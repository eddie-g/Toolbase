<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_logo_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('ai_logo_request_id')->nullable()->constrained('ai_logo_requests')->onDelete('set null');
            $table->string('session')->nullable();
            $table->string('user_email')->nullable();
            $table->string('request_type', 40)->default('logo_generation');
            $table->string('model_name', 60)->default('fal-ai/flux/schnell');
            $table->integer('image_count')->default(1);
            $table->string('image_size', 20)->default('512x512');
            $table->integer('num_inference_steps')->default(6);
            $table->decimal('guidance_scale', 4, 2)->default(3.50);
            $table->decimal('cost_per_image', 10, 6)->default(0.003000);
            $table->decimal('estimated_cost_usd', 10, 6)->nullable();
            $table->decimal('actual_cost_usd', 10, 6)->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('prompt_preview')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('ai_logo_request_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_logo_prices');
    }
};
