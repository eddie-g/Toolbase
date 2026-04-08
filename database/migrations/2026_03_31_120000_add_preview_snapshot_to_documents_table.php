<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (!Schema::hasColumn('documents', 'preview_image')) {
                $table->longText('preview_image')->nullable()->after('size_bytes');
            }
            if (!Schema::hasColumn('documents', 'preview_image_mime_type')) {
                $table->string('preview_image_mime_type', 64)->nullable()->after('preview_image');
            }
            if (!Schema::hasColumn('documents', 'preview_image_width')) {
                $table->unsignedInteger('preview_image_width')->nullable()->after('preview_image_mime_type');
            }
            if (!Schema::hasColumn('documents', 'preview_image_height')) {
                $table->unsignedInteger('preview_image_height')->nullable()->after('preview_image_width');
            }
            if (!Schema::hasColumn('documents', 'preview_image_updated_at')) {
                $table->timestamp('preview_image_updated_at')->nullable()->after('preview_image_height');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'preview_image_updated_at')) {
                $table->dropColumn('preview_image_updated_at');
            }
            if (Schema::hasColumn('documents', 'preview_image_height')) {
                $table->dropColumn('preview_image_height');
            }
            if (Schema::hasColumn('documents', 'preview_image_width')) {
                $table->dropColumn('preview_image_width');
            }
            if (Schema::hasColumn('documents', 'preview_image_mime_type')) {
                $table->dropColumn('preview_image_mime_type');
            }
            if (Schema::hasColumn('documents', 'preview_image')) {
                $table->dropColumn('preview_image');
            }
        });
    }
};
