<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AiRate;

class AiRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rates = [
            // DALL-E 3 Standard Quality
            [
                'model_name' => 'dall-e-3',
                'model_variant' => 'standard',
                'resolution' => '1024x1024',
                'base_cost_usd' => 0.040,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.060,
                'is_active' => true,
                'notes' => 'DALL-E 3 Standard Quality 1024x1024',
            ],
            [
                'model_name' => 'dall-e-3',
                'model_variant' => 'standard',
                'resolution' => '1024x1792',
                'base_cost_usd' => 0.080,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.120,
                'is_active' => true,
                'notes' => 'DALL-E 3 Standard Quality 1024x1792 (portrait)',
            ],
            [
                'model_name' => 'dall-e-3',
                'model_variant' => 'standard',
                'resolution' => '1792x1024',
                'base_cost_usd' => 0.080,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.120,
                'is_active' => true,
                'notes' => 'DALL-E 3 Standard Quality 1792x1024 (landscape)',
            ],
            // DALL-E 3 HD Quality
            [
                'model_name' => 'dall-e-3',
                'model_variant' => 'hd',
                'resolution' => '1024x1024',
                'base_cost_usd' => 0.080,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.120,
                'is_active' => true,
                'notes' => 'DALL-E 3 HD Quality 1024x1024',
            ],
            [
                'model_name' => 'dall-e-3',
                'model_variant' => 'hd',
                'resolution' => '1024x1792',
                'base_cost_usd' => 0.120,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.180,
                'is_active' => true,
                'notes' => 'DALL-E 3 HD Quality 1024x1792 (portrait)',
            ],
            [
                'model_name' => 'dall-e-3',
                'model_variant' => 'hd',
                'resolution' => '1792x1024',
                'base_cost_usd' => 0.120,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.180,
                'is_active' => true,
                'notes' => 'DALL-E 3 HD Quality 1792x1024 (landscape)',
            ],
            
            // Flux Schnell (per megapixel base price)
            [
                'model_name' => 'flux-schnell',
                'model_variant' => 'standard',
                'resolution' => '512x512',
                'base_cost_usd' => 0.000786, // 0.003 per MP × 0.262144 MP
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.001179,
                'is_active' => true,
                'notes' => 'Flux Schnell 512x512 (0.262144 MP @ $0.003/MP)',
            ],
            
            // Flux Pro
            [
                'model_name' => 'flux-pro',
                'model_variant' => 'standard',
                'resolution' => '512x512',
                'base_cost_usd' => 0.010486, // 0.04 per MP × 0.262144 MP
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.015729,
                'is_active' => true,
                'notes' => 'Flux Pro 512x512 (0.262144 MP @ $0.04/MP)',
            ],
            [
                'model_name' => 'flux-pro',
                'model_variant' => 'standard',
                'resolution' => '1024x1024',
                'base_cost_usd' => 0.041943, // 0.04 per MP × 1.048576 MP
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.062915,
                'is_active' => true,
                'notes' => 'Flux Pro 1024x1024 (1.048576 MP @ $0.04/MP)',
            ],
            [
                'model_name' => 'flux-pro',
                'model_variant' => 'standard',
                'resolution' => '1536x1536',
                'base_cost_usd' => 0.094372, // 0.04 per MP × 2.359296 MP
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.141558,
                'is_active' => true,
                'notes' => 'Flux Pro 1536x1536 (2.359296 MP @ $0.04/MP)',
            ],
            
            // Recraft V2
            [
                'model_name' => 'recraft-v2',
                'model_variant' => 'raster',
                'resolution' => '1024x1024',
                'base_cost_usd' => 0.022,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.033,
                'is_active' => true,
                'notes' => 'Recraft V2 Raster (22 units @ $0.001/unit)',
            ],
            [
                'model_name' => 'recraft-v2',
                'model_variant' => 'vector',
                'resolution' => '1024x1024',
                'base_cost_usd' => 0.044,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.066,
                'is_active' => true,
                'notes' => 'Recraft V2 Vector (44 units @ $0.001/unit)',
            ],
            
            // Recraft V3 (PRO)
            [
                'model_name' => 'recraft-v3',
                'model_variant' => 'raster',
                'resolution' => '1024x1024',
                'base_cost_usd' => 0.040,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.060,
                'is_active' => true,
                'notes' => 'Recraft V3 Raster (40 units @ $0.001/unit)',
            ],
            [
                'model_name' => 'recraft-v3',
                'model_variant' => 'vector',
                'resolution' => '1024x1024',
                'base_cost_usd' => 0.080,
                'markup_percentage' => 50.00,
                'user_cost_usd' => 0.120,
                'is_active' => true,
                'notes' => 'Recraft V3 Vector (80 units @ $0.001/unit)',
            ],
        ];

        foreach ($rates as $rate) {
            AiRate::updateOrCreate(
                [
                    'model_name' => $rate['model_name'],
                    'model_variant' => $rate['model_variant'],
                    'resolution' => $rate['resolution'],
                ],
                $rate
            );
        }

        $this->command->info('✓ AI rates seeded successfully');
    }
}
