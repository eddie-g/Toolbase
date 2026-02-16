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

    /**
     * Fallback prices per megapixel (used if API is unreachable).
     */
    private const FALLBACK_PRICES = [
        'fal-ai/flux/schnell' => ['price' => 0.003, 'unit' => 'megapixels'],
        'fal-ai/flux-pro/v1.1' => ['price' => 0.04, 'unit' => 'megapixels'],
        'fal-ai/birefnet' => ['price' => 0.00111, 'unit' => 'compute seconds'],
        'fal-ai/recraft/vectorize' => ['price' => 0.01, 'unit' => 'images'],
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
     * Estimate logo generation cost from real fal.ai pricing.
     *
     * @param int    $imageCount  Number of images
     * @param bool   $isPro       Whether using flux-pro/v1.1
     * @param int    $proSize     PRO resolution (512, 1024, 1536) — ignored for Schnell (always 512)
     * @param string $style       Style name ('vector' adds vectorize cost)
     * @param string $bgColor     Background color ('transparent' or hex adds birefnet cost)
     * @return array Cost breakdown
     */
    public static function estimateCost(
        int $imageCount = 4,
        bool $isPro = false,
        int $proSize = 1024,
        string $style = 'professional',
        string $bgColor = 'white',
    ): array {
        $pricing = self::fetchUnitPrices();
        $source = $pricing['source'];
        $prices = $pricing['prices'];

        // Determine generation model and megapixels
        $modelId = $isPro ? 'fal-ai/flux-pro/v1.1' : 'fal-ai/flux/schnell';
        $resolution = $isPro ? $proSize : 512;
        $megapixels = ($resolution * $resolution) / 1_000_000;

        // Base generation cost: price_per_megapixel × megapixels × image_count
        $genPricePerMp = $prices[$modelId]['price'] ?? self::FALLBACK_PRICES[$modelId]['price'];
        $genCostPerImage = round($genPricePerMp * $megapixels, 6);
        $genCostTotal = round($genCostPerImage * $imageCount, 6);

        // Add-on costs
        $bgRemoveCost = 0;
        $vectorizeCost = 0;

        // Background removal via birefnet (for transparent or custom hex colors)
        $needsBgRemove = $bgColor === 'transparent' || preg_match('/^#[0-9a-fA-F]{6}$/', $bgColor);
        if ($needsBgRemove) {
            $birefnetPrice = $prices['fal-ai/birefnet']['price'] ?? self::FALLBACK_PRICES['fal-ai/birefnet']['price'];
            // birefnet charges per compute-second; typical call ~2-3 seconds
            $bgRemoveCost = round($birefnetPrice * 3 * $imageCount, 6);
        }

        // SVG vectorization via recraft (for vector style)
        if ($style === 'vector') {
            $vectorPrice = $prices['fal-ai/recraft/vectorize']['price'] ?? self::FALLBACK_PRICES['fal-ai/recraft/vectorize']['price'];
            $vectorizeCost = round($vectorPrice * $imageCount, 6);
        }

        $totalCostPerImage = round($genCostPerImage + ($needsBgRemove ? ($birefnetPrice * 3) : 0) + ($style === 'vector' ? $vectorPrice : 0), 6);
        $totalCost = round($genCostTotal + $bgRemoveCost + $vectorizeCost, 6);

        return [
            'image_count' => $imageCount,
            'model' => $modelId,
            'resolution' => "{$resolution}x{$resolution}",
            'megapixels' => round($megapixels, 3),
            'cost_per_image' => $totalCostPerImage,
            'estimated_cost_usd' => $totalCost,
            'breakdown' => [
                'generation' => $genCostTotal,
                'bg_removal' => $bgRemoveCost,
                'vectorize' => $vectorizeCost,
            ],
            'source' => $source,
            'prices' => [
                'gen_per_mp' => $genPricePerMp,
                'birefnet_per_sec' => $needsBgRemove ? $birefnetPrice : null,
                'vectorize_per_img' => $style === 'vector' ? $vectorPrice : null,
            ],
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
