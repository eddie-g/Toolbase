<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            if (!Schema::hasColumn('pdf_state', 'annotation_debug')) {
                $table->longText('annotation_debug')->nullable()->after('flag_images');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            if (Schema::hasColumn('pdf_state', 'annotation_debug')) {
                $table->dropColumn('annotation_debug');
            }
        });
    }
};
