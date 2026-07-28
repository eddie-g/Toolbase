<?php

namespace App\Jobs;

use App\Models\AiLogoPrice;
use App\Models\AiLogoRequest;
use App\Models\Admin;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\FalBalanceService;
use App\Services\RecraftPricing;
use App\Traits\ResolvesExternalDns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateLogoJob implements ShouldQueue
{
    use Queueable, ResolvesExternalDns;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * Timeout in seconds (logo generation + post-processing can take a while).
     */
    public int $timeout = 300;

    /**
     * Ensure hard timeouts are marked failed instead of lingering.
     */
    public bool $failOnTimeout = true;

    /**
     * All the parameters needed to generate and process the logo.
     */
    public array $params;
    public int $userId;
    public ?int $adminId = null;
    public int $logoRequestId;
    public int $priceLogId;
    private ?int $storageUserId = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $userId,
        int $logoRequestId,
        int $priceLogId,
        array $params,
        ?int $adminId = null,
    ) {
        $this->userId = $userId;
        $this->adminId = $adminId;
        $this->logoRequestId = $logoRequestId;
        $this->priceLogId = $priceLogId;
        $this->params = $params;

        // Run on the logo-generation queue
        $this->onQueue('logo-generation');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logoRequest = AiLogoRequest::find($this->logoRequestId);
        $priceLog = AiLogoPrice::find($this->priceLogId);
        $admin = $this->adminId ? Admin::find($this->adminId) : null;
        $user = $admin ?? User::find($this->userId);

        if (!$logoRequest || !$priceLog || !$user) {
            Log::error('[GenerateLogoJob] Missing records', [
                'logo_request_id' => $this->logoRequestId,
                'price_log_id' => $this->priceLogId,
                'user_id' => $this->userId,
                'admin_id' => $this->adminId,
            ]);
            return;
        }

        $this->storageUserId = $this->resolveStorageUserId($logoRequest);

        // Mark as processing
        $logoRequest->update(['status' => 'processing']);

        $startTime = microtime(true);

        // Extract params
        $imageModel = $this->params['image_model'];
        $outputFormat = $this->params['output_format'];
        $isPro = $this->params['is_pro'];
        $proSize = $this->params['pro_size'];
        $imageCount = $this->params['image_count'];
        $prompt = $this->params['prompt'];
        $bgColor = $this->params['bg_color'];
        $domain = $this->params['domain'];
        $style = $this->params['style'];
        $iconOnly = $this->params['icon_only'];
        $colorPalette = $this->params['color_palette'];
        $recraftSubstyle = $this->params['recraft_substyle'];
        $totalCount = $this->params['total_count'];
        $costPerImage = $this->params['cost_per_image'];
        $modelName = $this->params['model_name'];
        $logoShape = $this->params['logo_shape'] ?? 'none';
        $logoDetail = $this->params['logo_detail'] ?? 'max';
        $imageSize = $this->params['image_size'] ?? '1:1';

        if ($imageModel === 'recraft' && $outputFormat === 'raster' && $isPro && $imageSize !== '1:1') {
            $logoRequest->update([
                'status' => 'failed',
                'error_message' => 'Ray PRO currently supports Square image size only. Landscape and Portrait are not available for this model.',
            ]);
            $priceLog->update(['status' => 'failed']);
            Log::warning('[GenerateLogoJob] Unsupported Recraft PRO image size rejected - NO CHARGE', [
                'logo_request_id' => $this->logoRequestId,
                'image_size' => $imageSize,
            ]);
            return;
        }

        try {
            if ($imageModel === 'recraft') {
                $result = $this->generateRecraft($prompt, $imageCount, $outputFormat, $isPro, $bgColor, $iconOnly, $colorPalette, $recraftSubstyle, $imageSize);
            } elseif ($imageModel === 'dalle') {
                $result = $this->generateDalle($prompt, $imageCount, $isPro, $outputFormat, $imageSize);
            } elseif ($imageModel === 'flux' && $outputFormat === 'raster') {
                $result = $this->generateNanoBanana2($prompt, $imageCount, $imageSize);
            } elseif ($isPro) {
                $result = $this->generateFluxPro($prompt, $imageCount, $proSize);
            } else {
                $result = $this->generateFluxSchnell($prompt, $imageCount);
            }

            // Check for failure
            if (isset($result['error'])) {
                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);
                $logoRequest->update([
                    'status' => 'failed',
                    'error_message' => $this->friendlyErrorMessage($result['error']),
                    'fal_status_code' => $result['status_code'] ?? null,
                    'response_time_ms' => $elapsedMs,
                ]);
                $priceLog->update(['status' => 'failed', 'response_time_ms' => $elapsedMs]);
                return;
            }

            $images = $result['images'];
            $seed = $result['seed'] ?? null;
            $responseStatus = count($images) > 0 ? 200 : 500;

            if (count($images) === 0) {
                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);
                $logoRequest->update([
                    'status' => 'failed',
                    'error_message' => 'No images generated',
                    'response_time_ms' => $elapsedMs,
                ]);
                $priceLog->update(['status' => 'failed', 'response_time_ms' => $elapsedMs]);
                return;
            }

            if (count($images) < $imageCount) {
                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);
                $logoRequest->update([
                    'status' => 'failed',
                    'error_message' => 'Only ' . count($images) . " of {$imageCount} requested images were generated. Your account was not charged.",
                    'response_time_ms' => $elapsedMs,
                ]);
                $priceLog->update(['status' => 'failed', 'response_time_ms' => $elapsedMs]);
                Log::warning('[GenerateLogoJob] Partial image result rejected - NO CHARGE', [
                    'logo_request_id' => $this->logoRequestId,
                    'requested' => $imageCount,
                    'received' => count($images),
                    'model' => $modelName,
                ]);
                return;
            }

            if (count($images) > $imageCount) {
                $images = array_slice($images, 0, $imageCount);
            }

            // ── Post-processing (non-Recraft only) ──
            if ($imageModel !== 'recraft') {
                $images = $this->postProcess($images, $bgColor, $outputFormat, $logoShape);
            }

            if (count($images) < $imageCount) {
                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);
                $logoRequest->update([
                    'status' => 'failed',
                    'error_message' => 'Only ' . count($images) . " of {$imageCount} requested images were available after processing. Your account was not charged.",
                    'response_time_ms' => $elapsedMs,
                ]);
                $priceLog->update(['status' => 'failed', 'response_time_ms' => $elapsedMs]);
                Log::warning('[GenerateLogoJob] Partial processed image result rejected - NO CHARGE', [
                    'logo_request_id' => $this->logoRequestId,
                    'requested' => $imageCount,
                    'received' => count($images),
                    'model' => $modelName,
                ]);
                return;
            }

            // ── Persist images to local storage ──
            $storedImageUrls = [];
            $storedImagePaths = [];
            $imageUrls = [];
            $storageUserId = $this->storageUserId ?? $this->resolveStorageUserId($logoRequest);

            foreach ($images as $idx => &$img) {
                $imgUrl = is_array($img) ? ($img['svg_url'] ?? $img['url'] ?? '') : (string) $img;
                $imageUrls[] = $imgUrl;

                if (!$imgUrl || $imgUrl === '[base64-omitted]') {
                    continue;
                }

                $stored = $this->storeRemoteLogoImage(
                    imageUrl: $imgUrl,
                    requestId: $this->logoRequestId,
                    userId: $storageUserId,
                    domain: $domain,
                    index: $idx + 1,
                );

                if ($stored) {
                    $storedImagePaths[] = $stored['path'];
                    $storedImageUrls[] = $stored['url'];
                    if (is_array($img)) {
                        $img['stored_path'] = $stored['path'];
                        $img['stored_url'] = $stored['url'];
                    } else {
                        $img = [
                            'url' => $imgUrl,
                            'stored_path' => $stored['path'],
                            'stored_url' => $stored['url'],
                        ];
                    }
                }
            }
            unset($img);

            // Attach a provider-independent generation ID to every output. Keep the
            // provider's own ID separately because some APIs (such as Recraft's
            // Explore Similar endpoint) require that exact ID for later iteration.
            $imagesWithSeed = $this->identifyGeneratedImages($images, $seed);

            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            // ── Calculate actual cost and charge ──
            $actualImageCount = count($images);
            if ($imageModel === 'recraft') {
                $recraftSize = $this->recraftRequestSize($outputFormat, $isPro, $imageSize);
                $actualCost = RecraftPricing::estimateLogoCost(
                    imageCount: $actualImageCount,
                    size: $recraftSize,
                    isPro: $isPro,
                    type: $outputFormat,
                );
            } elseif ($imageModel === 'dalle') {
                $gptImageResolution = AiLogoPrice::gptImageResolutionForSize($imageSize);
                $actualCost = AiLogoPrice::estimateDalleCost(
                    imageCount: $actualImageCount,
                    resolution: $gptImageResolution,
                    quality: $isPro ? 'hd' : 'standard',
                    outputFormat: $outputFormat,
                    bgColor: $bgColor,
                );
            } else {
                $actualCost = AiLogoPrice::estimateCost(
                    imageCount: $actualImageCount,
                    isPro: $isPro,
                    proSize: $proSize,
                    style: $style,
                    bgColor: $bgColor,
                    outputFormat: $outputFormat,
                    imageModel: $imageModel,
                );
            }

            $totalCost = (float) $actualCost['estimated_cost_usd'];

            // Re-check balance before charging (someone else may have drained it)
            $user->refresh();
            if ($totalCost > 0 && (float) $user->credit_balance < $totalCost) {
                // Insufficient balance — mark failed, do NOT charge
                $logoRequest->update([
                    'status' => 'failed',
                    'error_message' => 'Insufficient balance at charge time',
                    'response_time_ms' => $elapsedMs,
                ]);
                $priceLog->update(['status' => 'failed', 'response_time_ms' => $elapsedMs]);
                Log::warning('[GenerateLogoJob] Balance drained before charge', [
                    'user_id' => $this->userId,
                    'needed' => $totalCost,
                    'balance' => $user->credit_balance,
                ]);
                return;
            }

            // ── Update records ──
            $logoRequest->update([
                'status' => 'completed',
                'fal_status_code' => $responseStatus,
                'seed_number' => is_numeric($seed) ? (int) $seed : null,
                'storage_type' => !empty($storedImagePaths) ? 'path' : 'url',
                'image_urls' => !empty($storedImageUrls) ? $storedImageUrls : $imageUrls,
                'response_time_ms' => $elapsedMs,
            ]);

            $priceLog->update([
                'status' => 'completed',
                'image_count' => $actualImageCount,
                'actual_cost_usd' => $actualCost['estimated_cost_usd'],
                'response_time_ms' => $elapsedMs,
            ]);

            // ── Debit user ──
            if ($totalCost > 0) {
                $breakdown = $actualCost['breakdown'] ?? [];
                if ($admin) {
                    $admin->debitBalance($totalCost);
                } else {
                    CreditTransaction::debit(
                        userId: $this->userId,
                        amount: $totalCost,
                        service: 'logo_generation',
                        modelName: $modelName,
                        description: $domain ? "{$actualImageCount} logo(s) for {$domain}" : "{$actualImageCount} icon-only logo(s)",
                        metadata: [
                            'domain' => $domain,
                            'style' => $style,
                            'image_count' => $actualImageCount,
                            'resolution' => $imageModel === 'recraft' ? $this->recraftRequestSize($outputFormat, $isPro, $imageSize) : ($imageModel === 'dalle' ? AiLogoPrice::gptImageResolutionForSize($imageSize) : ($isPro ? "{$proSize}x{$proSize}" : '512x512')),
                            'pro' => $isPro,
                            'icon_only' => $iconOnly,
                            'bg_color' => $bgColor,
                            'image_model' => $imageModel,
                            'breakdown' => $breakdown,
                            'queued' => true,
                        ],
                    );
                }

                // ── Track fal.ai spend in our own balance ledger ──
                if (!in_array($imageModel, ['recraft', 'dalle'])) {
                    FalBalanceService::debit(
                        amount: $totalCost,
                        model: $modelName,
                        logoRequestId: $this->logoRequestId,
                    );
                }


            }

            // Store result data in the logo request for the polling endpoint
            $logoRequest->update([
                'result_data' => json_encode([
                    'images' => $imagesWithSeed,
                    'prompt' => $prompt,
                    'seed' => $seed,
                    'bg_color' => $bgColor,
                    'image_model' => $imageModel,
                    'style' => $style,
                    'icon_only' => $iconOnly,
                    'logo_shape' => $logoShape,
                    'logo_detail' => $logoDetail,
                    'cost' => [
                        'image_count' => $actualImageCount,
                        'cost_per_image' => $costPerImage,
                        'total_cost' => $actualCost['estimated_cost_usd'],
                    ],
                ]),
            ]);

            Log::info('[GenerateLogoJob] Completed', [
                'logo_request_id' => $this->logoRequestId,
                'user_id' => $this->userId,
                'model' => $modelName,
                'images' => $actualImageCount,
                'cost' => $totalCost,
                'elapsed_ms' => $elapsedMs,
            ]);

        } catch (\Exception $e) {
            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            $isConnection = $e instanceof \Illuminate\Http\Client\ConnectionException;
            Log::error('[GenerateLogoJob] ' . ($isConnection ? 'Connection error' : 'Error') . ' - NO CHARGE', [
                'message' => $e->getMessage(),
                'user_id' => $this->userId,
                'logo_request_id' => $this->logoRequestId,
                'image_model' => $imageModel,
                'elapsed_ms' => $elapsedMs,
            ]);

            try {
                $logoRequest->update([
                    'status' => 'error',
                    'error_message' => $isConnection
                        ? 'Unable to connect to the AI service. Your account was not charged.'
                        : $this->friendlyErrorMessage($e->getMessage()),
                    'response_time_ms' => $elapsedMs,
                ]);
                $priceLog->update(['status' => 'error', 'response_time_ms' => $elapsedMs]);
            } catch (\Throwable $_) {}

            // Don't rethrow — this prevents Horizon from retrying and double-charging
        }
    }

    public function failed(\Throwable $exception): void
    {
        $logoRequest = AiLogoRequest::find($this->logoRequestId);
        $priceLog = AiLogoPrice::find($this->priceLogId);

        if ($priceLog && $priceLog->status !== 'completed') {
            $priceLog->update([
                'status' => 'failed',
                'actual_cost_usd' => 0,
            ]);
        }

        if ($logoRequest && $logoRequest->status !== 'completed') {
            $isTimeout = $exception instanceof \Illuminate\Queue\TimeoutExceededException;
            $logoRequest->update([
                'status' => 'failed',
                'error_message' => $isTimeout
                    ? 'Logo generation timed out before completion. Your account was not charged.'
                    : $this->friendlyErrorMessage($exception->getMessage()),
            ]);
        }

        Log::warning('[GenerateLogoJob] Failed - NO CHARGE', [
            'logo_request_id' => $this->logoRequestId,
            'price_log_id' => $this->priceLogId,
            'user_id' => $this->userId,
            'admin_id' => $this->adminId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Model-specific generation methods
    // ──────────────────────────────────────────────────────────────

    private function identifyGeneratedImages(array $images, mixed $seed): array
    {
        return array_map(function ($image) use ($seed) {
            if (!is_array($image)) {
                $image = ['url' => (string) $image];
            }

            $image['generation_id'] = $image['generation_id'] ?? (string) Str::uuid();
            $image['provider_image_id'] = $image['provider_image_id'] ?? $image['image_id'] ?? null;
            $image['seed'] = $seed;

            return $image;
        }, $images);
    }

    private function recraftRequestSize(string $outputFormat, bool $isPro, string $imageSize): string
    {
        if ($outputFormat === 'vector') {
            return '1:1';
        }

        return match (true) {
            $isPro && $imageSize === '16:9' => '2688x1536',
            $isPro && $imageSize === '9:16' => '1536x2688',
            $isPro => '2048x2048',
            $imageSize === '16:9' => '1344x768',
            $imageSize === '9:16' => '768x1344',
            default => '1024x1024',
        };
    }

    /**
     * Generate images via Recraft (v4/v4.1 Pro or v2 regular, raster or vector).
     *
     * V4/V4.1: unified endpoint /v1/images/generations. Style and substyle are not supported.
     * V2: endpoint per type (/raster or /vector), with style/substyle support.
     */
    private function generateRecraft(string $prompt, int $imageCount, string $outputFormat, bool $isPro, string $bgColor, bool $iconOnly, ?array $colorPalette, ?string $recraftSubstyle, string $imageSize = '1:1'): array
    {
        $recraftBaseUrl = config('services.recraft.base_url', 'https://external.api.recraft.ai');
        $recraftKey = config('services.recraft.key');
        $isVector = $outputFormat === 'vector';

        $recraftSize = $this->recraftRequestSize($outputFormat, $isPro, $imageSize);

        // Use V4 for vector/raster Pro and V2 for regular raster.
        if ($isVector) {
            // Recraft V4 standard vector without the expensive Pro Vector tier.
            $recraftEndpoint = '/v1/images/generations/vector';

            $recraftBody = [
                'prompt'          => $prompt,
                'model'           => 'recraftv4_vector',
                'n'               => $imageCount,
                'size'            => $recraftSize,
                'response_format' => 'url',
            ];
        } elseif ($isPro) {
            // ── Recraft V4 Raster ─────────────────────────────────────────
            // Unified endpoint. No `style` or `substyle` parameters supported.
            $recraftEndpoint = '/v1/images/generations';

            $recraftBody = [
                'prompt'          => $prompt,
                'model'           => 'recraftv4',
                'n'               => $imageCount,
                'size'            => $recraftSize,
                'response_format' => 'url',
            ];
        } else {
            // ── Recraft V2 ────────────────────────────────────────────────
            // Separate endpoints per type; supports style/substyle.
            $recraftEndpoint = '/v1/images/generations/raster';
            $recraftStyle    = 'digital_illustration';

            $recraftBody = [
                'prompt'          => $prompt,
                'style'           => $recraftStyle,
                'model'           => 'recraftv2',
                'n'               => $imageCount,
                'size'            => $recraftSize,
                'response_format' => 'url',
            ];

            if (!empty($recraftSubstyle)) {
                $recraftBody['substyle'] = $recraftSubstyle;
            }
        }

        // Color palette — supported by all models
        if (!empty($colorPalette) && is_array($colorPalette)) {
            $recraftColors = [];
            foreach ($colorPalette as $hex) {
                $hex = ltrim($hex, '#');
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                $recraftColors[] = ['rgb' => [$r, $g, $b]];
            }
            $recraftBody['controls'] = ['colors' => $recraftColors];
        }

        // no_text control is V3 only — not applicable here (v2 or v4)

        // Background color — supported by all models
        if ($bgColor !== 'white') {
            $bgHex = match($bgColor) {
                'black' => '#000000',
                'transparent' => null,
                default => str_starts_with($bgColor, '#') ? $bgColor : null,
            };
            if ($bgHex) {
                $hex = ltrim($bgHex, '#');
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                $recraftBody['controls'] = array_merge(
                    $recraftBody['controls'] ?? [],
                    ['background_color' => ['rgb' => [$r, $g, $b]]]
                );
            }
        }

        $recraftUrl = $recraftBaseUrl . $recraftEndpoint;
        
        Log::info('Recraft API request', [
            'url' => $recraftUrl,
            'body' => $recraftBody,
            'is_vector' => $isVector,
            'is_pro' => $isPro,
        ]);
        
        $recraftResponse = $this->httpWithResolvedDns($recraftUrl, [
            'Authorization' => 'Bearer ' . $recraftKey,
            'Content-Type' => 'application/json',
        ])->retry(3, 2000, function (\Exception $e) {
            return $e instanceof \Illuminate\Http\Client\ConnectionException
                || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
        })->timeout(120)->post($recraftUrl, $recraftBody);

        if (!$recraftResponse->successful()) {
            $recraftError = $recraftResponse->json('error.message')
                ?? $recraftResponse->json('detail')
                ?? $recraftResponse->json('message')
                ?? 'Unknown Recraft error (HTTP ' . $recraftResponse->status() . ')';

            // Also check the raw body for error codes not surfaced via JSON fields
            $recraftBody = $recraftResponse->body();
            if (str_contains($recraftBody, 'not_enough_credits')) {
                $recraftError = 'Model currently unavailable, please try a different model.';
            } else {
                $recraftError = $this->friendlyErrorMessage($recraftError);
            }

            Log::error('Recraft ' . $outputFormat . ' generation failed', [
                'status' => $recraftResponse->status(),
                'body' => substr($recraftBody, 0, 500),
            ]);

            return ['error' => $recraftError, 'status_code' => $recraftResponse->status()];
        }

        // Check if response is JSON before parsing
        $responseBody = $recraftResponse->body();
        if (str_starts_with(trim($responseBody), '<') || str_starts_with(trim($responseBody), '<!DOCTYPE')) {
            Log::error('Recraft returned HTML instead of JSON', [
                'status' => $recraftResponse->status(),
                'body' => substr($responseBody, 0, 500),
                'url' => $recraftUrl,
            ]);
            return ['error' => 'API returned invalid response format', 'status_code' => $recraftResponse->status()];
        }

        try {
            $recraftData = $recraftResponse->json();
        } catch (\Exception $e) {
            Log::error('Failed to parse Recraft JSON response', [
                'error' => $e->getMessage(),
                'body' => substr($responseBody, 0, 500),
            ]);
            return ['error' => 'API returned invalid JSON', 'status_code' => 500];
        }

        $allImages = [];

        foreach ($recraftData['data'] ?? [] as $recraftImg) {
            $imgUrl = $recraftImg['url'] ?? null;
            if ($imgUrl) {
                $imgEntry = [
                    'url' => $imgUrl,
                    'image_id' => $recraftImg['image_id'] ?? null,
                ];
                // Vector endpoint returns SVG — strip BG programmatically
                if ($isVector) {
                    try {
                        $svgResponse = $this->httpWithResolvedDns($imgUrl, [])->timeout(15)->get($imgUrl);
                        $svgContent = $svgResponse->body();
                        if ($svgContent && str_contains($svgContent, '<svg')) {
                            $cleanedSvg = $this->removeSvgBackground($svgContent);
                            if ($cleanedSvg) {
                                $imgEntry['url'] = 'data:image/svg+xml;base64,' . base64_encode($cleanedSvg);
                                $imgEntry['svg_url'] = $imgEntry['url'];
                                $imgEntry['transparent'] = true;
                            } else {
                                $imgEntry['svg_url'] = $imgUrl;
                            }
                        } else {
                            $imgEntry['svg_url'] = $imgUrl;
                        }
                    } catch (\Exception $e) {
                        Log::warning('SVG background strip failed', ['error' => $e->getMessage()]);
                        $imgEntry['svg_url'] = $imgUrl;
                    }
                }
                $allImages[] = $imgEntry;
            }
        }

        return ['images' => $allImages, 'seed' => null];
    }

    /**
     * Generate images via GPT Image 1.5.
     * Routes to the new gpt-image-1.5 model. DALL-E 3 is preserved as generateDalle3() below.
     */
    private function generateDalle(string $prompt, int $imageCount, bool $isPro, string $outputFormat = 'raster', string $imageSize = '1:1'): array
    {
        return $this->generateGptImage15($prompt, $imageCount, $isPro, $outputFormat, $imageSize);
    }

    /**
     * Generate images via GPT Image 1.5 (OpenAI /images/generations).
     *
     * Quality mapping: standard → medium, hd → high.
     * Response may contain 'url' or 'b64_json'; both are handled.
     * 
     * If outputFormat is 'vector', will vectorize PNG to SVG after generation.
     */
    private function generateGptImage15(string $prompt, int $imageCount, bool $isPro, string $outputFormat = 'raster', string $imageSize = '1:1'): array
    {
        $quality = $isPro ? 'high' : 'medium';
        $allImages = [];

        // Map aspect ratio to a GPT Image 1.5 supported size.
        $gptSize = match ($imageSize) {
            '16:9'  => '1536x1024',
            '9:16'  => '1024x1536',
            default => '1024x1024',
        };

        for ($i = 0; $i < $imageCount; $i++) {
            $apiUrl = config('services.openai.base_url') . '/images/generations';
            $response = $this->httpWithResolvedDns($apiUrl, [
                'Authorization' => 'Bearer ' . config('services.openai.api_key'),
                'Content-Type' => 'application/json',
            ])->retry(3, 2000, function (\Exception $e) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
            })->timeout(120)->post($apiUrl, [
                'model'   => 'gpt-image-1.5',
                'prompt'  => $prompt,
                'n'       => 1,
                'size'    => $gptSize,
                'quality' => $quality,
            ]);

            if ($response->successful()) {
                // Check if response is JSON before parsing
                $responseBody = $response->body();
                if (str_starts_with(trim($responseBody), '<') || str_starts_with(trim($responseBody), '<!DOCTYPE')) {
                    Log::error('GPT Image 1.5 returned HTML instead of JSON', [
                        'body' => substr($responseBody, 0, 500),
                    ]);
                    return ['error' => 'API returned invalid response format', 'status_code' => 500];
                }
                
                try {
                    $data = $response->json();
                } catch (\Exception $jsonEx) {
                    Log::error('Failed to parse GPT Image 1.5 JSON response', [
                        'error' => $jsonEx->getMessage(),
                        'body' => substr($responseBody, 0, 500),
                    ]);
                    return ['error' => 'API returned invalid JSON', 'status_code' => 500];
                }
                
                foreach ($data['data'] ?? [] as $img) {
                    if (!empty($img['url'])) {
                        $allImages[] = [
                            'url'            => $img['url'],
                            'revised_prompt' => $img['revised_prompt'] ?? null,
                        ];
                    } elseif (!empty($img['b64_json'])) {
                        // Store base64 image directly to local disk
                        $stored = $this->storeBase64LogoImage($img['b64_json'], $i + 1);
                        if ($stored) {
                            $allImages[] = [
                                'url'            => $stored['url'],
                                'stored_path'    => $stored['path'],
                                'stored_url'     => $stored['url'],
                                'revised_prompt' => $img['revised_prompt'] ?? null,
                            ];
                        }
                    }
                }
            } else {
                $responseBody = $response->body();
                $errJson = null;
                
                // Try to parse error response as JSON
                if (!str_starts_with(trim($responseBody), '<')) {
                    try {
                        $errJson = $response->json();
                    } catch (\Exception $e) {
                        // Not valid JSON, continue with null
                    }
                }
                
                $errMsg  = $errJson['error']['message'] ?? '';
                $errType = $errJson['error']['type'] ?? '';

                if (str_contains($errMsg, 'content filters') || str_contains($errType, 'content_policy')) {
                    return ['error' => 'Your prompt was flagged by the AI safety filter. Please rephrase your description.', 'status_code' => 422];
                }

                if (str_contains($errMsg, 'Billing hard limit') || str_contains($errMsg, 'billing')) {
                    return ['error' => 'GPT Image 1.5 is temporarily unavailable. Please use another model.', 'status_code' => 503];
                }

                Log::warning('GPT Image 1.5 image ' . ($i + 1) . ' failed', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
            }
        }

        // Vectorize if requested (for DALL-E/Cosmo vector mode)
        if ($outputFormat === 'vector' && !empty($allImages)) {
            foreach ($allImages as $i => &$img) {
                $rasterUrl = $img['url'] ?? null;
                if (!$rasterUrl) continue;

                try {
                    $imageData = null;
                    
                    // If URL is a local storage path, read the file and send as base64
                    if (str_starts_with($rasterUrl, '/storage/')) {
                        $relativePath = str_replace('/storage/', '', $rasterUrl);
                        if (Storage::disk('public')->exists($relativePath)) {
                            $imageData = Storage::disk('public')->get($relativePath);
                            Log::info('Read local file for vectorization', [
                                'path' => $relativePath,
                                'size' => strlen($imageData),
                            ]);
                        } else {
                            Log::warning('Local file not found for vectorization', [
                                'path' => $relativePath,
                            ]);
                            continue;
                        }
                    } else {
                        // Download remote URL
                        try {
                            $response = $this->httpWithResolvedDns($rasterUrl, [])->timeout(45)->get($rasterUrl);
                            if ($response->successful()) {
                                $imageData = $response->body();
                            } else {
                                Log::warning('Failed to download raster image for vectorization', [
                                    'url' => $rasterUrl,
                                    'status' => $response->status(),
                                ]);
                                continue;
                            }
                        } catch (\Exception $e) {
                            Log::warning('Exception downloading raster image: ' . $e->getMessage());
                            continue;
                        }
                    }

                    if (!$imageData) continue;

                    // Convert to base64 and send to vectorization API
                    $base64Image = base64_encode($imageData);
                    $vectorizeUrl = 'https://fal.run/fal-ai/recraft/vectorize';
                    $svgResponse = $this->httpWithResolvedDns($vectorizeUrl, [
                        'Authorization' => 'Key ' . config('services.fal.key'),
                        'Content-Type' => 'application/json',
                    ])->retry(3, 2000, function (\Exception $e) {
                        return $e instanceof \Illuminate\Http\Client\ConnectionException
                            || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
                    })->timeout(120)->post($vectorizeUrl, [
                        'image' => 'data:image/png;base64,' . $base64Image,
                    ]);

                    if ($svgResponse->successful()) {
                        $responseBody = $svgResponse->body();
                        if (!str_starts_with(trim($responseBody), '<') && !str_starts_with(trim($responseBody), '<!DOCTYPE')) {
                            try {
                                $svgData = $svgResponse->json();
                                $svgUrl = $svgData['image']['url'] ?? ($svgData['images'][0]['url'] ?? null);
                                if ($svgUrl) {
                                    $img['svg_url'] = $svgUrl;
                                    // Also update the main URL to point to SVG for vector output
                                    $img['url'] = $svgUrl;
                                    Log::info('Successfully vectorized DALL-E/Cosmo image ' . $i, [
                                        'svg_url' => $svgUrl,
                                    ]);
                                }
                            } catch (\Exception $jsonEx) {
                                Log::warning('Failed to parse vectorization JSON for DALL-E image ' . $i, [
                                    'error' => $jsonEx->getMessage(),
                                    'body' => substr($responseBody, 0, 500),
                                ]);
                            }
                        }
                    } else {
                        Log::warning('SVG vectorization failed for DALL-E image ' . $i, [
                            'status' => $svgResponse->status(),
                            'body' => substr($svgResponse->body(), 0, 500),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Exception during DALL-E vectorization for image ' . $i . ': ' . $e->getMessage());
                }
            }
        }

        return ['images' => $allImages, 'seed' => null];
    }

    /**
     * Generate images via DALL-E 3 (backup — kept for rollback purposes).
     *
     * @deprecated Use generateGptImage15() via generateDalle() instead.
     */
    private function generateDalle3(string $prompt, int $imageCount, bool $isPro): array
    {
        $dalleQuality = $isPro ? 'hd' : 'standard';
        $allImages = [];

        for ($i = 0; $i < $imageCount; $i++) {
            $dalleUrl = config('services.openai.base_url') . '/images/generations';
            $dalleResponse = $this->httpWithResolvedDns($dalleUrl, [
                'Authorization' => 'Bearer ' . config('services.openai.api_key'),
                'Content-Type' => 'application/json',
            ])->retry(3, 2000, function (\Exception $e) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
            })->timeout(120)->post($dalleUrl, [
                'model' => 'dall-e-3',
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1024x1024',
                'quality' => $dalleQuality,
                'response_format' => 'url',
            ]);

            if ($dalleResponse->successful()) {
                $dalleData = $dalleResponse->json();
                foreach ($dalleData['data'] ?? [] as $dImg) {
                    $allImages[] = ['url' => $dImg['url'], 'revised_prompt' => $dImg['revised_prompt'] ?? null];
                }
            } else {
                $errJson = $dalleResponse->json();
                $errMsg = $errJson['error']['message'] ?? '';
                $errType = $errJson['error']['type'] ?? '';

                // Content filter — immediate failure
                if (str_contains($errMsg, 'content filters') || str_contains($errType, 'content_policy')) {
                    return ['error' => 'Your prompt was flagged by the AI safety filter. Please rephrase your description.', 'status_code' => 422];
                }

                // Billing limit
                if (str_contains($errMsg, 'Billing hard limit') || str_contains($errMsg, 'billing')) {
                    return ['error' => 'DALL-E 3 is temporarily unavailable. Please use another model.', 'status_code' => 503];
                }

                Log::warning('DALL-E image ' . ($i + 1) . ' failed', [
                    'status' => $dalleResponse->status(),
                    'body' => substr($dalleResponse->body(), 0, 500),
                ]);
            }
        }

        return ['images' => $allImages, 'seed' => null];
    }

    /**
     * Generate images via FLUX.2 [flex] (fal-ai/flux-2-flex).
     * Upgraded from flux-pro/v1.1 ($0.04/MP) — better typography, adjustable steps, $0.05/MP.
     */
    private function generateFluxPro(string $prompt, int $imageCount, int $proSize): array
    {
        $endpoint = 'https://fal.run/fal-ai/flux-2-flex';
        $allImages = [];
        $fluxImageSize = ['width' => $proSize, 'height' => $proSize];

        for ($i = 0; $i < $imageCount; $i++) {
            $proResponse = $this->httpWithResolvedDns($endpoint, [
                'Authorization' => 'Key ' . config('services.fal.key'),
                'Content-Type' => 'application/json',
            ])->retry(3, 3000, function (\Exception $e) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
            })->timeout(120)->post($endpoint, [
                'prompt' => $prompt,
                'image_size' => $fluxImageSize,
                'num_images' => 1,
                'num_inference_steps' => 28,
                'guidance_scale' => 3.5,
                'safety_tolerance' => 5,
                'enable_prompt_expansion' => false, // Keep prompts precise for logo generation
                'sync_mode' => true,
            ]);

            if ($proResponse->successful()) {
                // Check if response is JSON before parsing
                $responseBody = $proResponse->body();
                if (str_starts_with(trim($responseBody), '<') || str_starts_with(trim($responseBody), '<!DOCTYPE')) {
                    Log::error('Flux Pro returned HTML instead of JSON', [
                        'body' => substr($responseBody, 0, 500),
                    ]);
                    return ['error' => 'API returned invalid response format', 'status_code' => 500];
                }
                
                try {
                    $proData = $proResponse->json();
                    foreach ($proData['images'] ?? [] as $pImg) {
                        $allImages[] = $pImg;
                    }
                } catch (\Exception $jsonEx) {
                    Log::error('Failed to parse Flux Pro JSON response', [
                        'error' => $jsonEx->getMessage(),
                        'body' => substr($responseBody, 0, 500),
                    ]);
                    return ['error' => 'API returned invalid JSON', 'status_code' => 500];
                }
            } else {
                $proBody = $proResponse->body();
                if ($this->friendlyErrorMessage($proBody) === 'Model currently unavailable, please try a different model.') {
                    return ['error' => 'Model currently unavailable, please try a different model.', 'status_code' => 400];
                }
                Log::warning('PRO image ' . ($i + 1) . ' failed', [
                    'status' => $proResponse->status(),
                    'body' => substr($proBody, 0, 500),
                ]);
            }
        }

        return ['images' => $allImages, 'seed' => null];
    }

    /**
     * Generate images via Flux Schnell.
     */
    private function generateFluxSchnell(string $prompt, int $imageCount): array
    {
        $endpoint = 'https://fal.run/fal-ai/flux/schnell';
        $response = $this->httpWithResolvedDns($endpoint, [
            'Authorization' => 'Key ' . config('services.fal.key'),
            'Content-Type' => 'application/json',
        ])->retry(3, 3000, function (\Exception $e) {
            return $e instanceof \Illuminate\Http\Client\ConnectionException
                || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
        })->timeout(120)->post($endpoint, [
            'prompt' => $prompt,
            'image_size' => [
                'width' => 512,
                'height' => 512,
            ],
            'num_images' => $imageCount,
            'num_inference_steps' => 8,
            'guidance_scale' => 3.5,
            'sync_mode' => true,
        ]);

        if (!$response->successful()) {
            $falBody = $response->body();
            
            // Try to extract error message, handling both JSON and non-JSON responses
            $falError = 'Unknown error';
            if (str_contains($falBody, 'not_enough_credits')) {
                $falError = 'not_enough_credits';
            } elseif (!str_starts_with(trim($falBody), '<')) {
                try {
                    $jsonData = $response->json();
                    $falError = $jsonData['detail'] ?? $jsonData['message'] ?? 'Unknown error';
                } catch (\Exception $e) {
                    $falError = 'Unknown error';
                }
            }
            
            $falError = $this->friendlyErrorMessage($falError);
            Log::error('Fal.ai logo generation failed', [
                'status' => $response->status(),
                'body' => substr($falBody, 0, 500),
            ]);
            return ['error' => $falError, 'status_code' => $response->status()];
        }

        // Check if response is JSON before parsing
        $responseBody = $response->body();
        if (str_starts_with(trim($responseBody), '<') || str_starts_with(trim($responseBody), '<!DOCTYPE')) {
            Log::error('Flux Schnell returned HTML instead of JSON', [
                'body' => substr($responseBody, 0, 500),
            ]);
            return ['error' => 'API returned invalid response format', 'status_code' => 500];
        }
        
        try {
            $data = $response->json();
            return ['images' => $data['images'] ?? [], 'seed' => $data['seed'] ?? null];
        } catch (\Exception $jsonEx) {
            Log::error('Failed to parse Flux Schnell JSON response', [
                'error' => $jsonEx->getMessage(),
                'body' => substr($responseBody, 0, 500),
            ]);
            return ['error' => 'API returned invalid JSON', 'status_code' => 500];
        }
    }

    /**
     * Generate images via Nano Banana 2.
     */
    private function generateNanoBanana2(string $prompt, int $imageCount, string $imageSize = '1:1'): array
    {
        $aspectRatio = in_array($imageSize, ['1:1', '16:9', '9:16'], true) ? $imageSize : '1:1';
        $endpoint = 'https://fal.run/fal-ai/nano-banana-2';
        $response = $this->httpWithResolvedDns($endpoint, [
            'Authorization' => 'Key ' . config('services.fal.key'),
            'Content-Type' => 'application/json',
        ])->retry(3, 3000, function (\Exception $e) {
            return $e instanceof \Illuminate\Http\Client\ConnectionException
                || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
        })->timeout(120)->post($endpoint, [
            'prompt' => $prompt,
            'num_images' => $imageCount,
            'resolution' => '1K',
            'aspect_ratio' => $aspectRatio,
            'output_format' => 'png',
            'sync_mode' => true,
        ]);

        if (!$response->successful()) {
            $falBody = $response->body();
            
            // Try to extract error message, handling both JSON and non-JSON responses
            $falError = 'Unknown error';
            if (str_contains($falBody, 'not_enough_credits')) {
                $falError = 'not_enough_credits';
            } elseif (!str_starts_with(trim($falBody), '<')) {
                try {
                    $jsonData = $response->json();
                    $falError = $jsonData['detail'] ?? $jsonData['message'] ?? 'Unknown error';
                } catch (\Exception $e) {
                    $falError = 'Unknown error';
                }
            }
            
            $falError = $this->friendlyErrorMessage($falError);
            Log::error('Fal.ai nano-banana-2 generation failed', [
                'status' => $response->status(),
                'body' => substr($falBody, 0, 500),
            ]);
            return ['error' => $falError, 'status_code' => $response->status()];
        }

        // Check if response is JSON before parsing
        $responseBody = $response->body();
        if (str_starts_with(trim($responseBody), '<') || str_starts_with(trim($responseBody), '<!DOCTYPE')) {
            Log::error('Nano Banana 2 returned HTML instead of JSON', [
                'body' => substr($responseBody, 0, 500),
            ]);
            return ['error' => 'API returned invalid response format', 'status_code' => 500];
        }
        
        try {
            $data = $response->json();
            return ['images' => $data['images'] ?? [], 'seed' => $data['seed'] ?? null];
        } catch (\Exception $jsonEx) {
            Log::error('Failed to parse Nano Banana 2 JSON response', [
                'error' => $jsonEx->getMessage(),
                'body' => substr($responseBody, 0, 500),
            ]);
            return ['error' => 'API returned invalid JSON', 'status_code' => 500];
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Post-processing helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Apply background removal and/or vectorization to generated images.
     */

    /**
     * Convert known API error codes/messages into user-friendly strings.
     */
    private function friendlyErrorMessage(string $raw): string
    {
        $normalized = strtolower($raw);
        if (
            str_contains($normalized, 'not_enough_credits') ||
            str_contains($normalized, 'user is locked') ||
            str_contains($normalized, 'exhausted balance')
        ) {
            return 'Model currently unavailable, please try a different model.';
        }

        if (
            str_contains($normalized, 'http request returned status code') ||
            str_contains($normalized, 'invalid_request_parameter') ||
            str_contains($normalized, 'invalid response') ||
            str_contains($normalized, 'invalid json') ||
            str_contains($normalized, 'api returned') ||
            str_contains($normalized, 'api key') ||
            str_contains($normalized, 'recraft') ||
            str_contains($normalized, 'dall-e') ||
            str_contains($normalized, 'gpt-image') ||
            str_contains($normalized, 'fal-ai') ||
            str_contains($normalized, 'openai')
        ) {
            return 'Image generation failed. Please adjust your settings and try again.';
        }

        return 'Image generation failed. Please try again.';
    }

    private function postProcess(array $images, string $bgColor, string $outputFormat, ?string $logoShape = null): array
    {
        $needsBgRemoval = in_array($bgColor, ['none', 'transparent'], true) || str_starts_with($bgColor, '#');
        $shape = strtolower((string) ($logoShape ?: 'none'));

        if ($needsBgRemoval) {
            $falKey = config('services.fal.key');
            foreach ($images as $i => &$img) {
                $imgUrl = is_array($img) ? ($img['url'] ?? '') : (string) $img;
                if (!$imgUrl) continue;

                $backgroundRemovalInput = $imgUrl;
                if (str_starts_with($imgUrl, '/storage/')) {
                    $relativePath = str_replace('/storage/', '', $imgUrl);
                    if (Storage::disk('public')->exists($relativePath)) {
                        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
                        $mime = match ($extension) {
                            'jpg', 'jpeg' => 'image/jpeg',
                            'webp' => 'image/webp',
                            'gif' => 'image/gif',
                            default => 'image/png',
                        };
                        $backgroundRemovalInput = 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('public')->get($relativePath));
                    }
                }

                try {
                    $birefnetUrl = 'https://fal.run/fal-ai/birefnet';
                    $bgResponse = $this->httpWithResolvedDns($birefnetUrl, [
                        'Authorization' => 'Key ' . $falKey,
                        'Content-Type' => 'application/json',
                    ])->retry(3, 2000, function (\Exception $e) {
                        return $e instanceof \Illuminate\Http\Client\ConnectionException
                            || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
                    })->timeout(60)->post($birefnetUrl, [
                        'image_url' => $backgroundRemovalInput,
                        'model' => 'General Use (Light)',
                        'operating_resolution' => '1024x1024',
                        'output_format' => 'png',
                    ]);

                    if ($bgResponse->successful()) {
                        $transparentUrl = $bgResponse->json()['image']['url'] ?? null;
                        if ($transparentUrl) {
                            if (is_array($img)) {
                                $img['url'] = $transparentUrl;
                                $img['transparent'] = true;
                            } else {
                                $images[$i] = ['url' => $transparentUrl, 'transparent' => true];
                            }
                        }
                    } else {
                        Log::warning('Background removal failed for image ' . $i, ['status' => $bgResponse->status()]);
                    }
                } catch (\Exception $bgEx) {
                    Log::warning('Background removal exception for image ' . $i, ['error' => $bgEx->getMessage()]);
                }
            }
            unset($img);
        }

        if ($shape !== 'none' && $shape !== 'square' && $outputFormat === 'raster') {
            foreach ($images as $i => &$img) {
                $imgUrl = is_array($img) ? ($img['url'] ?? '') : (string) $img;
                if (!$imgUrl) {
                    continue;
                }

                $maskedDataUrl = $this->maskImageToShape($imgUrl, $shape);
                if (!$maskedDataUrl) {
                    continue;
                }

                if (is_array($img)) {
                    $img['url'] = $maskedDataUrl;
                    $img['shape'] = $shape;
                    $img['transparent'] = true;
                    unset($img['stored_path'], $img['stored_url']);
                } else {
                    $images[$i] = [
                        'url' => $maskedDataUrl,
                        'shape' => $shape,
                        'transparent' => true,
                    ];
                }
            }
            unset($img);
        }

        // Vectorize if requested
        if ($outputFormat === 'vector') {
            foreach ($images as $i => &$img) {
                $rasterUrl = $img['url'] ?? (is_string($img) ? $img : null);
                if (!$rasterUrl) continue;

                try {
                    $vectorizeUrl = 'https://fal.run/fal-ai/recraft/vectorize';
                    $svgResponse = $this->httpWithResolvedDns($vectorizeUrl, [
                        'Authorization' => 'Key ' . config('services.fal.key'),
                        'Content-Type' => 'application/json',
                    ])->retry(3, 2000, function (\Exception $e) {
                        return $e instanceof \Illuminate\Http\Client\ConnectionException
                            || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
                    })->timeout(120)->post($vectorizeUrl, [
                        'image_url' => $rasterUrl,
                    ]);

                    if ($svgResponse->successful()) {
                        // Check if response is JSON before parsing
                        $responseBody = $svgResponse->body();
                        if (str_starts_with(trim($responseBody), '<') || str_starts_with(trim($responseBody), '<!DOCTYPE')) {
                            Log::warning('Vectorization API returned HTML instead of JSON for image ' . $i, [
                                'body' => substr($responseBody, 0, 500),
                            ]);
                        } else {
                            try {
                                $svgData = $svgResponse->json();
                                $svgUrl = $svgData['image']['url'] ?? ($svgData['images'][0]['url'] ?? null);
                                if ($svgUrl) {
                                    $img['svg_url'] = $svgUrl;
                                }
                            } catch (\Exception $jsonEx) {
                                Log::warning('Failed to parse vectorization JSON for image ' . $i, [
                                    'error' => $jsonEx->getMessage(),
                                    'body' => substr($responseBody, 0, 500),
                                ]);
                            }
                        }
                    } else {
                        Log::warning('SVG vectorization failed for image ' . $i, [
                            'status' => $svgResponse->status(),
                            'body' => $svgResponse->body(),
                        ]);
                    }
                } catch (\Exception $svgEx) {
                    Log::warning('SVG vectorization exception for image ' . $i, ['error' => $svgEx->getMessage()]);
                }
            }
            unset($img);
        }

        return $images;
    }

    private function maskImageToShape(string $imageUrl, string $shape): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $imageBytes = $this->imageBytesForProcessing($imageUrl);
            if ($imageBytes === null || $imageBytes === '') {
                return null;
            }

            $source = @imagecreatefromstring($imageBytes);
            if (!$source) {
                return null;
            }

            $width = imagesx($source);
            $height = imagesy($source);
            if ($width <= 0 || $height <= 0) {
                imagedestroy($source);
                return null;
            }

            imagepalettetotruecolor($source);
            imagealphablending($source, false);
            imagesavealpha($source, true);

            $mask = imagecreatetruecolor($width, $height);
            imagefill($mask, 0, 0, imagecolorallocate($mask, 0, 0, 0));
            $white = imagecolorallocate($mask, 255, 255, 255);

            $padding = (int) round(min($width, $height) * 0.04);
            $left = $padding;
            $top = $padding;
            $right = $width - $padding - 1;
            $bottom = $height - $padding - 1;
            $centerX = $width / 2;
            $centerY = $height / 2;
            $radius = (min($width, $height) / 2) - $padding;

            match ($shape) {
                'circle' => imagefilledellipse($mask, (int) round($centerX), (int) round($centerY), (int) round($radius * 2), (int) round($radius * 2), $white),
                'triangle' => imagefilledpolygon($mask, [
                    (int) round($centerX), $top,
                    $right, $bottom,
                    $left, $bottom,
                ], 3, $white),
                'hexagon' => imagefilledpolygon($mask, $this->regularPolygonPoints($centerX, $centerY, $radius, 6, -90), 6, $white),
                'pentagon' => imagefilledpolygon($mask, $this->regularPolygonPoints($centerX, $centerY, $radius, 5, -90), 5, $white),
                default => imagefilledrectangle($mask, $left, $top, $right, $bottom, $white),
            };

            $transparent = imagecolorallocatealpha($source, 0, 0, 0, 127);
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    if ((imagecolorat($mask, $x, $y) & 0xFF) < 128) {
                        imagesetpixel($source, $x, $y, $transparent);
                    }
                }
            }

            ob_start();
            imagepng($source);
            $png = ob_get_clean();

            return is_string($png) && $png !== ''
                ? 'data:image/png;base64,' . base64_encode($png)
                : null;
        } catch (\Throwable $e) {
            Log::warning('[GenerateLogoJob] Shape mask failed', [
                'request_id' => $this->logoRequestId,
                'shape' => $shape,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array<int, int>
     */
    private function regularPolygonPoints(float $centerX, float $centerY, float $radius, int $sides, float $rotationDegrees): array
    {
        $points = [];
        for ($i = 0; $i < $sides; $i++) {
            $angle = deg2rad($rotationDegrees + (($i * 360) / $sides));
            $points[] = (int) round($centerX + ($radius * cos($angle)));
            $points[] = (int) round($centerY + ($radius * sin($angle)));
        }

        return $points;
    }

    private function imageBytesForProcessing(string $imageUrl): ?string
    {
        if (str_starts_with($imageUrl, 'data:image/')) {
            if (!preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,(.*)$/s', $imageUrl, $matches)) {
                return null;
            }

            $decoded = base64_decode($matches[1], true);
            return $decoded === false ? null : $decoded;
        }

        if (str_starts_with($imageUrl, '/storage/')) {
            $relativePath = str_replace('/storage/', '', $imageUrl);
            return Storage::disk('public')->exists($relativePath)
                ? Storage::disk('public')->get($relativePath)
                : null;
        }

        $response = $this->httpWithResolvedDns($imageUrl, [])->timeout(45)->get($imageUrl);
        return $response->successful() ? $response->body() : null;
    }

    private function resolveStorageUserId(?AiLogoRequest $logoRequest = null): int
    {
        $requestUserId = (int) ($logoRequest?->user_id ?? 0);
        if ($requestUserId > 0) {
            return $requestUserId;
        }
        if ($this->userId > 0) {
            return $this->userId;
        }
        if ((int) ($this->adminId ?? 0) > 0) {
            return (int) $this->adminId;
        }

        return 0;
    }

    /**
     * Decode a base64 image and store it locally (used for gpt-image-1.5 b64_json responses).
     */
    private function storeBase64LogoImage(string $b64Data, int $index): ?array
    {
        try {
            $imageData = base64_decode($b64Data, true);
            if ($imageData === false) {
                Log::warning('[GenerateLogoJob] Invalid base64 image data', [
                    'request_id' => $this->logoRequestId,
                    'index'      => $index,
                ]);
                return null;
            }

            $domain    = $this->params['domain'] ?? null;
            $safeDomain = $domain ? (Str::slug($domain) ?: 'logo') : 'logo';
            $filename  = sprintf('%s-%d-%02d.png', $safeDomain, $this->logoRequestId, $index);
            $storageUserId = $this->storageUserId ?? $this->resolveStorageUserId();
            $relativePath = sprintf('logos/%d/%d/%s', $storageUserId, $this->logoRequestId, $filename);

            Storage::disk('public')->put($relativePath, $imageData);

            return [
                'path' => $relativePath,
                'url'  => '/storage/' . $relativePath,
            ];
        } catch (\Throwable $e) {
            Log::warning('[GenerateLogoJob] Exception storing base64 logo image', [
                'request_id' => $this->logoRequestId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Download a remote image and store it locally.
     */
    private function storeRemoteLogoImage(int $requestId, int $userId, ?string $domain, string $imageUrl, int $index): ?array
    {
        try {
            if (str_starts_with($imageUrl, 'data:image/')) {
                return $this->storeDataLogoImage($requestId, $userId, $domain, $imageUrl, $index);
            }

            $response = $this->httpWithResolvedDns($imageUrl, [])->timeout(45)->get($imageUrl);
            if (!$response->successful()) {
                Log::warning('Failed to download generated logo image', [
                    'request_id' => $requestId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));
            $extension = 'png';
            if (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
                $extension = 'jpg';
            } elseif (str_contains($contentType, 'webp')) {
                $extension = 'webp';
            } elseif (str_contains($contentType, 'svg')) {
                $extension = 'svg';
            } elseif (str_contains($contentType, 'png')) {
                $extension = 'png';
            } else {
                $urlPath = strtolower((string) parse_url($imageUrl, PHP_URL_PATH));
                if (preg_match('/\.(png|jpe?g|webp|svg)$/', $urlPath, $m)) {
                    $extension = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                }
            }

            $safeDomain = $domain ? (Str::slug($domain) ?: 'logo') : 'logo';
            $filename = sprintf('%s-%d-%02d.%s', $safeDomain, $requestId, $index, $extension);
            $relativePath = sprintf('logos/%d/%d/%s', $userId, $requestId, $filename);

            $body = $response->body();
            if ($extension === 'svg') {
                $body = $this->normalizeStoredSvg($body) ?? $body;
            }

            Storage::disk('public')->put($relativePath, $body);

            return [
                'path' => $relativePath,
                'url' => '/storage/' . $relativePath,
            ];
        } catch (\Throwable $e) {
            Log::warning('Exception while storing generated logo image', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function storeDataLogoImage(int $requestId, int $userId, ?string $domain, string $dataUrl, int $index): ?array
    {
        if (!preg_match('/^data:(image\/[a-z0-9.+-]+)(?:;charset=[^;]+)?(;base64)?,(.*)$/is', $dataUrl, $matches)) {
            return null;
        }

        $mime = strtolower($matches[1]);
        $isBase64 = !empty($matches[2]);
        $payload = $matches[3];
        $body = $isBase64 ? base64_decode($payload, true) : rawurldecode($payload);
        if ($body === false || $body === '') {
            return null;
        }

        $extension = match ($mime) {
            'image/svg+xml' => 'svg',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        if ($extension === 'svg') {
            $body = $this->normalizeStoredSvg($body) ?? $body;
        }

        $safeDomain = $domain ? (Str::slug($domain) ?: 'logo') : 'logo';
        $filename = sprintf('%s-%d-%02d.%s', $safeDomain, $requestId, $index, $extension);
        $relativePath = sprintf('logos/%d/%d/%s', $userId, $requestId, $filename);

        Storage::disk('public')->put($relativePath, $body);

        return [
            'path' => $relativePath,
            'url' => '/storage/' . $relativePath,
        ];
    }

    private function normalizeStoredSvg(string $svgContent, int $maxIntrinsicSize = 512): ?string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($svgContent, LIBXML_NOERROR | LIBXML_NOWARNING);
            $svg = $dom->documentElement;
            if (!$svg || strtolower($svg->tagName) !== 'svg') {
                return null;
            }

            $viewBox = trim($svg->getAttribute('viewBox'));
            if ($viewBox === '') {
                $width = $this->parseSvgLength($svg->getAttribute('width'));
                $height = $this->parseSvgLength($svg->getAttribute('height'));
                if ($width > 0 && $height > 0) {
                    $svg->setAttribute('viewBox', '0 0 ' . $this->formatSvgNumber($width) . ' ' . $this->formatSvgNumber($height));
                    $viewBox = $svg->getAttribute('viewBox');
                }
            }

            $parts = preg_split('/[\s,]+/', $viewBox);
            if (is_array($parts) && count($parts) === 4) {
                $viewBoxWidth = (float) $parts[2];
                $viewBoxHeight = (float) $parts[3];
                if ($viewBoxWidth > 0 && $viewBoxHeight > 0) {
                    $scale = $maxIntrinsicSize / max($viewBoxWidth, $viewBoxHeight);
                    $intrinsicWidth = max(1, (int) round($viewBoxWidth * $scale));
                    $intrinsicHeight = max(1, (int) round($viewBoxHeight * $scale));
                    $svg->setAttribute('width', (string) $intrinsicWidth);
                    $svg->setAttribute('height', (string) $intrinsicHeight);
                    $svg->setAttribute('preserveAspectRatio', 'xMidYMid meet');
                }
            }

            return $dom->saveXML($dom->documentElement) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseSvgLength(string $value): float
    {
        return preg_match('/^-?\d+(?:\.\d+)?/', trim($value), $match) ? (float) $match[0] : 0.0;
    }

    private function formatSvgNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }

    /**
     * Remove the background rect/path from SVG content.
     */
    private function removeSvgBackground(string $svgContent): ?string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($svgContent, LIBXML_NOERROR | LIBXML_NOWARNING);

            $svg = $dom->documentElement;
            if (!$svg) return null;

            // Get the viewBox dimensions
            $viewBox = $svg->getAttribute('viewBox');
            $vbParts = preg_split('/[\s,]+/', trim($viewBox));
            $vbWidth = (float) ($vbParts[2] ?? 0);
            $vbHeight = (float) ($vbParts[3] ?? 0);

            if ($vbWidth <= 0 || $vbHeight <= 0) return null;

            // Look for the first rect or path that covers the full canvas
            foreach ($svg->childNodes as $node) {
                if (!$node instanceof \DOMElement) continue;

                if ($node->nodeName === 'rect') {
                    $w = (float) $node->getAttribute('width');
                    $h = (float) $node->getAttribute('height');
                    $x = (float) $node->getAttribute('x');
                    $y = (float) $node->getAttribute('y');

                    if ($x <= 1 && $y <= 1 && $w >= $vbWidth * 0.95 && $h >= $vbHeight * 0.95) {
                        $svg->removeChild($node);
                        return $dom->saveXML();
                    }
                }

                if ($node->nodeName === 'path') {
                    $d = $node->getAttribute('d');
                    // Simple heuristic: check if path draws a full-canvas rectangle
                    if (preg_match('/^M\s*0[\s,]+0.*[Zz]\s*$/', trim($d))) {
                        $svg->removeChild($node);
                        return $dom->saveXML();
                    }
                }

                // Only check the first few elements
                break;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
