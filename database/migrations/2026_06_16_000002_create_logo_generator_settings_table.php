<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logo_generator_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->json('settings');
            $table->timestamps();

            $table->unique('user_id');
            $table->unique('admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logo_generator_settings');
    }
};
