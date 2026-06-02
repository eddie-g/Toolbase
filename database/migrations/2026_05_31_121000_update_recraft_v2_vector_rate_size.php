<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_rates')
            ->where('model_name', 'recraft-v2')
            ->where('model_variant', 'vector')
            ->update([
                'resolution' => '1:1',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('ai_rates')
            ->where('model_name', 'recraft-v2')
            ->where('model_variant', 'vector')
            ->update([
                'resolution' => '1024x1024',
                'updated_at' => now(),
            ]);
    }
};