<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tests_overlay_editor', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 36)->index();
            $table->string('test_type')->default('extraction');  // extraction, shapes
            $table->string('filename');
            $table->text('description');
            $table->string('test_category');
            $table->string('section_name')->nullable();
            $table->string('status');                            // pass, fail, error
            $table->json('checks')->nullable();
            $table->integer('checks_passed')->default(0);
            $table->integer('checks_total')->default(0);
            $table->integer('page_count')->default(0);
            $table->integer('file_size')->default(0);
            $table->text('error')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('test_type');
            $table->index('test_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tests_overlay_editor');
    }
};
