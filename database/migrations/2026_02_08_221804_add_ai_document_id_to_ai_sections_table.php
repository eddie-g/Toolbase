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
        Schema::table('ai_sections', function (Blueprint $table) {
            $table->foreignId('ai_document_id')->nullable()->constrained('ai_documents')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_sections', function (Blueprint $table) {
            $table->dropForeign(['ai_document_id']);
            $table->dropColumn('ai_document_id');
        });
    }
};
