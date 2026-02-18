<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_rates', function (Blueprint $table) {
            $table->id();
            $table->string('model_name', 60); // e.g., 'dall-e-3'
            $table->string('model_variant', 40)->nullable(); // e.g., 'standard', 'hd'
            $table->string('resolution', 20)->nullable(); // e.g., '1024x1024', '1024x1792'
            $table->decimal('base_cost_usd', 10, 6); // OpenAI's actual cost
            $table->decimal('markup_percentage', 5, 2)->default(50.00); // 50% markup
            $table->decimal('user_cost_usd', 10, 6); // Final price charged to user
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['model_name', 'model_variant', 'resolution']);
            $table->index('model_name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rates');
    }
};
