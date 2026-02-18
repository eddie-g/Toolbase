<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->longText('result_data')->nullable()->after('image_urls');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->dropColumn('result_data');
        });
    }
};
