<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Previews move out of the row into files (App\Services\DocumentPreviews).
     * preview_image held them as base64, about 30 KB that came along with every
     * Document loaded anywhere, including each route-bound editor request. The
     * old column stays until documents:migrate-previews has emptied it.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('preview_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('preview_path');
        });
    }
};
