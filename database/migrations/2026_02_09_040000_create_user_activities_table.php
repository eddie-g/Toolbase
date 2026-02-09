<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action');           // e.g. "Export to JPG", "Convert to PDF/A-2b"
            $table->string('category');         // e.g. "image_export", "pdfa_export"
            $table->json('details')->nullable(); // extra info: format, dpi, pages, level, etc.
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');           // "success" or "failed"
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('category');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activities');
    }
};
