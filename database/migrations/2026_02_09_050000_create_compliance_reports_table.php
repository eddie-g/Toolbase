<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_reports', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 36)->index();          // Groups tests from a single run
            $table->string('filename');                       // e.g. isartor-6-1-2-t01-fail-a
            $table->text('description');                      // Human-readable test description
            $table->string('test_category');                  // e.g. 6.1.2
            $table->string('section_name')->nullable();       // e.g. File Header
            $table->string('status');                         // pass, fail, error
            $table->boolean('conversion_success')->default(false);
            $table->json('checks')->nullable();               // Array of check results
            $table->integer('checks_passed')->default(0);
            $table->integer('checks_total')->default(0);
            $table->string('compliance_status')->nullable();  // Compliant / Non-Compliant
            $table->text('error')->nullable();                // Error message if any
            $table->json('warnings')->nullable();             // Conversion warnings
            $table->integer('file_size_input')->default(0);
            $table->integer('file_size_output')->default(0);
            $table->string('level', 4)->default('1b');        // PDF/A level tested
            $table->timestamps();

            $table->index('status');
            $table->index('test_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_reports');
    }
};
