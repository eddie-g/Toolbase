<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $values = [
            'word_provider' => 'adobe',
            'excel_provider' => 'adobe',
            'fallback_to_local' => false,
            'updated_at' => now(),
        ];

        if (DB::table('document_conversion_settings')->exists()) {
            DB::table('document_conversion_settings')->update($values);
        } else {
            DB::table('document_conversion_settings')->insert($values + [
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('document_conversion_settings')->update([
            'fallback_to_local' => true,
            'updated_at' => now(),
        ]);
    }
};
