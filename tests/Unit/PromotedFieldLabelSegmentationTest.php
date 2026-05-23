<?php

namespace Tests\Unit;

use App\Http\Controllers\PdfTestController;
use ReflectionClass;
use Tests\TestCase;

class PromotedFieldLabelSegmentationTest extends TestCase
{
    public function test_clean_promoted_field_label_line_is_split_into_editable_segments(): void
    {
        $annotation = [
            'id' => 'promoted_1_27',
            'type' => 'text',
            'text' => '6 County and state where principal business is located',
            'originalText' => '6 County and state where principal business is located',
            'pageIndex' => 0,
            'pdfX' => 54.400001525879,
            'pdfY' => 602.98773193359,
            'pdfWidth' => 208.78401947021,
            'pdfHeight' => 8.0063934326172,
            'fontSize' => 8,
            'fontFamily' => 'HelveticaNeueLTStd',
            'fontWeight' => '700',
            'fontStyle' => 'normal',
            'textColor' => '#000000',
            'sourcePageHeight' => 791.96801757812,
            'sourceTextLines' => ['6 County and state where principal business is located'],
            'sourceLineBBoxes' => [[54.400001525879, 180.97389221191, 263.18402099609, 188.98028564453]],
            'sourceBlockLeft' => 54.400001525879,
            'sourceBlockTop' => 180.97389221191,
            'sourceBlockWidth' => 208.78401947021,
            'sourceBlockHeight' => 8.0063934326172,
            'promotedSourceKey' => 'block-1-27',
            'promotedFromExtraction' => true,
            'promotedDirty' => false,
            'sourceSpans' => [
                [
                    'text' => '6',
                    'bbox' => [54.400001525879, 180.97389221191, 74.416000366211, 188.97389221191],
                    'font_weight' => '700',
                    'font_size' => 8,
                    'embedded_font_name' => 'HelveticaNeueLTStd-Bd',
                    'embedded_font_family' => 'HelveticaNeueLTStd',
                ],
                [
                    'text' => 'County and state where principal business is located',
                    'bbox' => [74.416000366211, 180.98028564453, 263.18402099609, 188.98028564453],
                    'font_weight' => '400',
                    'font_size' => 8,
                    'embedded_font_name' => 'HelveticaNeueLTStd-Roman',
                    'embedded_font_family' => 'HelveticaNeueLTStd',
                ],
            ],
        ];

        $controller = app(PdfTestController::class);
        $method = (new ReflectionClass($controller))->getMethod('splitPromotedFieldLabelAnnotationsForEditor');
        $segments = $method->invoke($controller, [$annotation]);

        $this->assertCount(2, $segments);
        $this->assertSame('promoted_1_27_seg_0', $segments[0]['id']);
        $this->assertSame('6', $segments[0]['text']);
        $this->assertSame('field_label', $segments[0]['promotedSegmentRole']);
        $this->assertSame([54.400001525879, 180.97389221191, 74.416000366211, 188.97389221191], $segments[0]['sourceLineBBoxes'][0]);

        $this->assertSame('promoted_1_27_seg_1', $segments[1]['id']);
        $this->assertSame('County and state where principal business is located', $segments[1]['text']);
        $this->assertSame('field_body_label', $segments[1]['promotedSegmentRole']);
        $this->assertSame([74.416000366211, 180.98028564453, 263.18402099609, 188.98028564453], $segments[1]['sourceLineBBoxes'][0]);
        $this->assertSame('block-1-27__seg_0', $segments[0]['promotedSourceKey']);
        $this->assertSame('block-1-27__seg_1', $segments[1]['promotedSourceKey']);
    }
}