<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-scoped saved signatures (NK_Dev_4).
 *
 * The signature modal already had a per-browser library in localStorage.
 * This table backs the "Save signature" button, which stores a signature
 * against the signed-in account so it can be loaded on any browser.
 *
 * Ownership mirrors documents: web users land in user_id, admins in
 * admin_id, exactly one of which is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source_mode', 16)->default('draw');
            $table->longText('data_url');
            $table->json('composer')->nullable();
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_signatures');
    }
};
