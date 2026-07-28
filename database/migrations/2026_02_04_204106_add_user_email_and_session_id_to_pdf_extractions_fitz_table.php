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
        if (!Schema::hasColumn('pdf_extractions_fitz', 'user_email')) {
            Schema::table('pdf_extractions_fitz', function (Blueprint $table) {
                $table->string('user_email')->nullable()->after('document_id')->index();
            });
        }

        if (!Schema::hasColumn('pdf_extractions_fitz', 'session_id')) {
            Schema::table('pdf_extractions_fitz', function (Blueprint $table) {
                $table->string('session_id')->nullable()->after('user_email')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = array_values(array_filter(
            ['user_email', 'session_id'],
            static fn (string $column): bool => Schema::hasColumn('pdf_extractions_fitz', $column),
        ));

        if ($columns !== []) {
            Schema::table('pdf_extractions_fitz', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
