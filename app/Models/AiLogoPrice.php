<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class AiLogoPrice extends Model
{
    protected $fillable = [
        'user_id',
        'ai_logo_request_id',
        'session',
        'user_email',
        'request_type',
        'model_name',
        'image_count',
        'image_size',
        'num_inference_steps',
        'guidance_scale',
        'cost_per_image',
        'estimated_cost_usd',
        'actual_cost_usd',
        'status',
        'prompt_preview',
        'response_time_ms',
    ];

    protected $casts = [
        'image_count' => 'integer',
        'num_inference_steps' => 'integer',
        'guidance_scale' => 'decimal:2',
        'cost_per_image' => 'decimal:6',
        'estimated_cost_usd' => 'decimal:6',
        'actual_cost_usd' => 'decimal:6',
        'response_time_ms' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logoRequest(): BelongsTo
    {
        return $this->belongsTo(AiLogoRequest::class, 'ai_logo_request_id');
    }

    public static function gptImageResolutionForSize(string $imageSize): string
    {
        return match ($imageSize) {
            '16:9' => '1536x1024',
            '9:16' => '1024x1536',
            default => '1024x1024',
        };
    }

    /**
     * Fallback prices per megapixel (used if API is unreachable).
     */
    private const FALLBACK_PRICES = [
        'fal-ai/flux/schnell'  => ['price' => 0.003, 'unit' => 'megapixels'],
        'fal-ai/flux-2-flex'   => ['price' => 0.05,  'unit' => 'megapixels'],  // FLUX.2 [flex] — upgraded from flux-pro/v1.1 ($0.04)
        'fal-ai/nano-banana-2' => ['price' => 0.0398, 'unit' => 'images'],
        'fal-ai/birefnet'      => ['price' => 0.00111, 'unit' => 'compute seconds'],
        'fal-ai/recraft/vectorize' => ['price' => 0.01, 'unit' => 'images'],
        'fal-ai/topaz/upscale/image' => ['price' => 0.08, 'unit' => 'images'],
    ];

    /**
     * Fetch all model unit prices from fal.ai GET /v1/models/pricing API.
     * Caches for 1 hour. Returns associative array of endpoint_id => unit_price.
     */
    public static function fetchUnitPrices(): array
    {
        return Cache::remember('fal_unit_prices', 3600, function () {
            try {
                $endpointIds = array_keys(self::FALLBACK_PRICES);
                $query = implode('&', array_map(fn($id) => 'endpoint_id=' . urlencode($id), $endpointIds));

                $response = Http::withHeaders([
                    'Authorization' => 'Key ' . config('services.fal.key'),
                ])->timeout(10)->get('https://api.fal.ai/v1/models/pricing?' . $query);

                if ($response->successful()) {
                    $data = $response->json();
                    $prices = [];
                    foreach ($data['prices'] ?? [] as $entry) {
                        $prices[$entry['endpoint_id']] = [
                            'price' => (float) $entry['unit_price'],
                            'unit' => $entry['unit'] ?? 'unknown',
                        ];
                    }
                    if (!empty($prices)) {
                        return ['source' => 'fal_api', 'prices' => $prices];
                    }
                }

                \Log::warning('Fal.ai pricing API failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            } catch (\Exception $e) {
                \Log::warning('Fal.ai pricing API error: ' . $e->getMessage());
            }

            return ['source' => 'fallback', 'prices' => self::FALLBACK_PRICES];
        });
    }

    /**
     * Estimate logo generation cost from real fal.ai pricing with 50% markup.
     *
     * @param int    $imageCount  Number of images
     * @param bool   $isPro       Whether using flux-2-flex (PRO) or flux/schnell (standard)
     * @param int    $proSize     PRO resolution (512, 1024, 1536) — ignored for Schnell (always 512)
     * @param string $style       Style name ('vector' adds vectorize cost)
     * @param string $bgColor     Background color ('none', 'transparent', or hex adds background-removal cost)
     * @param string $outputFormat Output format ('raster' or 'vector')
     * @param string $imageModel  Image model ('flux', 'dalle', 'recraft')
     * @return array Cost breakdown
     */
    public static function estimateCost(
        int $imageCount = 4,
        bool $isPro = false,
        int $proSize = 1024,
        string $style = 'professional',
        string $bgColor = 'white',
        string $outputFormat = 'raster',
        string $imageModel = 'flux',
        ?int $customWidth = null,
        ?int $customHeight = null,
    ): array {
        $hasCustomDimensions = $customWidth !== null && $customHeight !== null;
        $resolution = $isPro ? $proSize : 512;
        
        // Determine model ID based on imageModel and outputFormat
        if ($imageModel === 'flux' && $outputFormat === 'raster' && !$isPro) {
            $modelId = 'fal-ai/nano-banana-2';
            $modelName = 'nano-banana-2';
        } else {
            $modelName = $isPro ? 'flux-2-flex' : 'flux-schnell';
            $modelId = $isPro ? 'fal-ai/flux-2-flex' : 'fal-ai/flux/schnell';
        }
        
        $resolutionStr = $hasCustomDimensions ? "{$customWidth}x{$customHeight}" : "{$resolution}x{$resolution}";
        
        // Try to get pricing from ai_rates table
        $dbRate = \App\Models\AiRate::where('model_name', $modelName)
            ->where('model_variant', 'standard')
            ->where('resolution', $resolutionStr)
            ->where('is_active', true)
            ->first();
        
        $pricing = self::fetchUnitPrices();
        $source = $pricing['source'];
        $prices = $pricing['prices'];
        
        // Calculate megapixels (used for display and some models)
        $megapixels = $hasCustomDimensions
            ? ($customWidth * $customHeight) / 1_000_000
            : ($resolution * $resolution) / 1_000_000;
        
        // Calculate base generation cost
        $fallbackData = self::FALLBACK_PRICES[$modelId] ?? ['price' => 0.05, 'unit' => 'megapixels'];
        $priceData = $prices[$modelId] ?? $fallbackData;
        $unitPrice = $priceData['price'];
        $unit = $priceData['unit'] ?? $fallbackData['unit'];
        
        // Calculate cost based on unit type
        if ($unit === 'images') {
            // Flat price per image (e.g., nano-banana-2)
            $baseGenCostPerImage = round($unitPrice, 6);
        } else {
            // Price per megapixel (e.g., flux models)
            $baseGenCostPerImage = round($unitPrice * $megapixels, 6);
        }
        
        // Apply markup to generation cost
        if ($dbRate) {
            $genCostPerImage = (float) $dbRate->user_cost_usd;
            $markupPercentage = (float) $dbRate->markup_percentage;
        } else {
            $markupPercentage = 50.00;
            $genCostPerImage = round($baseGenCostPerImage * 1.5, 6);
        }
        
        $baseGenCostTotal = round($baseGenCostPerImage * $imageCount, 6);
        $genCostTotal = round($genCostPerImage * $imageCount, 6);

        // Add-on costs (apply same 50% markup)
        $bgRemoveCost = 0;
        $bgRemoveBaseCost = 0;
        $vectorizeCost = 0;
        $vectorizeBaseCost = 0;

        // Background removal via birefnet
        $needsBgRemove = in_array($bgColor, ['none', 'transparent'], true) || preg_match('/^#[0-9a-fA-F]{6}$/', $bgColor);
        if ($needsBgRemove) {
            $birefnetPrice = $prices['fal-ai/birefnet']['price'] ?? self::FALLBACK_PRICES['fal-ai/birefnet']['price'];
            $bgRemoveBaseCost = round($birefnetPrice * 3 * $imageCount, 6);
            $bgRemoveCost = round($bgRemoveBaseCost * 1.5, 6); // 50% markup
        }

        // SVG vectorization via recraft
        $needsVectorize = $outputFormat === 'vector';
        if ($needsVectorize) {
            $vectorPrice = $prices['fal-ai/recraft/vectorize']['price'] ?? self::FALLBACK_PRICES['fal-ai/recraft/vectorize']['price'];
            $vectorizeBaseCost = round($vectorPrice * $imageCount, 6);
            $vectorizeCost = round($vectorizeBaseCost * 1.5, 6); // 50% markup
        }

        $baseCostPerImage = round($baseGenCostPerImage + ($needsBgRemove ? ($birefnetPrice * 3) : 0) + ($needsVectorize ? $vectorPrice : 0), 6);
        $totalCostPerImage = round($genCostPerImage + ($needsBgRemove ? ($bgRemoveCost / $imageCount) : 0) + ($needsVectorize ? ($vectorizeCost / $imageCount) : 0), 6);
        
        $baseCostTotal = round($baseGenCostTotal + $bgRemoveBaseCost + $vectorizeBaseCost, 6);
        $totalCost = round($genCostTotal + $bgRemoveCost + $vectorizeCost, 6);
        $markupAmount = round($totalCost - $baseCostTotal, 6);

        return [
            'image_count' => $imageCount,
            'model' => $modelId,
            'resolution' => $resolutionStr,
            'megapixels' => round($megapixels, 3),
            'cost_per_image' => $totalCostPerImage,
            'estimated_cost_usd' => $totalCost,
            'base_cost_per_image' => $baseCostPerImage,
            'base_cost_total' => $baseCostTotal,
            'markup_percentage' => $markupPercentage,
            'markup_amount' => $markupAmount,
            'breakdown' => [
                'generation' => $genCostTotal,
                'bg_removal' => $bgRemoveCost,
                'vectorize' => $vectorizeCost,
            ],
            'source' => $dbRate ? 'database' : 'static_with_markup',
            'prices' => [
                'gen_per_mp' => $unitPrice,
                'birefnet_per_sec' => $needsBgRemove ? $birefnetPrice : null,
                'vectorize_per_img' => $needsVectorize ? $vectorPrice : null,
            ],
        ];
    }

    /**
     * Estimate cost for GPT Image 1.5 generation with 50% markup from ai_rates table.
     *
     * Accepts legacy DALL-E 3 quality names ('standard', 'hd') which are mapped internally
     * to GPT Image 1.5 tiers ('medium', 'high'). Native tier names ('low', 'medium', 'high')
     * are also accepted directly.
     */
    public static function estimateDalleCost(
        int $imageCount = 4,
        string $resolution = '1024x1024',
        string $quality = 'standard',
        string $outputFormat = 'raster',
        string $bgColor = 'white',
    ): array {
        // Map legacy DALL-E 3 quality names → GPT Image 1.5 quality tiers
        $gptQuality = match($quality) {
            'hd'       => 'high',
            'standard' => 'medium',
            default    => $quality, // pass-through for 'low', 'medium', 'high'
        };

        // Try to get pricing from ai_rates table (gpt-image-1.5 first, fall back to dall-e-3)
        $dbRate = \App\Models\AiRate::where('model_name', 'gpt-image-1.5')
            ->where('model_variant', $gptQuality)
            ->where('resolution', $resolution)
            ->where('is_active', true)
            ->first();

        if (!$dbRate) {
            // Backward-compatible fallback: check old dall-e-3 db rates
            $dbRate = \App\Models\AiRate::where('model_name', 'dall-e-3')
                ->where('model_variant', $quality)
                ->where('resolution', $resolution)
                ->where('is_active', true)
                ->first();
        }

        // GPT Image 1.5 base prices (OpenAI standard pricing, per 1 image).
        if ($dbRate) {
            $baseCost = (float) $dbRate->base_cost_usd;
            $costPerImage = (float) $dbRate->user_cost_usd;
            $markupPercentage = (float) $dbRate->markup_percentage;
        } else {
            $basePrices = [
                'medium' => [
                    '1024x1024' => 0.034,
                    '1024x1536' => 0.050,
                    '1536x1024' => 0.050,
                    '1024x1792' => 0.050,
                    '1792x1024' => 0.050,
                ],
                'high' => [
                    '1024x1024' => 0.133,
                    '1024x1536' => 0.200,
                    '1536x1024' => 0.200,
                    '1024x1792' => 0.200,
                    '1792x1024' => 0.200,
                ],
                'low' => [
                    '1024x1024' => 0.009,
                    '1024x1536' => 0.013,
                    '1536x1024' => 0.013,
                    '1024x1792' => 0.013,
                    '1792x1024' => 0.013,
                ],
            ];

            $baseCost = $basePrices[$gptQuality][$resolution] ?? 0.034;
            $markupPercentage = 50.00;
            $costPerImage = round($baseCost * 1.5, 6); // 50% markup
        }

        $baseCostTotal = round($baseCost * $imageCount, 6);
        $totalCost = round($costPerImage * $imageCount, 6);
        $markupAmount = round($totalCost - $baseCostTotal, 6);

        // Add vectorization cost if output format is vector
        $vectorizeCostPerImage = 0;
        if ($outputFormat === 'vector') {
            $vectorizeCostPerImage = 0.01; // $0.01 per image for PNG to SVG vectorization
        }
        $vectorizeCostTotal = round($vectorizeCostPerImage * $imageCount, 6);
        $totalCost = round($totalCost + $vectorizeCostTotal, 6);

        $bgRemoveCostPerImage = 0;
        if (in_array($bgColor, ['none', 'transparent'], true) || preg_match('/^#[0-9a-fA-F]{6}$/', $bgColor)) {
            $bgRemoveCostPerImage = round((self::FALLBACK_PRICES['fal-ai/birefnet']['price'] ?? 0.00111) * 3 * 1.5, 6);
        }
        $bgRemoveCostTotal = round($bgRemoveCostPerImage * $imageCount, 6);
        $totalCost = round($totalCost + $bgRemoveCostTotal, 6);

        return [
            'image_count' => $imageCount,
            'model' => 'gpt-image-1.5',
            'resolution' => $resolution,
            'quality' => $gptQuality,
            'output_format' => $outputFormat,
            'cost_per_image' => $costPerImage,
            'estimated_cost_usd' => $totalCost,
            'base_cost_per_image' => $baseCost,
            'base_cost_total' => $baseCostTotal,
            'markup_percentage' => $markupPercentage,
            'markup_amount' => $markupAmount,
            'breakdown' => [
                'generation' => round($costPerImage * $imageCount, 6),
                'bg_removal' => $bgRemoveCostTotal,
                'vectorize' => $vectorizeCostTotal,
            ],
            'source' => $dbRate ? 'database' : 'static_with_markup',
        ];
    }

    /**
     * Estimate Topaz image upscaling cost with the same 50% markup used elsewhere.
     */
    public static function estimateUpscaleCost(int $imageCount = 1, int $upscaleFactor = 2, ?float $outputMegapixels = null): array
    {
        $baseCostPerImage = match (true) {
            $outputMegapixels !== null && $outputMegapixels > 96 => 1.36,
            $outputMegapixels !== null && $outputMegapixels > 48 => 0.32,
            $outputMegapixels !== null && $outputMegapixels > 24 => 0.16,
            default => 0.08,
        };

        $markupPercentage = 50.00;
        $costPerImage = round($baseCostPerImage * 1.5, 6);
        $baseCostTotal = round($baseCostPerImage * $imageCount, 6);
        $totalCost = round($costPerImage * $imageCount, 6);

        return [
            'image_count' => $imageCount,
            'model' => 'fal-ai/topaz/upscale/image',
            'resolution' => $upscaleFactor . 'x upscale',
            'upscale_factor' => $upscaleFactor,
            'megapixels' => $outputMegapixels !== null ? round($outputMegapixels, 3) : null,
            'cost_per_image' => $costPerImage,
            'estimated_cost_usd' => $totalCost,
            'base_cost_per_image' => $baseCostPerImage,
            'base_cost_total' => $baseCostTotal,
            'markup_percentage' => $markupPercentage,
            'markup_amount' => round($totalCost - $baseCostTotal, 6),
            'breakdown' => [
                'upscale' => $totalCost,
            ],
            'source' => 'static_with_markup',
        ];
    }

    /**
     * Legacy wrapper for backward compatibility.
     */
    public static function estimateCostFromApi(int $imageCount = 4, string $imageSize = 'square_hd'): array
    {
        return self::estimateCost(imageCount: $imageCount, isPro: false);
    }
}
