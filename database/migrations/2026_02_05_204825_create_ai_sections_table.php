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
        Schema::create('ai_sections', function (Blueprint $table) {
            $table->id();
            $table->string('document_id');
            $table->string('session');
            $table->json('sections_data');
            $table->decimal('page_width', 10, 2)->nullable();
            $table->decimal('page_height', 10, 2)->nullable();
            $table->timestamps();
            
            // Index for quick lookups
            $table->index(['document_id', 'session']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_sections');
    }
};
