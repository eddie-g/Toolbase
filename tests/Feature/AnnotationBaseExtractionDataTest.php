<?php

namespace Tests\Feature;

use App\Http\Controllers\DocumentController;
use App\Models\Document;
use App\Models\PdfExtractionFitz;
use App\Models\PdfExtractionPage;
use App\Models\PdfGroup;
use App\Models\PdfState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnotationBaseExtractionDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_annotation_base_mode_rebuilds_grouped_extraction_blocks_from_pdf_state_and_pdf_groups(): void
    {
        config()->set('pdf_editor.mode', 'annotation_base');

        $user = User::factory()->create();
        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'grouped.pdf',
            'path' => 'documents/grouped.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $extractedSessionId = 'document_' . $document->id . '_extracted';

        PdfState::query()->create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'session_id' => $extractedSessionId,
            'page_number' => 0,
            'state' => 'extracted',
            'annotation_data' => [
                'id' => 'promoted_1_7_lines_0_0',
                'type' => 'text',
                'text' => 'First line',
                'pageIndex' => 0,
                'fontSize' => 11,
                'fontFamily' => 'Helvetica',
                'fontSourceName' => 'Helvetica',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#111111',
                'lineHeight' => 12,
                'promotedFromExtraction' => true,
                'promotedSourceKey' => 'block-1-7-lines-0-0',
                'sourcePageHeight' => 792,
                'sourceTextLines' => ['First line'],
                'sourceLineBBoxes' => [[72, 100, 160, 112]],
                'sourceSpans' => [['font' => 'Helvetica']],
                'sourceBlockLeft' => 72,
                'sourceBlockTop' => 100,
                'sourceBlockWidth' => 130,
                'sourceBlockHeight' => 26,
            ],
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'session_id' => $extractedSessionId,
            'page_number' => 0,
            'state' => 'extracted',
            'annotation_data' => [
                'id' => 'promoted_1_7_lines_1_1',
                'type' => 'text',
                'text' => 'Second line',
                'pageIndex' => 0,
                'fontSize' => 11,
                'fontFamily' => 'Helvetica',
                'fontSourceName' => 'Helvetica',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#111111',
                'lineHeight' => 12,
                'promotedFromExtraction' => true,
                'promotedSourceKey' => 'block-1-7-lines-1-1',
                'sourcePageHeight' => 792,
                'sourceTextLines' => ['Second line'],
                'sourceLineBBoxes' => [[72, 114, 170, 126]],
                'sourceSpans' => [['font' => 'Helvetica']],
                'sourceBlockLeft' => 72,
                'sourceBlockTop' => 100,
                'sourceBlockWidth' => 130,
                'sourceBlockHeight' => 26,
            ],
        ]);

        PdfGroup::query()->create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'session_id' => $extractedSessionId,
            'page_number' => 0,
            'group_key' => 'block-1-7',
            'root_source_key' => 'block-1-7',
            'group_type' => 'promoted_text',
            'group_bbox' => [72, 100, 170, 126],
            'annotation_ids' => ['promoted_1_7_lines_0_0', 'promoted_1_7_lines_1_1'],
            'annotation_source_keys' => ['block-1-7-lines-0-0', 'block-1-7-lines-1-1'],
            'group_data' => [
                'page_number' => 1,
                'page_width' => 612,
                'page_height' => 792,
            ],
            'state' => 'extracted',
        ]);

        $controller = app(DocumentController::class);
        $method = new \ReflectionMethod($controller, 'resolveRedrawExtractionData');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $document, null, null);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['page_number']);
        $this->assertSame(612.0, $result[0]['width']);
        $this->assertSame(792.0, $result[0]['height']);
        $this->assertCount(1, $result[0]['blocks']);
        $this->assertSame(7, $result[0]['blocks'][0]['block_num']);
        $this->assertSame(['First line', 'Second line'], $result[0]['blocks'][0]['text_lines']);
        $this->assertSame('First line' . "\n" . 'Second line', $result[0]['blocks'][0]['text']);
        $this->assertSame([72.0, 100.0, 170.0, 126.0], $result[0]['blocks'][0]['bbox']);
    }

    public function test_annotation_base_mode_uses_pinned_normalized_snapshot_dimensions_instead_of_latest_raw_blob(): void
    {
        config()->set('pdf_editor.mode', 'annotation_base');

        $user = User::factory()->create();
        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'normalized.pdf',
            'path' => 'documents/normalized.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $snapshot = PdfExtractionFitz::query()->create([
            'document_id' => $document->id,
            'user_email' => null,
            'session_id' => 'snapshot-session',
            'pdf_filename' => 'normalized.pdf',
            'total_pages' => 1,
            'total_words' => 2,
            'full_text' => 'First line Second line',
            'extraction_data' => [],
        ]);

        PdfExtractionPage::query()->create([
            'pdf_extraction_fitz_id' => $snapshot->id,
            'document_id' => $document->id,
            'page_number' => 1,
            'width' => 612,
            'height' => 792,
            'word_count' => 2,
            'text' => 'First line' . "\n" . 'Second line',
            'drawn_box_rects' => [],
            'widget_rects' => [],
        ]);

        $extractedSessionId = 'document_' . $document->id . '_extracted';

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => $snapshot->id,
            'user_id' => $user->id,
            'session_id' => $extractedSessionId,
            'page_number' => 0,
            'state' => 'extracted',
            'annotation_data' => [
                'id' => 'promoted_1_7_lines_0_0',
                'type' => 'text',
                'text' => 'First line',
                'pageIndex' => 0,
                'fontSize' => 11,
                'fontFamily' => 'Helvetica',
                'fontSourceName' => 'Helvetica',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#111111',
                'lineHeight' => 12,
                'promotedFromExtraction' => true,
                'promotedSourceKey' => 'block-1-7-lines-0-0',
                'sourcePageHeight' => 792,
                'sourceTextLines' => ['First line'],
                'sourceLineBBoxes' => [[72, 100, 160, 112]],
                'sourceSpans' => [['font' => 'Helvetica']],
                'sourceBlockLeft' => 72,
                'sourceBlockTop' => 100,
                'sourceBlockWidth' => 130,
                'sourceBlockHeight' => 26,
            ],
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => $snapshot->id,
            'user_id' => $user->id,
            'session_id' => $extractedSessionId,
            'page_number' => 0,
            'state' => 'extracted',
            'annotation_data' => [
                'id' => 'promoted_1_7_lines_1_1',
                'type' => 'text',
                'text' => 'Second line',
                'pageIndex' => 0,
                'fontSize' => 11,
                'fontFamily' => 'Helvetica',
                'fontSourceName' => 'Helvetica',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#111111',
                'lineHeight' => 12,
                'promotedFromExtraction' => true,
                'promotedSourceKey' => 'block-1-7-lines-1-1',
                'sourcePageHeight' => 792,
                'sourceTextLines' => ['Second line'],
                'sourceLineBBoxes' => [[72, 114, 170, 126]],
                'sourceSpans' => [['font' => 'Helvetica']],
                'sourceBlockLeft' => 72,
                'sourceBlockTop' => 100,
                'sourceBlockWidth' => 130,
                'sourceBlockHeight' => 26,
            ],
        ]);

        PdfGroup::query()->create([
            'document_id' => $document->id,
            'pdf_extraction_fitz_id' => $snapshot->id,
            'user_id' => $user->id,
            'session_id' => $extractedSessionId,
            'page_number' => 0,
            'group_key' => 'block-1-7',
            'root_source_key' => 'block-1-7',
            'group_type' => 'promoted_text',
            'group_bbox' => [72, 100, 170, 126],
            'annotation_ids' => ['promoted_1_7_lines_0_0', 'promoted_1_7_lines_1_1'],
            'annotation_source_keys' => ['block-1-7-lines-0-0', 'block-1-7-lines-1-1'],
            'group_data' => [
                'page_number' => 1,
                'page_width' => 0,
                'page_height' => 0,
            ],
            'state' => 'extracted',
        ]);

        $controller = app(DocumentController::class);
        $method = new \ReflectionMethod($controller, 'resolveRedrawExtractionData');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $document, null, null);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame(612.0, $result[0]['width']);
        $this->assertSame(792.0, $result[0]['height']);
        $this->assertCount(1, $result[0]['blocks']);
        $this->assertSame('First line' . "\n" . 'Second line', $result[0]['blocks'][0]['text']);
    }

    public function test_annotation_base_mode_merges_nested_single_line_group_into_parent_blank_line_slot(): void
    {
        config()->set('pdf_editor.mode', 'annotation_base');

        $user = User::factory()->create();
        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'nested-grouping.pdf',
            'path' => 'documents/nested-grouping.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $extractedSessionId = 'document_' . $document->id . '_extracted';

        PdfState::query()->create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'session_id' => $extractedSessionId,
            'page_number' => 0,
            'state' => 'extracted',
            'annotation_data' => [
                'id' => 'promoted_1_4',
                'type' => 'text',
                'text' => "Use Form 1040-ES to figure and pay your estimated tax\nfor 2026.\n\nthat isn't subject to withholding",
                'pageIndex' => 0,
                'fontSize' => 10,
                'fontFamily' => 'HelveticaWorld',
                'fontSourceName' => 'HelveticaWorld-Regular',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#231F20',
                'lineHeight' => 10,
                'promotedFromExtraction' => true,
                'promotedSourceKey' => 'block-1-4',
                'sourcePageHeight' => 792,
                'sourceTextLines' => [
                    'Use Form 1040-ES to figure and pay your estimated tax',
                    'for 2026.',
                    '',
                    "that isn't subject to withholding",
                ],
                'sourceLineBBoxes' => [
                    [42, 132.5, 286.9, 142.5],
                    [42, 143.7, 80.8, 153.7],
                    [42, 162.1, 42, 172.1],
                    [42, 172.1, 278.7, 182.1],
                ],
                'sourceSpans' => [
                    ['bbox' => [42, 132.5, 286.9, 142.5], 'font' => 'HelveticaWorld-Regular'],
                    ['bbox' => [42, 143.7, 80.8, 153.7], 'font' => 'HelveticaWorld-Regular'],
                    ['bbox' => [42, 172.1, 278.7, 182.1], 'font' => 'HelveticaWorld-Regular'],
                ],
                'sourceBlockLeft' => 42,
                'sourceBlockTop' => 132.5,
                'sourceBlockWidth' => 254.3,
                'sourceBlockHeight' => 49.6,
            ],
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'session_id' => $extractedSessionId,
            'page_number' => 0,
            'state' => 'extracted',
            'annotation_data' => [
                'id' => 'promoted_1_19',
                'type' => 'text',
                'text' => 'Estimated tax is the method used to pay tax on income',
                'pageIndex' => 0,
                'fontSize' => 10,
                'fontFamily' => 'HelveticaWorld',
                'fontSourceName' => 'HelveticaWorld-Regular',
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'textColor' => '#231F20',
                'lineHeight' => 10,
                'promotedFromExtraction' => true,
                'promotedSourceKey' => 'block-1-19',
                'sourcePageHeight' => 792,
                'sourceTextLines' => ['Estimated tax is the method used to pay tax on income'],
                'sourceLineBBoxes' => [[54, 160.9, 295.4, 170.9]],
                'sourceSpans' => [
                    ['bbox' => [54, 160.9, 295.4, 170.9], 'font' => 'HelveticaWorld-Regular'],
                ],
                'sourceBlockLeft' => 54,
                'sourceBlockTop' => 160.9,
                'sourceBlockWidth' => 241.4,
                'sourceBlockHeight' => 10,
            ],
        ]);

        PdfGroup::query()->create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'session_id' => $extractedSessionId,
            'page_number' => 0,
            'group_key' => 'block-1-4',
            'root_source_key' => 'block-1-4',
            'group_type' => 'promoted_text',
            'group_bbox' => [42, 132.5, 296.3, 182.1],
            'annotation_ids' => ['promoted_1_4'],
            'annotation_source_keys' => ['block-1-4'],
            'group_data' => [
                'page_number' => 1,
                'page_width' => 612,
                'page_height' => 792,
            ],
            'state' => 'extracted',
        ]);

        PdfGroup::query()->create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'session_id' => $extractedSessionId,
            'page_number' => 0,
            'group_key' => 'block-1-19',
            'root_source_key' => 'block-1-19',
            'group_type' => 'promoted_text',
            'group_bbox' => [54, 160.9, 295.4, 170.9],
            'annotation_ids' => ['promoted_1_19'],
            'annotation_source_keys' => ['block-1-19'],
            'group_data' => [
                'page_number' => 1,
                'page_width' => 612,
                'page_height' => 792,
            ],
            'state' => 'extracted',
        ]);

        $controller = app(DocumentController::class);
        $method = new \ReflectionMethod($controller, 'resolveRedrawExtractionData');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $document, null, null);

        $this->assertCount(1, $result);
        $blocks = $result[0]['blocks'];
        $this->assertCount(1, $blocks);
        $this->assertSame(4, $blocks[0]['block_num']);
        $this->assertSame([
            'Use Form 1040-ES to figure and pay your estimated tax',
            'for 2026.',
            'Estimated tax is the method used to pay tax on income',
            "that isn't subject to withholding",
        ], $blocks[0]['text_lines']);
        $this->assertSame(
            "Use Form 1040-ES to figure and pay your estimated tax\nfor 2026.\nEstimated tax is the method used to pay tax on income\nthat isn't subject to withholding",
            $blocks[0]['text']
        );
    }

    public function test_promoted_materialization_merges_nested_single_line_block_into_parent_block(): void
    {
        $controller = app(DocumentController::class);
        $method = new \ReflectionMethod($controller, 'buildPromotedStateAnnotationsFromExtractionData');
        $method->setAccessible(true);

        $annotations = $method->invoke($controller, [[
            'page_number' => 1,
            'width' => 612,
            'height' => 792,
            'blocks' => [
                [
                    'block_num' => 4,
                    'text' => "Use Form 1040-ES to figure and pay your estimated tax\nfor 2026.\n\nthat isn't subject to withholding",
                    'text_lines' => [
                        'Use Form 1040-ES to figure and pay your estimated tax',
                        'for 2026.',
                        '',
                        "that isn't subject to withholding",
                    ],
                    'line_bboxes' => [
                        [42, 132.5, 286.9, 142.5],
                        [42, 143.7, 80.8, 153.7],
                        [42, 162.1, 42, 172.1],
                        [42, 172.1, 278.7, 182.1],
                    ],
                    'left' => 42,
                    'top' => 132.5,
                    'width' => 254.3,
                    'height' => 49.6,
                    'bbox' => [42, 132.5, 296.3, 182.1],
                    'font_size' => 10,
                    'font' => 'HelveticaWorld-Regular',
                    'font_weight' => '400',
                    'italic' => false,
                    'underline' => false,
                    'hex_color' => '#231F20',
                    'line_height' => 10,
                    'avg_line_height' => 10,
                    'spans' => [
                        ['bbox' => [42, 132.5, 286.9, 142.5], 'font' => 'HelveticaWorld-Regular'],
                        ['bbox' => [42, 143.7, 80.8, 153.7], 'font' => 'HelveticaWorld-Regular'],
                        ['bbox' => [42, 172.1, 278.7, 182.1], 'font' => 'HelveticaWorld-Regular'],
                    ],
                ],
                [
                    'block_num' => 19,
                    'text' => 'Estimated tax is the method used to pay tax on income',
                    'text_lines' => ['Estimated tax is the method used to pay tax on income'],
                    'line_bboxes' => [[54, 160.9, 295.4, 170.9]],
                    'left' => 54,
                    'top' => 160.9,
                    'width' => 241.4,
                    'height' => 10,
                    'bbox' => [54, 160.9, 295.4, 170.9],
                    'font_size' => 10,
                    'font' => 'HelveticaWorld-Regular',
                    'font_weight' => '400',
                    'italic' => false,
                    'underline' => false,
                    'hex_color' => '#231F20',
                    'line_height' => 10,
                    'avg_line_height' => 10,
                    'spans' => [
                        ['bbox' => [54, 160.9, 295.4, 170.9], 'font' => 'HelveticaWorld-Regular'],
                    ],
                ],
            ],
        ]]);

        $this->assertIsArray($annotations);
        $this->assertCount(1, $annotations);
        $this->assertSame('promoted_1_4', $annotations[0]['id']);
        $this->assertSame('block-1-4', $annotations[0]['promotedSourceKey']);
        $this->assertSame([
            'Use Form 1040-ES to figure and pay your estimated tax',
            'for 2026.',
            'Estimated tax is the method used to pay tax on income',
            "that isn't subject to withholding",
        ], $annotations[0]['sourceTextLines']);
    }
}
