<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dictionary', function (Blueprint $table) {
            $table->timestamp('word_ranker_scan')->nullable()->after('scanned');
            $table->decimal('popularity', 5, 4)->nullable()->after('word_ranker_scan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dictionary', function (Blueprint $table) {
            $table->dropColumn(['word_ranker_scan', 'popularity']);
        });
    }
};
