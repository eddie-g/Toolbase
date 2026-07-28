<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_upload_test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdf_upload_test_id')
                ->constrained('pdf_upload_tests')
                ->cascadeOnDelete();
            $table->string('annotation_id');
            $table->string('runtime_annotation_id')->nullable();
            $table->unsignedInteger('page_index');
            $table->text('target_text')->nullable();
            $table->text('test_comment');
            $table->timestamp('test_saved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['pdf_upload_test_id', 'annotation_id'],
                'pdf_upload_test_cases_upload_annotation_unique'
            );
            $table->index(
                ['pdf_upload_test_id', 'test_saved_at'],
                'pdf_upload_test_cases_upload_saved_index'
            );
        });

        DB::table('pdf_upload_tests')
            ->whereNotNull('annotation_id')
            ->whereNotNull('test_comment')
            ->orderBy('id')
            ->chunkById(100, function ($fixtures) {
                foreach ($fixtures as $fixture) {
                    $annotationId = trim((string) $fixture->annotation_id);
                    $testComment = trim((string) $fixture->test_comment);
                    if ($annotationId === '' || $testComment === '') {
                        continue;
                    }

                    DB::table('pdf_upload_test_cases')->updateOrInsert(
                        [
                            'pdf_upload_test_id' => $fixture->id,
                            'annotation_id' => $annotationId,
                        ],
                        [
                            'runtime_annotation_id' => $fixture->runtime_annotation_id,
                            'page_index' => (int) ($fixture->page_index ?? 0),
                            'target_text' => $fixture->target_text,
                            'test_comment' => $testComment,
                            'test_saved_at' => $fixture->test_saved_at,
                            'created_at' => $fixture->created_at,
                            'updated_at' => $fixture->updated_at,
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_upload_test_cases');
    }
};
