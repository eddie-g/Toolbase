<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guided_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');                    // "Clean Modern", "Bold Red"
            $table->string('slug')->unique();          // "default", "bold_red"  —  maps to Python style
            $table->string('description')->nullable(); // short blurb for the card
            $table->text('preview_html');              // SVG preview markup for the card
            $table->json('defaults')->nullable();      // default form values (company, customer, etc.)
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guided_templates');
    }
};
