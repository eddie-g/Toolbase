<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRate extends Model
{
    protected $fillable = [
        'model_name',
        'model_variant',
        'resolution',
        'base_cost_usd',
        'markup_percentage',
        'user_cost_usd',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'base_cost_usd' => 'decimal:6',
        'markup_percentage' => 'decimal:2',
        'user_cost_usd' => 'decimal:6',
        'is_active' => 'boolean',
    ];

    /**
     * Get the user cost for a specific model configuration.
     * 
     * @param string $modelName Model name (e.g., 'dall-e-3')
     * @param string|null $variant Model variant (e.g., 'standard', 'hd')
     * @param string|null $resolution Resolution (e.g., '1024x1024')
     * @return float|null User cost in USD, or null if not found
     */
    public static function getUserCost(string $modelName, ?string $variant = null, ?string $resolution = null): ?float
    {
        $rate = self::where('model_name', $modelName)
            ->where('model_variant', $variant)
            ->where('resolution', $resolution)
            ->where('is_active', true)
            ->first();

        return $rate ? (float) $rate->user_cost_usd : null;
    }

    /**
     * Calculate user cost from base cost and markup percentage.
     * 
     * @param float $baseCost Base cost in USD
     * @param float $markupPercentage Markup percentage (e.g., 50 for 50%)
     * @return float User cost in USD
     */
    public static function calculateUserCost(float $baseCost, float $markupPercentage): float
    {
        return round($baseCost * (1 + ($markupPercentage / 100)), 6);
    }

    /**
     * Get all rates for a specific model.
     */
    public static function getRatesForModel(string $modelName): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('model_name', $modelName)
            ->where('is_active', true)
            ->orderBy('model_variant')
            ->orderBy('resolution')
            ->get();
    }
}
