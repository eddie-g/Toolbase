<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-image state for a generation, keyed by the image's index in image_urls:
 * its own name, the upscale that replaced it, and trash / permanent delete.
 * The request-level columns (domain, status) are shared by every image in a
 * batch, so none of this can live there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->json('image_meta')->nullable()->after('image_urls');
        });
    }

    public function down(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->dropColumn('image_meta');
        });
    }
};
