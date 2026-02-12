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
        // Drop and recreate table with correct structure
        Schema::dropIfExists('ai_domain_requests');
        
        Schema::create('ai_domain_requests', function (Blueprint $table) {
            $table->id();
            $table->string('session')->nullable();
            $table->string('email')->nullable();
            $table->string('template')->nullable();
            $table->text('prompt');
            $table->json('response')->nullable();
            $table->string('model')->default('gemini-2.0-flash');
            $table->json('usage')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original structure
        Schema::dropIfExists('ai_domain_requests');
        
        Schema::create('ai_domain_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('prompt');
            $table->json('response')->nullable();
            $table->string('model')->nullable();
            $table->json('usage')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
        });
    }
};
