<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_upload_test_cases', function (Blueprint $table) {
            $table->uuid('test_id')->nullable()->after('id');
        });

        DB::table('pdf_upload_test_cases')
            ->whereNull('test_id')
            ->orderBy('id')
            ->chunkById(100, function ($cases) {
                foreach ($cases as $case) {
                    DB::table('pdf_upload_test_cases')
                        ->where('id', $case->id)
                        ->update(['test_id' => (string) Str::uuid()]);
                }
            });

        Schema::table('pdf_upload_test_cases', function (Blueprint $table) {
            $table->unique('test_id', 'pdf_upload_test_cases_test_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pdf_upload_test_cases', function (Blueprint $table) {
            $table->dropUnique('pdf_upload_test_cases_test_id_unique');
            $table->dropColumn('test_id');
        });
    }
};
