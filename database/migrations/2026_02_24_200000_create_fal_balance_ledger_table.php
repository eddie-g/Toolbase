<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fal_balance_ledger', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['credit', 'debit'])->index();
            $table->decimal('amount', 12, 6);           // positive for both types (direction comes from type)
            $table->decimal('balance_after', 12, 6);    // running balance snapshot after this entry
            $table->string('description')->nullable();
            $table->string('model', 100)->nullable();   // fal-ai/flux-2-flex, fal-ai/flux/schnell, etc.
            $table->unsignedBigInteger('logo_request_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fal_balance_ledger');
    }
};
