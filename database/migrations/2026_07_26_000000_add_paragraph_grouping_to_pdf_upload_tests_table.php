<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_upload_tests', function (Blueprint $table) {
            $table->boolean('paragraph_grouping_enabled')
                ->default(false)
                ->after('pdf_base64');
        });
    }

    public function down(): void
    {
        Schema::table('pdf_upload_tests', function (Blueprint $table) {
            $table->dropColumn('paragraph_grouping_enabled');
        });
    }
};
