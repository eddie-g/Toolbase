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
        Schema::create('pdf_acro_form', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sess_id')->index();
            $table->unsignedInteger('page_num')->nullable();
            $table->json('data');
            $table->string('state')->default('saved')->index();
            $table->timestamps();

            $table->index(['document_id', 'sess_id']);
            $table->index(['document_id', 'page_num']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_acro_form');
    }
};
