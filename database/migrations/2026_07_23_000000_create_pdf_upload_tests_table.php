<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_upload_tests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('document_id')->unique()->constrained('documents')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('mime_type', 120)->default('application/pdf');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->longText('pdf_base64');
            $table->timestamps();

            $table->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_upload_tests');
    }
};
