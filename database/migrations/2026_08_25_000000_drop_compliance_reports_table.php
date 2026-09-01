<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the compliance_reports table.
 *
 * The Admin "Run Tests" page and its Compliance Report resource were retired
 * under "[Cleanup] - Remove retired admin tools" (subtask 3). ComplianceController
 * was the table's only writer, so nothing populates or reads it any more.
 *
 * Not to be confused with the user-facing PDF/A export in the editor, which is
 * staying: that reports conformance straight back in its response and never
 * touched this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('compliance_reports');
    }

    /**
     * Recreates the table exactly as 2026_02_09_050000_create_compliance_reports_table
     * defined it, so this migration is reversible. The rows themselves are not
     * recoverable — the feature that produced them is gone.
     */
    public function down(): void
    {
        Schema::create('compliance_reports', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 36)->index();
            $table->string('filename');
            $table->text('description');
            $table->string('test_category');
            $table->string('section_name')->nullable();
            $table->string('status');
            $table->boolean('conversion_success')->default(false);
            $table->json('checks')->nullable();
            $table->integer('checks_passed')->default(0);
            $table->integer('checks_total')->default(0);
            $table->string('compliance_status')->nullable();
            $table->text('error')->nullable();
            $table->json('warnings')->nullable();
            $table->integer('file_size_input')->default(0);
            $table->integer('file_size_output')->default(0);
            $table->string('level', 4)->default('1b');
            $table->timestamps();

            $table->index('status');
            $table->index('test_category');
        });
    }
};
