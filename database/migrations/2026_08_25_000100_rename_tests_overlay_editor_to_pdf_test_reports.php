<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Renames tests_overlay_editor to pdf_test_reports.
 *
 * The table was shared by three writers. Two of them — the Run Overlay Tests
 * and Run Shape Tests admin pages — were retired under "[Cleanup] - Remove
 * retired admin tools" (subtasks 5 and 7), leaving PdfTestController as the
 * only one. The old name no longer describes anything in the codebase.
 *
 * A rename rather than a drop-and-recreate: every existing row is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tests_overlay_editor') && ! Schema::hasTable('pdf_test_reports')) {
            Schema::rename('tests_overlay_editor', 'pdf_test_reports');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pdf_test_reports') && ! Schema::hasTable('tests_overlay_editor')) {
            Schema::rename('pdf_test_reports', 'tests_overlay_editor');
        }
    }
};
