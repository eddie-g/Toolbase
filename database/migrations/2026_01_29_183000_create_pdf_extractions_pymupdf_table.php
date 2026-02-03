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
        Schema::create('pdf_extractions_fitz', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('pdf_filename');
            $table->integer('total_pages');
            $table->integer('total_words');
            $table->longText('full_text');
            $table->json('extraction_data'); // Stores detailed text positioning data
            $table->timestamps();
            
            $table->index('document_id');
            $table->index('pdf_filename');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_extractions_fitz');
    }
};
