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
        Schema::table('documents', function (Blueprint $table) {
            $table->string('template_type')->nullable(); // invoice, newsletter, etc.
            $table->string('template_slug')->nullable(); // modern, bold, etc.
            $table->json('form_data')->nullable();       // Start inputs and values
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['template_type', 'template_slug', 'form_data']);
        });
    }
};
