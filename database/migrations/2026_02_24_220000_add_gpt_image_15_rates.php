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
                    'model_name'       => 'gpt-image-1.5',
                    'model_variant'    => $variant,
                    'resolution'       => $resolution,
                    'base_cost_usd'    => $base,
                    'markup_percentage'=> 50.00,
                    'user_cost_usd'    => $user,
                    'is_active'        => true,
                    'notes'            => 'GPT Image 1.5 official pricing (June 2026)',
                    'created_at'       => $now,
                    'updated_at'       => $now,
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

        // Deactivate stale dall-e-3 rows so they no longer serve as fallback
        DB::table('ai_rates')
            ->where('model_name', 'dall-e-3')
            ->update(['is_active' => false, 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('ai_rates')->where('model_name', 'gpt-image-1.5')->delete();

        // Restore dall-e-3 rows
        DB::table('ai_rates')
            ->where('model_name', 'dall-e-3')
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
