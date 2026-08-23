<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_conversions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('format', 16);
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('options');
            $table->json('quote');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->string('input_path');
            $table->string('output_path')->nullable();
            $table->string('download_name')->nullable();
            $table->string('content_type', 160)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['admin_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_conversions');
    }
};
