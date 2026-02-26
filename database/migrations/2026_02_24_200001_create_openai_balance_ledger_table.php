<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('openai_balance_ledger', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['credit', 'debit'])->index();
            $table->decimal('amount', 12, 6);
            $table->decimal('balance_after', 12, 6);
            $table->string('description')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedBigInteger('logo_request_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openai_balance_ledger');
    }
};
