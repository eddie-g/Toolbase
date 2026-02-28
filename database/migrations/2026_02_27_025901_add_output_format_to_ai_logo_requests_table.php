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
            $table->string('output_format', 20)->nullable()->after('model')->comment('raster or vector');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->dropColumn('output_format');
        });
    }
};
