<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_conversion_settings', function (Blueprint $table) {
            $table->id();
            $table->string('word_provider', 32)->default('local');
            $table->string('excel_provider', 32)->default('local');
            $table->boolean('fallback_to_local')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_conversion_settings');
    }
};
