<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_pdf_monthly_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month_start');
            $table->unsignedInteger('uploads_count')->default(0);
            $table->unsignedInteger('actions_count')->default(0);
            $table->boolean('has_unlimited_actions')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'month_start']);
            $table->index(['month_start', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_pdf_monthly_usages');
    }
};
