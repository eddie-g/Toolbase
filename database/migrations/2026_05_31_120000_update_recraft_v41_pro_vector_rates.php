<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('ai_rates')
            ->where('model_name', 'recraft-v4')
            ->where('model_variant', 'vector')
            ->update([
                'resolution' => '1:1',
                'base_cost_usd' => 0.080000,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.120000,
                'is_active' => true,
                'notes' => 'Recraft V4 Vector (80 units @ $0.001/unit)',
                'updated_at' => $now,
            ]);

        DB::table('ai_rates')->upsert([
            [
                'model_name' => 'recraft-v4.1-pro',
                'model_variant' => 'vector',
                'resolution' => '1:1',
                'base_cost_usd' => 0.300000,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.450000,
                'is_active' => true,
                'notes' => 'Recraft V4.1 Pro Vector (300 units @ $0.001/unit)',
                'created_at' => $now,
                'updated_at' => $now,
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

    public function down(): void
    {
        $now = now();

        DB::table('ai_rates')->where('model_name', 'recraft-v4.1-pro')->delete();

        DB::table('ai_rates')
            ->where('model_name', 'recraft-v4')
            ->where('model_variant', 'vector')
            ->update([
                'resolution' => '1024x1024',
                'base_cost_usd' => 0.040000,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.060000,
                'is_active' => true,
                'notes' => 'Recraft V4 Vector (40 units @ $0.001/unit)',
                'updated_at' => $now,
            ]);
    }
};