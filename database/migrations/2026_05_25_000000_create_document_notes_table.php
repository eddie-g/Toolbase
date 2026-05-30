<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->unsignedInteger('page_index')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index(['document_id', 'user_id']);
            $table->index(['document_id', 'admin_id']);
            $table->index(['document_id', 'page_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_notes');
    }
};
