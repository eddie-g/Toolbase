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
        Schema::create('ai_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_response_id')->constrained('ai_responses')->onDelete('cascade');
            $table->string('session')->nullable();
            $table->string('document_id')->nullable();
            $table->integer('section_number');
            $table->text('prompt'); // The prompt used to generate the image
            $table->string('storage_type')->default('url'); // 'url', 'base64', or 'path'
            $table->longText('image_data'); // URL, base64 string, or file path
            $table->string('mime_type')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->timestamps();
            
            $table->index(['document_id', 'session']);
            $table->index('section_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_images');
    }
};
