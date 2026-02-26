<?php

namespace App\Services;

class RecraftPricing
{
    const UNIT_PRICE = 0.001; // $1.00 / 1000 units

    /**
     * Estimate the cost for a Recraft API call.
     *
     * @param string $type  'raster' or 'vector'
     * @param string $version 'v4', 'v3', 'v2', or 'tools'
     * @return array{units: int, usd: float}
     */
    public static function estimate(string $type = 'raster', string $version = 'v4'): array
    {
        $map = [
            'v4' => ['raster' => 40, 'vector' => 80],
            'v3' => ['raster' => 40, 'vector' => 80],
            'v2' => ['raster' => 22, 'vector' => 44],
            'tools' => ['vectorize' => 10, 'remove_bg' => 10],
        ];

        $units = $map[$version][$type] ?? 40;

        return [
            'units' => $units,
            'usd' => $units * self::UNIT_PRICE,
        ];
    }

    /**
     * Estimate the total cost for logo generation via Recraft endpoint with 50% markup.
     *
     * @param int    $imageCount Number of images to generate
     * @param string $size       Image size (e.g. '1024x1024')
     * @param bool   $isPro      Use V3 (PRO) or V2 (standard)
     * @param string $type       Output format: 'vector' or 'raster'
     * @return array Cost breakdown compatible with AiLogoPrice format
     */
    public static function estimateLogoCost(int $imageCount = 1, string $size = '1024x1024', bool $isPro = false, string $type = 'vector'): array
    {
        $version = $isPro ? 'v4' : 'v2';
        $modelName = $isPro ? 'recraft-v4' : 'recraft-v2';
        $modelLabel = "{$modelName}-{$type}";
        
        // Try to get pricing from ai_rates table
        $dbRate = \App\Models\AiRate::where('model_name', $modelName)
            ->where('model_variant', $type)
            ->where('resolution', $size)
            ->where('is_active', true)
            ->first();
        
        if ($dbRate) {
            $baseCost = (float) $dbRate->base_cost_usd;
            $costPerImage = (float) $dbRate->user_cost_usd;
            $markupPercentage = (float) $dbRate->markup_percentage;
        } else {
            // Fallback to static pricing with 50% markup
            $perImage = self::estimate($type, $version);
            $baseCost = $perImage['usd'];
            $markupPercentage = 50.00;
            $costPerImage = round($baseCost * 1.5, 6);
        }
        
        $baseCostTotal = round($baseCost * $imageCount, 6);
        $totalCost = round($costPerImage * $imageCount, 6);
        $markupAmount = round($totalCost - $baseCostTotal, 6);

        return [
            'image_count' => $imageCount,
            'model' => $modelLabel,
            'resolution' => $size,
            'cost_per_image' => $costPerImage,
            'estimated_cost_usd' => $totalCost,
            'base_cost_per_image' => $baseCost,
            'base_cost_total' => $baseCostTotal,
            'markup_percentage' => $markupPercentage,
            'markup_amount' => $markupAmount,
            'breakdown' => [
                'generation' => $totalCost,
                'bg_removal' => 0,
                'vectorize' => 0,
            ],
            'source' => $dbRate ? 'database' : 'static_with_markup',
            'units_per_image' => $dbRate ? null : $perImage['units'] ?? null,
            'output_format' => $type,
        ];
    }
}
