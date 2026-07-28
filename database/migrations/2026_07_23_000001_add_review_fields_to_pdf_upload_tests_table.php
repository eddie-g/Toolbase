<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_upload_tests', function (Blueprint $table) {
            $table->string('annotation_id')->nullable()->after('pdf_base64');
            $table->string('runtime_annotation_id')->nullable()->after('annotation_id');
            $table->unsignedInteger('page_index')->nullable()->after('runtime_annotation_id');
            $table->text('target_text')->nullable()->after('page_index');
            $table->text('test_comment')->nullable()->after('target_text');
            $table->timestamp('test_saved_at')->nullable()->after('test_comment');

            $table->index(['admin_id', 'annotation_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pdf_upload_tests', function (Blueprint $table) {
            $table->dropIndex(['admin_id', 'annotation_id']);
            $table->dropColumn([
                'annotation_id',
                'runtime_annotation_id',
                'page_index',
                'target_text',
                'test_comment',
                'test_saved_at',
            ]);
        });
    }
};
