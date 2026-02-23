<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_scores', function (Blueprint $table) {
            $table->id();
            $table->string('word', 50)->unique();
            $table->decimal('category_horror',  7, 6)->default(0)->index();
            $table->decimal('category_fantasy',  7, 6)->default(0)->index();
            $table->decimal('category_scifi',    7, 6)->default(0)->index();
            $table->decimal('category_tech',     7, 6)->default(0)->index();
            $table->decimal('category_romance',  7, 6)->default(0)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_scores');
    }
};
