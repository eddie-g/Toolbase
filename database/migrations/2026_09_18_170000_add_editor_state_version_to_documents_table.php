<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Counts the editor's full-state saves of a document. The editor sends the
     * version it loaded; a save against an older version is refused (409), so
     * a second tab cannot silently overwrite the first.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('editor_state_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('editor_state_version');
        });
    }
};
