<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('user_email')->nullable();
            $table->string('session_id')->index();
            $table->integer('page_number')->nullable()->index();
            $table->string('group_key');
            $table->string('root_source_key')->nullable();
            $table->string('group_type')->default('promoted_text');
            $table->json('group_bbox')->nullable();
            $table->json('annotation_ids')->nullable();
            $table->json('annotation_source_keys')->nullable();
            $table->json('group_data')->nullable();
            $table->string('state')->default('extracted');
            $table->timestamps();

            $table->index(['document_id', 'session_id']);
            $table->index(['document_id', 'state']);
            $table->index(['document_id', 'user_id']);
            $table->index(['document_id', 'admin_id']);
            $table->index(['document_id', 'group_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_groups');
    }
};
