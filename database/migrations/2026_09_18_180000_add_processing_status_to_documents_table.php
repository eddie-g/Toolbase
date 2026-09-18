<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an upload is in the extraction pipeline (queued, extracting,
     * ready, failed), so the editor can wait for it, and show a failure with a
     * retry instead of an editor that never fills. Null on documents created
     * before this column: they are treated as ready.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('processing_status', 16)->nullable()->index();
            $table->string('processing_error', 64)->nullable();
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->timestamp('processing_queued_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['processing_status']);
            $table->dropColumn([
                'processing_status',
                'processing_error',
                'processing_attempts',
                'processing_queued_at',
                'processing_started_at',
                'processing_finished_at',
            ]);
        });
    }
};
