<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            $table->foreignId('pdf_extraction_fitz_id')
                ->nullable()
                ->after('document_id')
                ->constrained('pdf_extractions_fitz')
                ->nullOnDelete();
            $table->index(
                ['document_id', 'state', 'pdf_extraction_fitz_id'],
                'pdf_state_document_state_fitz_idx'
            );
        });

        Schema::table('pdf_groups', function (Blueprint $table) {
            $table->foreignId('pdf_extraction_fitz_id')
                ->nullable()
                ->after('document_id')
                ->constrained('pdf_extractions_fitz')
                ->nullOnDelete();
            $table->index(
                ['document_id', 'state', 'pdf_extraction_fitz_id'],
                'pdf_groups_document_state_fitz_idx'
            );
        });

        Schema::create('pdf_extraction_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdf_extraction_fitz_id')
                ->constrained('pdf_extractions_fitz')
                ->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('page_number');
            $table->double('width')->default(0);
            $table->double('height')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->longText('text')->nullable();
            $table->json('drawn_box_rects')->nullable();
            $table->json('widget_rects')->nullable();
            $table->timestamps();

            $table->unique(['pdf_extraction_fitz_id', 'page_number'], 'pdf_extract_pages_snapshot_page_uq');
            $table->index(['document_id', 'page_number'], 'pdf_extract_pages_document_page_idx');
        });

        Schema::create('pdf_extraction_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')
                ->constrained('pdf_extraction_pages')
                ->cascadeOnDelete();
            $table->foreignId('pdf_extraction_fitz_id')
                ->constrained('pdf_extractions_fitz')
                ->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('page_number');
            $table->integer('block_num');
            $table->string('source_key');
            $table->string('root_source_key');
            $table->longText('text')->nullable();
            $table->longText('text_single_line')->nullable();
            $table->json('text_lines')->nullable();
            $table->json('line_bboxes')->nullable();
            $table->double('left')->default(0);
            $table->double('top')->default(0);
            $table->double('width')->default(0);
            $table->double('height')->default(0);
            $table->json('bbox')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->string('font')->nullable();
            $table->integer('font_xref')->nullable();
            $table->double('font_size')->nullable();
            $table->string('font_weight')->nullable();
            $table->boolean('italic')->default(false);
            $table->boolean('underline')->default(false);
            $table->string('hex_color', 16)->nullable();
            $table->double('line_height')->nullable();
            $table->double('avg_line_height')->nullable();
            $table->double('rotation')->nullable();
            $table->json('direction')->nullable();
            $table->boolean('has_mixed_styles')->default(false);
            $table->json('block_data')->nullable();
            $table->timestamps();

            $table->unique(
                ['pdf_extraction_fitz_id', 'page_number', 'block_num'],
                'pdf_extract_blocks_snapshot_page_block_uq'
            );
            $table->index(['document_id', 'page_number'], 'pdf_extract_blocks_document_page_idx');
            $table->index(['pdf_extraction_fitz_id', 'root_source_key'], 'pdf_extract_blocks_snapshot_root_idx');
            $table->index(['document_id', 'source_key'], 'pdf_extract_blocks_document_source_idx');
        });

        Schema::create('pdf_extraction_spans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')
                ->constrained('pdf_extraction_pages')
                ->cascadeOnDelete();
            $table->foreignId('block_id')
                ->constrained('pdf_extraction_blocks')
                ->cascadeOnDelete();
            $table->foreignId('pdf_extraction_fitz_id')
                ->constrained('pdf_extractions_fitz')
                ->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('page_number');
            $table->integer('block_num');
            $table->integer('line_num')->nullable();
            $table->unsignedInteger('span_index')->default(0);
            $table->longText('text')->nullable();
            $table->longText('render_text')->nullable();
            $table->boolean('suppress_drawn_underline')->default(false);
            $table->boolean('has_drawn_underline')->default(false);
            $table->string('font')->nullable();
            $table->integer('font_xref')->nullable();
            $table->double('font_size')->nullable();
            $table->string('font_weight')->nullable();
            $table->string('color_value', 64)->nullable();
            $table->string('hex_color', 16)->nullable();
            $table->boolean('bold')->default(false);
            $table->boolean('italic')->default(false);
            $table->integer('flags')->nullable();
            $table->double('left')->default(0);
            $table->double('top')->default(0);
            $table->double('width')->default(0);
            $table->double('height')->default(0);
            $table->json('bbox')->nullable();
            $table->double('ascender')->nullable();
            $table->double('descender')->nullable();
            $table->json('origin')->nullable();
            $table->json('direction')->nullable();
            $table->integer('writing_mode')->default(0);
            $table->double('rotation')->nullable();
            $table->double('line_width')->nullable();
            $table->string('render_type', 32)->nullable();
            $table->double('space_width')->nullable();
            $table->boolean('uses_embedded_font')->default(false);
            $table->string('embedded_font_name')->nullable();
            $table->string('embedded_font_family')->nullable();
            $table->integer('embedded_font_xref')->nullable();
            $table->boolean('is_link')->default(false);
            $table->longText('link_uri')->nullable();
            $table->string('link_kind')->nullable();
            $table->integer('link_page')->nullable();
            $table->json('source_content_ops')->nullable();
            $table->json('span_data')->nullable();
            $table->timestamps();

            $table->index(
                ['pdf_extraction_fitz_id', 'page_number', 'block_num', 'line_num', 'span_index'],
                'pdf_extract_spans_snapshot_page_block_line_idx'
            );
            $table->index(['document_id', 'page_number', 'block_num'], 'pdf_extract_spans_document_page_block_idx');
            $table->index(['block_id', 'line_num'], 'pdf_extract_spans_block_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_extraction_spans');
        Schema::dropIfExists('pdf_extraction_blocks');
        Schema::dropIfExists('pdf_extraction_pages');

        Schema::table('pdf_groups', function (Blueprint $table) {
            $table->dropIndex('pdf_groups_document_state_fitz_idx');
            $table->dropConstrainedForeignId('pdf_extraction_fitz_id');
        });

        Schema::table('pdf_state', function (Blueprint $table) {
            $table->dropIndex('pdf_state_document_state_fitz_idx');
            $table->dropConstrainedForeignId('pdf_extraction_fitz_id');
        });
    }
};
