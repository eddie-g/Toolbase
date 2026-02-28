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

        try {
            if ($imageModel === 'recraft') {
                $result = $this->generateRecraft($prompt, $imageCount, $outputFormat, $isPro, $bgColor, $iconOnly, $colorPalette, $recraftSubstyle);
            } elseif ($imageModel === 'dalle') {
                $result = $this->generateDalle($prompt, $imageCount, $isPro);
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
                    'error_message' => $result['error'],
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

            // ── Post-processing (non-Recraft only) ──
            if ($imageModel !== 'recraft') {
                $images = $this->postProcess($images, $bgColor, $outputFormat);
            }

            // ── Persist images to local storage ──
            $storedImageUrls = [];
            $storedImagePaths = [];
            $imageUrls = [];

            foreach ($images as $idx => &$img) {
                $imgUrl = is_array($img) ? ($img['svg_url'] ?? $img['url'] ?? '') : (string) $img;
                $imageUrls[] = $imgUrl;

                if (!$imgUrl || str_starts_with($imgUrl, 'data:') || $imgUrl === '[base64-omitted]') {
                    continue;
                }

                $stored = $this->storeRemoteLogoImage(
                    imageUrl: $imgUrl,
                    requestId: $this->logoRequestId,
                    userId: $this->userId,
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

            // Attach seed to each image
            $imagesWithSeed = array_map(function ($img) use ($seed) {
                if (is_array($img)) {
                    $img['seed'] = $seed;
                }
                return $img;
            }, $images);

            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            // ── Calculate actual cost and charge ──
            $actualImageCount = count($images);
            if ($imageModel === 'recraft') {
                $actualCost = RecraftPricing::estimateLogoCost(
                    imageCount: $actualImageCount,
                    size: '1024x1024',
                    isPro: $isPro,
                    type: $outputFormat,
                );
            } elseif ($imageModel === 'dalle') {
                $actualCost = AiLogoPrice::estimateDalleCost(
                    imageCount: $actualImageCount,
                    resolution: '1024x1024',
                    quality: $isPro ? 'hd' : 'standard',
                );
            } else {
                $actualCost = AiLogoPrice::estimateCost(
                    imageCount: $actualImageCount,
                    isPro: $isPro,
                    proSize: $proSize,
                    style: $style,
                    bgColor: $bgColor,
                    outputFormat: $outputFormat,
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
                            'resolution' => $imageModel === 'recraft' ? '1024x1024' : ($imageModel === 'dalle' ? '1024x1024' : ($isPro ? "{$proSize}x{$proSize}" : '512x512')),
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

    // ──────────────────────────────────────────────────────────────
    //  Model-specific generation methods
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate images via Recraft (v4 Pro or v2 regular, raster or vector).
     *
     * V4 (Pro): unified endpoint /v1/images/generations, model=recraftv4 or recraftv4_vector.
     *           Both raster and vector supported. `style` and `substyle` are NOT supported by V4.
     * V2 (Regular): endpoint per type (/raster or /vector), model=recraftv2.
     *               Supports `style`, `substyle`.
     */
    private function generateRecraft(string $prompt, int $imageCount, string $outputFormat, bool $isPro, string $bgColor, bool $iconOnly, ?array $colorPalette, ?string $recraftSubstyle): array
    {
        $recraftBaseUrl = config('services.recraft.base_url', 'https://external.api.recraft.ai');
        $recraftKey = config('services.recraft.key');
        $isVector = $outputFormat === 'vector';

        // Use V4 for PRO mode (both raster and vector)
        // Use V2 for non-PRO mode
        if ($isPro) {
            // ── Recraft V4 ────────────────────────────────────────────────
            // Single unified endpoint; supports both raster and vector.
            // No `style` or `substyle` parameters supported.
            $recraftEndpoint = '/v1/images/generations';
            $recraftModel    = $isVector ? 'recraftv4_vector' : 'recraftv4';

            $recraftBody = [
                'prompt'          => $prompt,
                'model'           => $recraftModel,
                'n'               => $imageCount,
                'size'            => '1024x1024',
                'response_format' => 'url',
            ];
        } else {
            // ── Recraft V2 ────────────────────────────────────────────────
            // Separate endpoints per type; supports style/substyle.
            $recraftEndpoint = $isVector ? '/v1/images/generations/vector' : '/v1/images/generations/raster';
            $recraftStyle    = $isVector ? 'vector_illustration' : 'digital_illustration';

            $recraftBody = [
                'prompt'          => $prompt,
                'style'           => $recraftStyle,
                'model'           => 'recraftv2',
                'n'               => $imageCount,
                'size'            => '1024x1024',
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
    private function generateDalle(string $prompt, int $imageCount, bool $isPro): array
    {
        return $this->generateGptImage15($prompt, $imageCount, $isPro);
    }

    /**
     * Generate images via GPT Image 1.5 (OpenAI /images/generations).
     *
     * Quality mapping: standard → medium ($0.042 / img), hd → high ($0.167 / img)
     * Response may contain 'url' or 'b64_json'; both are handled.
     */
    private function generateGptImage15(string $prompt, int $imageCount, bool $isPro): array
    {
        $quality = $isPro ? 'high' : 'medium';
        $allImages = [];

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
                'size'    => '1024x1024',
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

        for ($i = 0; $i < $imageCount; $i++) {
            $proResponse = $this->httpWithResolvedDns($endpoint, [
                'Authorization' => 'Key ' . config('services.fal.key'),
                'Content-Type' => 'application/json',
            ])->retry(3, 3000, function (\Exception $e) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
            })->timeout(120)->post($endpoint, [
                'prompt' => $prompt,
                'image_size' => [
                    'width' => $proSize,
                    'height' => $proSize,
                ],
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
        return $raw;
    }

    private function postProcess(array $images, string $bgColor, string $outputFormat): array
    {
        $needsBgRemoval = ($bgColor === 'transparent' || str_starts_with($bgColor, '#'));

        if ($needsBgRemoval) {
            $falKey = config('services.fal.key');
            foreach ($images as $i => &$img) {
                $imgUrl = is_array($img) ? ($img['url'] ?? '') : (string) $img;
                if (!$imgUrl || str_starts_with($imgUrl, 'data:')) continue;

                try {
                    $birefnetUrl = 'https://fal.run/fal-ai/birefnet';
                    $bgResponse = $this->httpWithResolvedDns($birefnetUrl, [
                        'Authorization' => 'Key ' . $falKey,
                        'Content-Type' => 'application/json',
                    ])->retry(3, 2000, function (\Exception $e) {
                        return $e instanceof \Illuminate\Http\Client\ConnectionException
                            || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
                    })->timeout(60)->post($birefnetUrl, [
                        'image_url' => $imgUrl,
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
            $relativePath = sprintf('logos/%d/%d/%s', $this->userId, $this->logoRequestId, $filename);

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
            $response = $this->httpWithResolvedDns($imageUrl, [])->timeout(45)->get($imageUrl);
            if (!$response->successful()) {
                Log::warning('Failed to download generated logo image', [
                    'request_id' => $requestId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $contentType = strtolower((string) $response->header('Content-Type', ''));
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

            Storage::disk('public')->put($relativePath, $response->body());

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
                if ($node->nodeType !== XML_ELEMENT_NODE) continue;

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
