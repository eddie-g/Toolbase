<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rates = [
            // quality / resolution / base / user (50% markup)
            ['low',    '1024x1024', 0.009000, 0.013500],
            ['low',    '1024x1536', 0.013000, 0.019500],
            ['low',    '1536x1024', 0.013000, 0.019500],
            ['low',    '1024x1792', 0.013000, 0.019500],
            ['low',    '1792x1024', 0.013000, 0.019500],
            ['medium', '1024x1024', 0.034000, 0.051000],
            ['medium', '1024x1536', 0.050000, 0.075000],
            ['medium', '1536x1024', 0.050000, 0.075000],
            ['medium', '1024x1792', 0.050000, 0.075000],
            ['medium', '1792x1024', 0.050000, 0.075000],
            ['high',   '1024x1024', 0.133000, 0.199500],
            ['high',   '1024x1536', 0.200000, 0.300000],
            ['high',   '1536x1024', 0.200000, 0.300000],
            ['high',   '1024x1792', 0.200000, 0.300000],
            ['high',   '1792x1024', 0.200000, 0.300000],
        ];

        $now = now();

        foreach ($rates as [$variant, $resolution, $base, $user]) {
            DB::table('ai_rates')->upsert([
                [
                    'model_name'        => 'gpt-image-1.5',
                    'model_variant'     => $variant,
                    'resolution'        => $resolution,
                    'base_cost_usd'     => $base,
                    'markup_percentage' => 50.00,
                    'user_cost_usd'     => $user,
                    'is_active'         => true,
                    'notes'             => 'GPT Image 1.5 official pricing (June 2026)',
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
            ], ['model_name', 'model_variant', 'resolution'], [
                'base_cost_usd',
                'markup_percentage',
                'user_cost_usd',
                'is_active',
                'notes',
                'updated_at',
            ]);
        }
    }

    public function down(): void
    {
        $rates = [
            ['low',    '1024x1024', 0.011000, 0.016500],
            ['low',    '1024x1536', 0.022000, 0.033000],
            ['low',    '1536x1024', 0.022000, 0.033000],
            ['low',    '1024x1792', 0.022000, 0.033000],
            ['low',    '1792x1024', 0.022000, 0.033000],
            ['medium', '1024x1024', 0.042000, 0.063000],
            ['medium', '1024x1536', 0.084000, 0.126000],
            ['medium', '1536x1024', 0.084000, 0.126000],
            ['medium', '1024x1792', 0.084000, 0.126000],
            ['medium', '1792x1024', 0.084000, 0.126000],
            ['high',   '1024x1024', 0.167000, 0.250500],
            ['high',   '1024x1536', 0.334000, 0.501000],
            ['high',   '1536x1024', 0.334000, 0.501000],
            ['high',   '1024x1792', 0.334000, 0.501000],
            ['high',   '1792x1024', 0.334000, 0.501000],
        ];

        $now = now();

        foreach ($rates as [$variant, $resolution, $base, $user]) {
            DB::table('ai_rates')->upsert([
                [
                    'model_name'        => 'gpt-image-1.5',
                    'model_variant'     => $variant,
                    'resolution'        => $resolution,
                    'base_cost_usd'     => $base,
                    'markup_percentage' => 50.00,
                    'user_cost_usd'     => $user,
                    'is_active'         => true,
                    'notes'             => 'GPT Image 1.5 official pricing (Feb 2026)',
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
            ], ['model_name', 'model_variant', 'resolution'], [
                'base_cost_usd',
                'markup_percentage',
                'user_cost_usd',
                'is_active',
                'notes',
                'updated_at',
            ]);
        }
    }
};
