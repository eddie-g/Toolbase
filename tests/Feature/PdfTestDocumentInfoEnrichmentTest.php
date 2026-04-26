<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\PdfExtractionBlock;
use App\Models\PdfExtractionFitz;
use App\Models\PdfExtractionPage;
use App\Models\PdfExtractionSpan;
use App\Models\PdfState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfTestDocumentInfoEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_info_prefers_canonical_fitz_block_geometry_for_promoted_annotations(): void
    {
        $user = User::factory()->create([
            'email' => 'doc2826-owner@example.com',
        ]);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'doc2826.pdf',
            'path' => 'documents/doc2826.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $fitz = PdfExtractionFitz::query()->create([
            'document_id' => $document->id,
            'session_id' => 'fitz-session',
            'pdf_filename' => 'doc2826.pdf',
            'total_pages' => 1,
            'total_words' => 10,
            'full_text' => 'sample',
            'extraction_data' => [],
        ]);

        $page = PdfExtractionPage::query()->create([
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'width' => 612,
            'height' => 792,
            'word_count' => 10,
            'text' => 'sample',
            'drawn_box_rects' => [],
            'widget_rects' => [],
        ]);

        PdfExtractionBlock::query()->create([
            'page_id' => $page->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 12,
            'source_key' => 'block-1-12',
            'root_source_key' => 'block-1-12',
            'text' => "First line\nSecond line",
            'text_single_line' => 'First line Second line',
            'text_lines' => ['First line', 'Second line'],
            'line_bboxes' => [
                [45.396, 543.272, 456.156, 552.279],
                [64.854, 554.072, 446.517, 563.290],
            ],
            'left' => 45.396,
            'top' => 543.272,
            'width' => 410.760,
            'height' => 20.018,
            'line_count' => 2,
            'font' => 'HelveticaNeueLTStd-Roman',
            'font_size' => 9,
            'font_weight' => '400',
            'italic' => false,
            'underline' => false,
            'hex_color' => '#000000',
            'line_height' => 9.113,
            'avg_line_height' => 9.113,
            'has_mixed_styles' => true,
            'block_data' => [],
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => null,
            'user_id' => $user->id,
            'session_id' => 'state-session',
            'page_number' => 0,
            'state' => 'not_saved',
            'flagged' => true,
            'annotation_data' => [
                'id' => 'promoted_1_12',
                'type' => 'text',
                'text' => "First line\nSecond line",
                'pageIndex' => 0,
                'pdfX' => 99,
                'pdfY' => 248.7284,
                'pdfWidth' => 0,
                'pdfHeight' => 20.0074,
                'fontSize' => 9,
                'fontFamily' => 'HelveticaNeueLTStd-Roman',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#000000',
                'lineHeight' => 10.65,
                'promotedFromExtraction' => true,
                'promotedDirty' => true,
                'promotedSourceKey' => 'block-1-12',
                'promotedSourceBlockNum' => 12,
                'sourceBlockLeft' => 99,
                'sourceBlockTop' => 500,
                'sourceBlockWidth' => 0,
                'sourceBlockHeight' => 20,
                'sourcePageHeight' => 792,
                'sourceTextLines' => ['First line', 'Second line'],
                'sourceLineBBoxes' => [
                    [99, 500, 199, 510],
                    [99, 511, 199, 521],
                ],
                'sourceSpans' => [],
            ],
        ]);

        $response = $this->actingAs($user)->getJson(route('pdfTests.documentInfo', $document));
        $response->assertOk()->assertJson(['success' => true]);

        $annotation = collect($response->json('annotations'))
            ->firstWhere('id', 'promoted_1_12');

        $this->assertNotNull($annotation);
        $this->assertSame(45.396, round((float) $annotation['pdfX'], 3));
        $this->assertSame(410.760, round((float) $annotation['pdfWidth'], 3));
        $this->assertSame(20.018, round((float) $annotation['pdfHeight'], 3));
        $this->assertSame(228.710, round((float) $annotation['pdfY'], 3));
        $this->assertSame([45.396, 543.272, 456.156, 552.279], array_map(
            static fn ($value) => round((float) $value, 3),
            $annotation['sourceLineBBoxes'][0]
        ));
    }

    public function test_document_info_synthesizes_visual_lines_from_spans_when_saved_line_metadata_is_flattened(): void
    {
        $user = User::factory()->create([
            'email' => 'doc2831-owner@example.com',
        ]);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'doc2831.pdf',
            'path' => 'documents/doc2831.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $fitz = PdfExtractionFitz::query()->create([
            'document_id' => $document->id,
            'session_id' => 'fitz-session',
            'pdf_filename' => 'doc2831.pdf',
            'total_pages' => 1,
            'total_words' => 18,
            'full_text' => 'sample',
            'extraction_data' => [],
        ]);

        $page = PdfExtractionPage::query()->create([
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'width' => 612,
            'height' => 792,
            'word_count' => 18,
            'text' => 'sample',
            'drawn_box_rects' => [],
            'widget_rects' => [],
        ]);

        $block = PdfExtractionBlock::query()->create([
            'page_id' => $page->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 14,
            'source_key' => 'block-1-14',
            'root_source_key' => 'block-1-14',
            'text' => "3 Acts authorized (you are required to complete line 3).\nExcept for the acts described in line 5b, I authorize my representative(s) to receive and inspect my confidential tax information.",
            'text_single_line' => '3 Acts authorized (you are required to complete line 3). Except for the acts described in line 5b, I authorize my representative(s) to receive and inspect my confidential tax information.',
            'text_lines' => [
                '3 Acts authorized (you are required to complete line 3). Except for the acts described in line 5b, I authorize my representative(s) to receive and inspect my confidential tax information.',
            ],
            'line_bboxes' => [
                [33.120, 544.090, 544.790, 553.870],
            ],
            'left' => 33.120,
            'top' => 544.090,
            'width' => 511.670,
            'height' => 19.780,
            'line_count' => 1,
            'font' => 'HelveticaNeueLTStd-Roman',
            'font_size' => 8.64,
            'font_weight' => '400',
            'italic' => false,
            'underline' => false,
            'hex_color' => '#000000',
            'line_height' => 9.78,
            'avg_line_height' => 9.78,
            'has_mixed_styles' => true,
            'block_data' => [],
        ]);

        PdfExtractionSpan::query()->create([
            'page_id' => $page->id,
            'block_id' => $block->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 14,
            'line_num' => 0,
            'span_index' => 0,
            'text' => '3 ',
            'render_text' => '3 ',
            'font' => 'HelveticaNeueLTStd-Bd',
            'font_size' => 8.64,
            'font_weight' => '700',
            'hex_color' => '#000000',
            'bold' => true,
            'italic' => false,
            'left' => 33.120,
            'top' => 544.090,
            'width' => 9.320,
            'height' => 9.780,
            'bbox' => [33.120, 544.090, 42.440, 553.870],
            'origin' => [33.120, 552.010],
            'direction' => [1.0, 0.0],
            'writing_mode' => 0,
        ]);
        PdfExtractionSpan::query()->create([
            'page_id' => $page->id,
            'block_id' => $block->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 14,
            'line_num' => 0,
            'span_index' => 1,
            'text' => 'Acts authorized (you are required to complete line 3).',
            'render_text' => 'Acts authorized (you are required to complete line 3).',
            'font' => 'HelveticaNeueLTStd-Bd',
            'font_size' => 8.64,
            'font_weight' => '700',
            'hex_color' => '#000000',
            'bold' => true,
            'italic' => false,
            'left' => 61.210,
            'top' => 544.090,
            'width' => 244.500,
            'height' => 9.780,
            'bbox' => [61.210, 544.090, 305.710, 553.870],
            'origin' => [61.210, 552.010],
            'direction' => [1.0, 0.0],
            'writing_mode' => 0,
        ]);
        PdfExtractionSpan::query()->create([
            'page_id' => $page->id,
            'block_id' => $block->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 14,
            'line_num' => 1,
            'span_index' => 0,
            'text' => 'Except for the acts described in line 5b, I authorize my representative(s) to receive and inspect my confidential tax information.',
            'render_text' => 'Except for the acts described in line 5b, I authorize my representative(s) to receive and inspect my confidential tax information.',
            'font' => 'HelveticaNeueLTStd-Roman',
            'font_size' => 8.64,
            'font_weight' => '400',
            'hex_color' => '#000000',
            'bold' => false,
            'italic' => false,
            'left' => 61.210,
            'top' => 554.890,
            'width' => 483.580,
            'height' => 9.780,
            'bbox' => [61.210, 554.890, 544.790, 564.670],
            'origin' => [61.210, 562.810],
            'direction' => [1.0, 0.0],
            'writing_mode' => 0,
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => null,
            'user_id' => $user->id,
            'session_id' => 'state-session',
            'page_number' => 0,
            'state' => 'not_saved',
            'annotation_data' => [
                'id' => 'promoted_1_14',
                'type' => 'text',
                'text' => "3 Acts authorized (you are required to complete line 3).\nExcept for the acts described in line 5b, I authorize my representative(s) to receive and inspect my confidential tax information.",
                'pageIndex' => 0,
                'pdfX' => 33.120,
                'pdfY' => 227.330,
                'pdfWidth' => 511.670,
                'pdfHeight' => 19.780,
                'fontSize' => 8.64,
                'fontFamily' => 'HelveticaNeueLTStd-Roman',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#000000',
                'lineHeight' => 9.78,
                'promotedFromExtraction' => true,
                'promotedDirty' => false,
                'promotedSourceKey' => 'block-1-14',
                'promotedSourceBlockNum' => 14,
                'sourceTextLines' => [
                    '3 Acts authorized (you are required to complete line 3). Except for the acts described in line 5b, I authorize my representative(s) to receive and inspect my confidential tax information.',
                ],
                'sourceLineBBoxes' => [
                    [33.120, 544.090, 544.790, 553.870],
                ],
                'sourceSpans' => [
                    [
                        'text' => '3 ',
                        'render_text' => '3 ',
                        'origin' => [33.120, 552.010],
                        'bbox' => [33.120, 544.090, 42.440, 553.870],
                    ],
                    [
                        'text' => 'Acts authorized (you are required to complete line 3).',
                        'render_text' => 'Acts authorized (you are required to complete line 3).',
                        'origin' => [61.210, 552.010],
                        'bbox' => [61.210, 544.090, 305.710, 553.870],
                    ],
                    [
                        'text' => 'Except for the acts described in line 5b, I authorize my representative(s) to receive and inspect my confidential tax information.',
                        'render_text' => 'Except for the acts described in line 5b, I authorize my representative(s) to receive and inspect my confidential tax information.',
                        'origin' => [61.210, 562.810],
                        'bbox' => [61.210, 554.890, 544.790, 564.670],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->getJson(route('pdfTests.documentInfo', $document));
        $response->assertOk()->assertJson(['success' => true]);

        $annotation = collect($response->json('annotations'))
            ->firstWhere('id', 'promoted_1_14');

        $this->assertNotNull($annotation);
        $this->assertCount(2, $annotation['sourceTextLines']);
        $this->assertCount(2, $annotation['sourceLineBBoxes']);
        $this->assertSame(
            '3 Acts authorized (you are required to complete line 3).',
            $annotation['sourceTextLines'][0]
        );
        $this->assertSame(
            'Except for the acts described in line 5b, I authorize my representative(s) to receive and inspect my confidential tax information.',
            $annotation['sourceTextLines'][1]
        );
        $this->assertSame([33.120, 544.090, 305.710, 553.870], array_map(
            static fn ($value) => round((float) $value, 3),
            $annotation['sourceLineBBoxes'][0]
        ));
        $this->assertSame([61.210, 554.890, 544.790, 564.670], array_map(
            static fn ($value) => round((float) $value, 3),
            $annotation['sourceLineBBoxes'][1]
        ));
    }

    public function test_document_info_preserves_child_split_line_metadata_for_derived_source_keys(): void
    {
        $user = User::factory()->create([
            'email' => 'doc2826-owner@example.com',
        ]);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'doc2826.pdf',
            'path' => 'documents/doc2826.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $fitz = PdfExtractionFitz::query()->create([
            'document_id' => $document->id,
            'session_id' => 'fitz-session',
            'pdf_filename' => 'doc2826.pdf',
            'total_pages' => 1,
            'total_words' => 24,
            'full_text' => 'sample',
            'extraction_data' => [],
        ]);

        $page = PdfExtractionPage::query()->create([
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'width' => 612,
            'height' => 792,
            'word_count' => 24,
            'text' => 'sample',
            'drawn_box_rects' => [],
            'widget_rects' => [],
        ]);

        $block = PdfExtractionBlock::query()->create([
            'page_id' => $page->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 11,
            'source_key' => 'block-1-11',
            'root_source_key' => 'block-1-11',
            'text' => "4   • For 2018: Enter the total of the amounts on your 2018 Schedule 1\n(Form 1040), lines 23 through 33, plus any write-in adjustments you\nentered on the dotted line next to Schedule 1 (Form 1040), line 36.\n• For 2019 and 2020: Enter the total of the amounts on your 2019",
            'text_single_line' => '4 • For 2018: Enter the total of the amounts on your 2018 Schedule 1 (Form 1040), lines 23 through 33, plus any write-in adjustments you entered on the dotted line next to Schedule 1 (Form 1040), line 36. • For 2019 and 2020: Enter the total of the amounts on your 2019',
            'text_lines' => [
                '4   • For 2018: Enter the total of the amounts on your 2018 Schedule 1',
                '(Form 1040), lines 23 through 33, plus any write-in adjustments you',
                'entered on the dotted line next to Schedule 1 (Form 1040), line 36.',
                '• For 2019 and 2020: Enter the total of the amounts on your 2019',
            ],
            'line_bboxes' => [
                [45.396, 424.472, 337.734, 444.272],
                [64.800, 435.279, 337.725, 444.279],
                [64.800, 446.079, 331.056, 455.079],
                [64.800, 459.678, 330.246, 468.678],
            ],
            'left' => 45.396,
            'top' => 424.472,
            'width' => 292.338,
            'height' => 44.206,
            'line_count' => 4,
            'font' => 'HelveticaNeueLTStd-Roman',
            'font_size' => 9,
            'font_weight' => '400',
            'italic' => false,
            'underline' => false,
            'hex_color' => '#000000',
            'line_height' => 9,
            'avg_line_height' => 9,
            'has_mixed_styles' => true,
            'block_data' => [],
        ]);

        PdfExtractionSpan::query()->create([
            'page_id' => $page->id,
            'block_id' => $block->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 11,
            'line_num' => 0,
            'span_index' => 0,
            'text' => '4  ',
            'render_text' => '4  ',
            'font' => 'HelveticaNeueLTStd-Bd',
            'font_size' => 9,
            'font_weight' => '700',
            'hex_color' => '#000000',
            'bold' => true,
            'italic' => false,
            'left' => 45.396,
            'top' => 424.472,
            'width' => 7.506,
            'height' => 19.800,
            'bbox' => [45.396, 424.472, 52.902, 444.272],
            'origin' => [45.396, 431.827],
            'direction' => [1.0, 0.0],
            'writing_mode' => 0,
        ]);
        PdfExtractionSpan::query()->create([
            'page_id' => $page->id,
            'block_id' => $block->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 11,
            'line_num' => 0,
            'span_index' => 1,
            'text' => '• For 2018: Enter the total of the amounts on your 2018 Schedule 1 ',
            'render_text' => '• For 2018: Enter the total of the amounts on your 2018 Schedule 1 ',
            'font' => 'HelveticaNeueLTStd-Roman',
            'font_size' => 9,
            'font_weight' => '400',
            'hex_color' => '#000000',
            'bold' => false,
            'italic' => false,
            'left' => 64.800,
            'top' => 424.479,
            'width' => 272.934,
            'height' => 9,
            'bbox' => [64.800, 424.479, 337.734, 433.479],
            'origin' => [64.800, 431.827],
            'direction' => [1.0, 0.0],
            'writing_mode' => 0,
        ]);
        PdfExtractionSpan::query()->create([
            'page_id' => $page->id,
            'block_id' => $block->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 11,
            'line_num' => 1,
            'span_index' => 0,
            'text' => '(Form 1040), lines 23 through 33, plus any write-in adjustments you ',
            'render_text' => '(Form 1040), lines 23 through 33, plus any write-in adjustments you ',
            'font' => 'HelveticaNeueLTStd-Roman',
            'font_size' => 9,
            'font_weight' => '400',
            'hex_color' => '#000000',
            'bold' => false,
            'italic' => false,
            'left' => 64.800,
            'top' => 435.279,
            'width' => 272.925,
            'height' => 9,
            'bbox' => [64.800, 435.279, 337.725, 444.279],
            'origin' => [64.800, 442.627],
            'direction' => [1.0, 0.0],
            'writing_mode' => 0,
        ]);
        PdfExtractionSpan::query()->create([
            'page_id' => $page->id,
            'block_id' => $block->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 11,
            'line_num' => 2,
            'span_index' => 0,
            'text' => 'entered on the dotted line next to Schedule 1 (Form 1040), line 36.',
            'render_text' => 'entered on the dotted line next to Schedule 1 (Form 1040), line 36.',
            'font' => 'HelveticaNeueLTStd-Roman',
            'font_size' => 9,
            'font_weight' => '400',
            'hex_color' => '#000000',
            'bold' => false,
            'italic' => false,
            'left' => 64.800,
            'top' => 446.079,
            'width' => 266.256,
            'height' => 9,
            'bbox' => [64.800, 446.079, 331.056, 455.079],
            'origin' => [64.800, 453.427],
            'direction' => [1.0, 0.0],
            'writing_mode' => 0,
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => null,
            'user_id' => $user->id,
            'session_id' => 'state-session',
            'page_number' => 0,
            'state' => 'not_saved',
            'annotation_data' => [
                'id' => 'promoted_1_11_inline-bullet-lines-0-0-2',
                'type' => 'text',
                'text' => "4   • For 2018: Enter the total of the amounts on your 2018 Schedule 1\n(Form 1040), lines 23 through 33, plus any write-in adjustments you\nentered on the dotted line next to Schedule 1 (Form 1040), line 36.",
                'pageIndex' => 0,
                'pdfX' => 45.396,
                'pdfY' => 336.921,
                'pdfWidth' => 292.338,
                'pdfHeight' => 30.607,
                'fontSize' => 9,
                'fontFamily' => 'HelveticaNeueLTStd-Roman',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#000000',
                'lineHeight' => 9,
                'promotedFromExtraction' => true,
                'promotedDirty' => false,
                'promotedSourceKey' => 'block-1-11-inline-bullet-lines-0-0-2',
                'promotedSourceBlockNum' => 11,
                'sourceBlockLeft' => 45.396,
                'sourceBlockTop' => 424.472,
                'sourceBlockWidth' => 292.338,
                'sourceBlockHeight' => 30.607,
                'sourcePageHeight' => 792,
                'sourceTextLines' => [
                    '4   • For 2018: Enter the total of the amounts on your 2018 Schedule 1',
                    '(Form 1040), lines 23 through 33, plus any write-in adjustments you',
                    'entered on the dotted line next to Schedule 1 (Form 1040), line 36.',
                ],
                'sourceLineBBoxes' => [
                    [45.396, 424.472, 337.734, 444.272],
                    [64.800, 435.279, 337.725, 444.279],
                    [64.800, 446.079, 331.056, 455.079],
                ],
                'sourceSpans' => [
                    [
                        'text' => '4  ',
                        'render_text' => '4  ',
                        'origin' => [45.396, 431.827],
                        'bbox' => [45.396, 424.472, 52.902, 444.272],
                    ],
                    [
                        'text' => '• For 2018: Enter the total of the amounts on your 2018 Schedule 1 ',
                        'render_text' => '• For 2018: Enter the total of the amounts on your 2018 Schedule 1 ',
                        'origin' => [64.800, 431.827],
                        'bbox' => [64.800, 424.479, 337.734, 433.479],
                    ],
                    [
                        'text' => '(Form 1040), lines 23 through 33, plus any write-in adjustments you ',
                        'render_text' => '(Form 1040), lines 23 through 33, plus any write-in adjustments you ',
                        'origin' => [64.800, 442.627],
                        'bbox' => [64.800, 435.279, 337.725, 444.279],
                    ],
                    [
                        'text' => 'entered on the dotted line next to Schedule 1 (Form 1040), line 36.',
                        'render_text' => 'entered on the dotted line next to Schedule 1 (Form 1040), line 36.',
                        'origin' => [64.800, 453.427],
                        'bbox' => [64.800, 446.079, 331.056, 455.079],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->getJson(route('pdfTests.documentInfo', $document));
        $response->assertOk()->assertJson(['success' => true]);

        $annotation = collect($response->json('annotations'))
            ->firstWhere('id', 'promoted_1_11_inline-bullet-lines-0-0-2');

        $this->assertNotNull($annotation);
        $this->assertCount(3, $annotation['sourceTextLines']);
        $this->assertCount(3, $annotation['sourceLineBBoxes']);
        $this->assertSame(45.396, round((float) $annotation['pdfX'], 3));
        $this->assertSame(30.607, round((float) $annotation['pdfHeight'], 3));
        $this->assertSame(
            'entered on the dotted line next to Schedule 1 (Form 1040), line 36.',
            $annotation['sourceTextLines'][2]
        );
    }

    public function test_document_info_merges_contained_single_line_promoted_annotation_into_parent(): void
    {
        $user = User::factory()->create([
            'email' => 'doc2995-owner@example.com',
        ]);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'doc2995.pdf',
            'path' => 'documents/doc2995.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $fitz = PdfExtractionFitz::query()->create([
            'document_id' => $document->id,
            'session_id' => 'fitz-session',
            'pdf_filename' => 'doc2995.pdf',
            'total_pages' => 1,
            'total_words' => 20,
            'full_text' => 'sample',
            'extraction_data' => [],
        ]);

        $page = PdfExtractionPage::query()->create([
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'width' => 612,
            'height' => 792,
            'word_count' => 20,
            'text' => 'sample',
            'drawn_box_rects' => [],
            'widget_rects' => [],
        ]);

        PdfExtractionBlock::query()->create([
            'page_id' => $page->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 4,
            'source_key' => 'block-1-4',
            'root_source_key' => 'block-1-4',
            'text' => "Use Form 1040-ES to figure and pay your estimated tax\nfor 2026.\nthat isn’t subject to withholding",
            'text_single_line' => 'Use Form 1040-ES to figure and pay your estimated tax for 2026. that isn’t subject to withholding',
            'text_lines' => [
                'Use Form 1040-ES to figure and pay your estimated tax ',
                'for 2026.',
                'that isn’t subject to withholding',
            ],
            'line_bboxes' => [
                [42.002, 132.542, 286.953, 142.542],
                [42.002, 143.742, 80.880, 153.742],
                [42.002, 172.142, 278.743, 182.142],
            ],
            'left' => 42.002,
            'top' => 132.542,
            'width' => 254.301,
            'height' => 49.600,
            'line_count' => 3,
            'font' => 'HelveticaWorld-Regular',
            'font_size' => 10,
            'font_weight' => '400',
            'italic' => false,
            'underline' => false,
            'hex_color' => '#231F20',
            'line_height' => 10,
            'avg_line_height' => 10,
            'has_mixed_styles' => false,
            'block_data' => [],
        ]);

        PdfExtractionBlock::query()->create([
            'page_id' => $page->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 19,
            'source_key' => 'block-1-19',
            'root_source_key' => 'block-1-19',
            'text' => 'Estimated tax is the method used to pay tax on income',
            'text_single_line' => 'Estimated tax is the method used to pay tax on income',
            'text_lines' => [
                'Estimated tax is the method used to pay tax on income',
            ],
            'line_bboxes' => [
                [54.002, 160.942, 295.413, 170.942],
            ],
            'left' => 54.002,
            'top' => 160.942,
            'width' => 241.411,
            'height' => 10,
            'line_count' => 1,
            'font' => 'HelveticaWorld-Regular',
            'font_size' => 10,
            'font_weight' => '400',
            'italic' => false,
            'underline' => false,
            'hex_color' => '#231F20',
            'line_height' => 10,
            'avg_line_height' => 10,
            'has_mixed_styles' => false,
            'block_data' => [],
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => null,
            'user_id' => $user->id,
            'session_id' => 'state-session',
            'page_number' => 0,
            'state' => 'not_saved',
            'annotation_data' => [
                'id' => 'promoted_1_4',
                'type' => 'text',
                'text' => "Use Form 1040-ES to figure and pay your estimated tax\nfor 2026.\nthat isn’t subject to withholding",
                'pageIndex' => 0,
                'pdfX' => 42.002,
                'pdfY' => 609.858,
                'pdfWidth' => 254.301,
                'pdfHeight' => 49.600,
                'fontSize' => 10,
                'fontFamily' => 'HelveticaWorld',
                'fontSourceName' => 'HelveticaWorld-Regular',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#231F20',
                'lineHeight' => 10,
                'promotedFromExtraction' => true,
                'promotedDirty' => false,
                'promotedSourceKey' => 'block-1-4',
                'promotedSourceBlockNum' => 4,
                'sourceBlockLeft' => 42.002,
                'sourceBlockTop' => 132.542,
                'sourceBlockWidth' => 254.301,
                'sourceBlockHeight' => 49.600,
                'sourcePageHeight' => 792,
                'sourceTextLines' => [
                    'Use Form 1040-ES to figure and pay your estimated tax ',
                    'for 2026.',
                    'that isn’t subject to withholding',
                ],
                'sourceLineBBoxes' => [
                    [42.002, 132.542, 286.953, 142.542],
                    [42.002, 143.742, 80.880, 153.742],
                    [42.002, 172.142, 278.743, 182.142],
                ],
                'sourceSpans' => [
                    [
                        'text' => 'Use Form 1040-ES to figure and pay your estimated tax ',
                        'render_text' => 'Use Form 1040-ES to figure and pay your estimated tax ',
                        'origin' => [42.002, 140.736],
                        'bbox' => [42.002, 132.542, 286.953, 142.542],
                    ],
                    [
                        'text' => 'for 2026.',
                        'render_text' => 'for 2026.',
                        'origin' => [42.002, 151.936],
                        'bbox' => [42.002, 143.742, 80.880, 153.742],
                    ],
                    [
                        'text' => 'that isn’t subject to withholding',
                        'render_text' => 'that isn’t subject to withholding',
                        'origin' => [42.002, 180.336],
                        'bbox' => [42.002, 172.142, 278.743, 182.142],
                    ],
                ],
            ],
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => null,
            'user_id' => $user->id,
            'session_id' => 'state-session',
            'page_number' => 0,
            'state' => 'not_saved',
            'annotation_data' => [
                'id' => 'promoted_1_19',
                'type' => 'text',
                'text' => 'Estimated tax is the method used to pay tax on income',
                'pageIndex' => 0,
                'pdfX' => 54.002,
                'pdfY' => 621.058,
                'pdfWidth' => 241.411,
                'pdfHeight' => 10,
                'fontSize' => 10,
                'fontFamily' => 'HelveticaWorld',
                'fontSourceName' => 'HelveticaWorld-Regular',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#231F20',
                'lineHeight' => 10,
                'promotedFromExtraction' => true,
                'promotedDirty' => false,
                'promotedSourceKey' => 'block-1-19',
                'promotedSourceBlockNum' => 19,
                'sourceBlockLeft' => 54.002,
                'sourceBlockTop' => 160.942,
                'sourceBlockWidth' => 241.411,
                'sourceBlockHeight' => 10,
                'sourcePageHeight' => 792,
                'sourceTextLines' => [
                    'Estimated tax is the method used to pay tax on income',
                ],
                'sourceLineBBoxes' => [
                    [54.002, 160.942, 295.413, 170.942],
                ],
                'sourceSpans' => [
                    [
                        'text' => 'Estimated tax is the method used to pay tax on income',
                        'render_text' => 'Estimated tax is the method used to pay tax on income',
                        'origin' => [54.002, 169.136],
                        'bbox' => [54.002, 160.942, 295.413, 170.942],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->getJson(route('pdfTests.documentInfo', $document, [
            'session_id' => 'state-session',
        ]));
        $response->assertOk()->assertJson(['success' => true]);

        $annotations = collect($response->json('annotations'));
        $this->assertNull($annotations->firstWhere('id', 'promoted_1_19'));

        $annotation = $annotations->firstWhere('id', 'promoted_1_4');
        $this->assertNotNull($annotation);
        $this->assertSame([
            'Use Form 1040-ES to figure and pay your estimated tax ',
            'for 2026.',
            'Estimated tax is the method used to pay tax on income',
            'that isn’t subject to withholding',
        ], $annotation['sourceTextLines']);
        $this->assertCount(4, $annotation['sourceLineBBoxes']);
        $this->assertSame([54.002, 160.942, 295.413, 170.942], array_map(
            static fn ($value) => round((float) $value, 3),
            $annotation['sourceLineBBoxes'][2]
        ));
    }

    public function test_document_info_merges_gap_fit_promoted_annotation_even_when_child_is_wider_than_parent_bbox(): void
    {
        $user = User::factory()->create([
            'email' => 'doc2995-gap-owner@example.com',
        ]);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'doc2995-gap.pdf',
            'path' => 'documents/doc2995-gap.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $fitz = PdfExtractionFitz::query()->create([
            'document_id' => $document->id,
            'session_id' => 'fitz-session',
            'pdf_filename' => 'doc2995-gap.pdf',
            'total_pages' => 1,
            'total_words' => 12,
            'full_text' => 'sample',
            'extraction_data' => [],
        ]);

        $page = PdfExtractionPage::query()->create([
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'width' => 612,
            'height' => 792,
            'word_count' => 12,
            'text' => 'sample',
            'drawn_box_rects' => [],
            'widget_rects' => [],
        ]);

        PdfExtractionBlock::query()->create([
            'page_id' => $page->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 14,
            'source_key' => 'block-1-14',
            'root_source_key' => 'block-1-14',
            'text' => "or\n2025 tax return must cover all 12 months.",
            'text_single_line' => 'or 2025 tax return must cover all 12 months.',
            'text_lines' => [
                'or',
                '2025 tax return must cover all 12 months.',
            ],
            'line_bboxes' => [
                [42.000, 637.742, 50.842, 647.742],
                [42.000, 663.142, 222.303, 673.142],
            ],
            'left' => 42.000,
            'top' => 637.742,
            'width' => 180.303,
            'height' => 35.400,
            'line_count' => 2,
            'font' => 'HelveticaWorld-Regular',
            'font_size' => 10,
            'font_weight' => '400',
            'italic' => false,
            'underline' => false,
            'hex_color' => '#231F20',
            'line_height' => 10,
            'avg_line_height' => 10,
            'has_mixed_styles' => false,
            'block_data' => [],
        ]);

        PdfExtractionBlock::query()->create([
            'page_id' => $page->id,
            'pdf_extraction_fitz_id' => $fitz->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'block_num' => 23,
            'source_key' => 'block-1-23',
            'root_source_key' => 'block-1-23',
            'text' => 'b. 100% of the tax shown on your 2025 tax return. Your',
            'text_single_line' => 'b. 100% of the tax shown on your 2025 tax return. Your',
            'text_lines' => [
                'b. 100% of the tax shown on your 2025 tax return. Your',
            ],
            'line_bboxes' => [
                [54.000, 652.856, 299.315, 662.275],
            ],
            'left' => 54.000,
            'top' => 652.856,
            'width' => 245.315,
            'height' => 9.419,
            'line_count' => 1,
            'font' => 'HelveticaWorld-Regular',
            'font_size' => 10,
            'font_weight' => '400',
            'italic' => false,
            'underline' => false,
            'hex_color' => '#231F20',
            'line_height' => 10,
            'avg_line_height' => 10,
            'has_mixed_styles' => false,
            'block_data' => [],
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => null,
            'user_id' => $user->id,
            'session_id' => 'state-session',
            'page_number' => 0,
            'state' => 'not_saved',
            'annotation_data' => [
                'id' => 'promoted_1_14',
                'type' => 'text',
                'text' => "or\n2025 tax return must cover all 12 months.",
                'pageIndex' => 0,
                'pdfX' => 42.000,
                'pdfY' => 118.858,
                'pdfWidth' => 180.303,
                'pdfHeight' => 35.400,
                'fontSize' => 10,
                'fontFamily' => 'HelveticaWorld',
                'fontSourceName' => 'HelveticaWorld-Regular',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#231F20',
                'lineHeight' => 10,
                'promotedFromExtraction' => true,
                'promotedDirty' => false,
                'promotedSourceKey' => 'block-1-14',
                'promotedSourceBlockNum' => 14,
                'sourceBlockLeft' => 42.000,
                'sourceBlockTop' => 637.742,
                'sourceBlockWidth' => 180.303,
                'sourceBlockHeight' => 35.400,
                'sourcePageHeight' => 792,
                'sourceTextLines' => [
                    'or',
                    '2025 tax return must cover all 12 months.',
                ],
                'sourceLineBBoxes' => [
                    [42.000, 637.742, 50.842, 647.742],
                    [42.000, 663.142, 222.303, 673.142],
                ],
                'sourceSpans' => [
                    [
                        'text' => 'or',
                        'render_text' => 'or',
                        'origin' => [42.000, 645.100],
                        'bbox' => [42.000, 637.742, 50.842, 647.742],
                    ],
                    [
                        'text' => '2025 tax return must cover all 12 months.',
                        'render_text' => '2025 tax return must cover all 12 months.',
                        'origin' => [42.000, 670.500],
                        'bbox' => [42.000, 663.142, 222.303, 673.142],
                    ],
                ],
            ],
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => null,
            'user_id' => $user->id,
            'session_id' => 'state-session',
            'page_number' => 0,
            'state' => 'not_saved',
            'annotation_data' => [
                'id' => 'promoted_1_23',
                'type' => 'text',
                'text' => 'b. 100% of the tax shown on your 2025 tax return. Your',
                'pageIndex' => 0,
                'pdfX' => 54.000,
                'pdfY' => 129.725,
                'pdfWidth' => 245.315,
                'pdfHeight' => 9.419,
                'fontSize' => 10,
                'fontFamily' => 'HelveticaWorld',
                'fontSourceName' => 'HelveticaWorld-Regular',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#231F20',
                'lineHeight' => 10,
                'promotedFromExtraction' => true,
                'promotedDirty' => false,
                'promotedSourceKey' => 'block-1-23',
                'promotedSourceBlockNum' => 23,
                'sourceBlockLeft' => 54.000,
                'sourceBlockTop' => 652.856,
                'sourceBlockWidth' => 245.315,
                'sourceBlockHeight' => 9.419,
                'sourcePageHeight' => 792,
                'sourceTextLines' => [
                    'b. 100% of the tax shown on your 2025 tax return. Your',
                ],
                'sourceLineBBoxes' => [
                    [54.000, 652.856, 299.315, 662.275],
                ],
                'sourceSpans' => [
                    [
                        'text' => 'b. 100% of the tax shown on your 2025 tax return. Your',
                        'render_text' => 'b. 100% of the tax shown on your 2025 tax return. Your',
                        'origin' => [54.000, 660.000],
                        'bbox' => [54.000, 652.856, 299.315, 662.275],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->getJson(route('pdfTests.documentInfo', $document, [
            'session_id' => 'state-session',
        ]));
        $response->assertOk()->assertJson(['success' => true]);

        $annotations = collect($response->json('annotations'));
        $this->assertNull($annotations->firstWhere('id', 'promoted_1_23'));

        $annotation = $annotations->firstWhere('id', 'promoted_1_14');
        $this->assertNotNull($annotation);
        $this->assertSame([
            'or',
            'b. 100% of the tax shown on your 2025 tax return. Your',
            '2025 tax return must cover all 12 months.',
        ], $annotation['sourceTextLines']);
    }

    public function test_document_info_suppresses_child_when_saved_parent_text_already_contains_it(): void
    {
        $user = User::factory()->create([
            'email' => 'doc3018-owner@example.com',
        ]);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'doc3018.pdf',
            'path' => 'documents/doc3018.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        PdfExtractionFitz::query()->create([
            'document_id' => $document->id,
            'session_id' => 'fitz-session',
            'pdf_filename' => 'doc3018.pdf',
            'total_pages' => 1,
            'total_words' => 12,
            'full_text' => 'sample',
            'extraction_data' => [],
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => null,
            'user_id' => $user->id,
            'session_id' => 'state-session',
            'page_number' => 0,
            'state' => 'saved',
            'annotation_data' => [
                'id' => 'promoted_1_15',
                'type' => 'text',
                'text' => "or\nb. 100% of the tax shown on your 2025 tax return. Your\n2025 tax return must cover all 12 months.",
                'originalText' => "or\n2025 tax return must cover all 12 months.",
                'pageIndex' => 0,
                'pdfX' => 42.000,
                'pdfY' => 95.175,
                'pdfWidth' => 180.303,
                'pdfHeight' => 59.084,
                'fontSize' => 10,
                'fontFamily' => 'HelveticaWorld',
                'fontSourceName' => 'HelveticaWorld-Regular',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#231F20',
                'lineHeight' => 10,
                'promotedFromExtraction' => true,
                'promotedDirty' => false,
                'promotedSourceKey' => 'block-1-15',
                'promotedSourceBlockNum' => 15,
                'sourceBlockLeft' => 42.000,
                'sourceBlockTop' => 637.742,
                'sourceBlockWidth' => 180.303,
                'sourceBlockHeight' => 35.400,
                'sourcePageHeight' => 792,
                'sourceTextLines' => [
                    'or',
                    '2025 tax return must cover all 12 months.',
                ],
                'sourceLineBBoxes' => [
                    [42.000, 637.742, 50.842, 647.742],
                    [42.000, 663.142, 222.303, 673.142],
                ],
            ],
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => null,
            'user_id' => $user->id,
            'session_id' => 'state-session',
            'page_number' => 0,
            'state' => 'saved',
            'annotation_data' => [
                'id' => 'promoted_1_22',
                'type' => 'text',
                'text' => 'b. 100% of the tax shown on your 2025 tax return. Your',
                'originalText' => 'b. 100% of the tax shown on your 2025 tax return. Your',
                'pageIndex' => 0,
                'pdfX' => 54.000,
                'pdfY' => 129.725,
                'pdfWidth' => 245.315,
                'pdfHeight' => 9.419,
                'fontSize' => 10,
                'fontFamily' => 'HelveticaWorld',
                'fontSourceName' => 'HelveticaWorld-Regular',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#231F20',
                'lineHeight' => 10,
                'promotedFromExtraction' => true,
                'promotedDirty' => false,
                'promotedSourceKey' => 'block-1-22',
                'promotedSourceBlockNum' => 22,
                'sourceBlockLeft' => 54.000,
                'sourceBlockTop' => 652.856,
                'sourceBlockWidth' => 245.315,
                'sourceBlockHeight' => 9.419,
                'sourcePageHeight' => 792,
                'sourceTextLines' => [
                    'b. 100% of the tax shown on your 2025 tax return. Your',
                ],
                'sourceLineBBoxes' => [
                    [54.000, 652.856, 299.315, 662.275],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->getJson(route('pdfTests.documentInfo', $document, [
            'session_id' => 'state-session',
        ]));
        $response->assertOk()->assertJson(['success' => true]);

        $annotations = collect($response->json('annotations'));
        $this->assertNull($annotations->firstWhere('id', 'promoted_1_22'));
        $this->assertSame(
            "or\nb. 100% of the tax shown on your 2025 tax return. Your\n2025 tax return must cover all 12 months.",
            $annotations->firstWhere('id', 'promoted_1_15')['text'] ?? null
        );
    }
}
