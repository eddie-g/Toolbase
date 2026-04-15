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
}
