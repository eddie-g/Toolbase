<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->json('showcase_image_indexes')->nullable()->after('is_showcase');
        });
    }

    public function down(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->dropColumn('showcase_image_indexes');
        });
    }
};
