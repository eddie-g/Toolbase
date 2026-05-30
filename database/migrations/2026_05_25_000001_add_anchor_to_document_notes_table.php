<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_notes', function (Blueprint $table) {
            $table->decimal('anchor_x', 8, 6)->nullable()->after('page_index');
            $table->decimal('anchor_y', 8, 6)->nullable()->after('anchor_x');

            $table->index(['document_id', 'page_index', 'anchor_x', 'anchor_y'], 'document_notes_anchor_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('document_notes', function (Blueprint $table) {
            $table->dropIndex('document_notes_anchor_lookup_index');
            $table->dropColumn(['anchor_x', 'anchor_y']);
        });
    }
};