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
        if (!Schema::hasColumn('dictionary', 'word_ranker_scan')) {
            Schema::table('dictionary', function (Blueprint $table) {
                $column = $table->timestamp('word_ranker_scan')->nullable();
                if (Schema::hasColumn('dictionary', 'scanned')) {
                    $column->after('scanned');
                }
            });
        }

        if (!Schema::hasColumn('dictionary', 'popularity')) {
            Schema::table('dictionary', function (Blueprint $table) {
                $table->decimal('popularity', 5, 4)->nullable()->after('word_ranker_scan');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = array_values(array_filter(
            ['word_ranker_scan', 'popularity'],
            static fn (string $column): bool => Schema::hasColumn('dictionary', $column),
        ));

        if ($columns !== []) {
            Schema::table('dictionary', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
