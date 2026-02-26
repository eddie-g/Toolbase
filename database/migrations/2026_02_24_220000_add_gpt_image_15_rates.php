<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rates = [
            // quality / resolution / base / user (50% markup)
            ['low',    '1024x1024', 0.011000, 0.016500],
            ['low',    '1024x1792', 0.022000, 0.033000],
            ['low',    '1792x1024', 0.022000, 0.033000],
            ['medium', '1024x1024', 0.042000, 0.063000],
            ['medium', '1024x1792', 0.084000, 0.126000],
            ['medium', '1792x1024', 0.084000, 0.126000],
            ['high',   '1024x1024', 0.167000, 0.250500],
            ['high',   '1024x1792', 0.334000, 0.501000],
            ['high',   '1792x1024', 0.334000, 0.501000],
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
                    'notes'            => 'GPT Image 1.5 official pricing (Feb 2026)',
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
