<?php

namespace Tests\Unit;

use App\Http\Controllers\DocumentController;
use ReflectionClass;
use Tests\TestCase;

class PromotedSourceSpanRichTextTest extends TestCase
{
    public function test_visible_export_filter_keeps_clean_multiline_rich_promoted_text_source_rendered(): void
    {
        $annotation = [
            'id' => 'promoted_1_1',
            'type' => 'text',
            'text' => "1234 Company St.\nCompany Town ST 12345",
            'originalText' => "1234 Company St.\nCompany Town ST 12345",
            'pdfjsSourceText' => "1234 Company St.\nCompany Town ST 12345",
            'promotedFromExtraction' => true,
            'promotedDirty' => false,
            'pdfjsEditorMode' => 'rich',
            'sourceLineBBoxes' => [
                [24, 49.52, 114.5, 59.27],
                [24, 64.67, 147.27, 74.42],
            ],
        ];

        $controller = app(DocumentController::class);
        $method = (new ReflectionClass($controller))->getMethod('filterAnnotationsForPdfjsVisibleExport');
        $movedAnnotation = [...$annotation, 'movedTextOverlay' => true];

        $this->assertSame([], $method->invoke($controller, [$annotation]));
        $this->assertSame(
            [$movedAnnotation],
            $method->invoke($controller, [$movedAnnotation])
        );
    }

    public function test_promoted_number_edit_preserves_source_span_boundaries(): void
    {
        $annotation = [
            'id' => 'promoted_1_9',
            'type' => 'text',
            'text' => '55 Reason for applying (check only one box)',
            'promotedFromExtraction' => true,
            'promotedDirty' => true,
            'preserveSourceTypography' => true,
            'sourceSpans' => [
                [
                    'bbox' => [57.599998474121, 387.17385864258, 136.10401916504, 395.17385864258],
                    'text' => 'Reason for applying',
                    'bold' => true,
                    'font_weight' => '700',
                    'font_size' => 8,
                    'space_width' => 2.2239999771118,
                    'embedded_font_name' => 'HelveticaNeueLTStd-Bd',
                    'embedded_font_family' => 'HelveticaNeueLTStd',
                ],
                [
                    'bbox' => [136.10401916504, 387.18026733398, 210.04002380371, 395.18026733398],
                    'text' => '(check only one box)',
                    'bold' => false,
                    'font_weight' => '400',
                    'font_size' => 8,
                    'space_width' => 2.2239999771118,
                    'embedded_font_name' => 'HelveticaNeueLTStd-Roman',
                    'embedded_font_family' => 'HelveticaNeueLTStd',
                ],
                [
                    'bbox' => [36, 387.17385864258, 44.896003723145, 395.17385864258],
                    'text' => '10',
                    'bold' => true,
                    'font_weight' => '700',
                    'font_size' => 8,
                    'space_width' => 2.2239999771118,
                    'embedded_font_name' => 'HelveticaNeueLTStd-Bd',
                    'embedded_font_family' => 'HelveticaNeueLTStd',
                ],
            ],
        ];

        $controller = app(DocumentController::class);
        $method = (new ReflectionClass($controller))->getMethod('ensureRichTextHtmlForMultiStyleSource');
        $result = $method->invoke($controller, $annotation);
        $html = (string) ($result['richTextHtml'] ?? '');

        $this->assertStringContainsString('>55</span>', $html);
        $this->assertStringContainsString('>Reason for applying</span>', $html);
        $this->assertStringContainsString('>(check only one box)</span>', $html);
        $this->assertStringNotContainsString('>Reason</span>', $html);
        $this->assertStringNotContainsString('>for applying (check only one box)</span>', $html);
        $this->assertLessThan(strpos($html, '>Reason for applying</span>'), strpos($html, '>55</span>'));
        $this->assertLessThan(strpos($html, '>(check only one box)</span>'), strpos($html, '>Reason for applying</span>'));
    }
}
