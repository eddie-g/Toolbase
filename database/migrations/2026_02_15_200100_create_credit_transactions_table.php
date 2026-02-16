<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['debit', 'credit', 'topup', 'refund'])->default('debit');
            $table->decimal('amount', 10, 6);
            $table->decimal('balance_after', 10, 4);
            $table->string('service')->index();          // e.g. 'logo_generation', 'bg_removal', 'vectorize'
            $table->string('model_name')->nullable();     // e.g. 'fal-ai/flux/schnell'
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();         // extra context (image_count, resolution, etc.)
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['service', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
