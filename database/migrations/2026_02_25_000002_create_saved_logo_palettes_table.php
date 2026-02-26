<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_logo_palettes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('name', 60);
            $table->json('colors');
            $table->timestamps();

            $table->index('user_id');
            $table->index('admin_id');
            $table->unique(['user_id', 'name']);
            $table->unique(['admin_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_logo_palettes');
    }
};

