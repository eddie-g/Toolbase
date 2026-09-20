<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which documents a visitor without an account may open. It replaces the
     * list of ids in the session payload, which grew without bound and died
     * with the 120-minute session, leaving its documents orphaned for good.
     * The visitor is a random token in an encrypted cookie; only its hash is
     * stored. App\Services\GuestDocuments.
     */
    public function up(): void
    {
        Schema::create('guest_documents', function (Blueprint $table) {
            $table->id();
            $table->char('guest_token_hash', 64);
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['guest_token_hash', 'document_id']);
            $table->index(['document_id']);
            $table->index(['last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_documents');
    }
};
