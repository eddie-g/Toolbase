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
        Schema::create('ai_price_log', function (Blueprint $table) {
            $table->id();
            $table->string('session')->nullable()->index();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->string('user_email')->nullable()->index();
            $table->string('request_type'); // 'gemini' or 'dalle'
            $table->string('model_name')->nullable(); // 'gemini-2.0-flash', 'dall-e-3'
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->integer('image_count')->default(0);
            $table->string('image_size')->nullable(); // '1024x1024', '1024x1792', etc.
            $table->decimal('cost_usd', 10, 6)->nullable(); // Actual cost after completion
            $table->decimal('estimated_cost_usd', 10, 6)->nullable(); // Estimated cost before request
            $table->text('prompt_preview')->nullable(); // First 200 chars of prompt
            $table->string('status')->default('estimated'); // 'estimated', 'confirmed', 'completed', 'failed'
            $table->timestamps();
            
            // Composite index for common queries
            $table->index(['session', 'document_id']);
            $table->index(['user_email', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_price_log');
    }
};
