<?php

namespace Tests\Unit;

use App\Services\PdfDocumentPageOffsetRemapper;
use PHPUnit\Framework\TestCase;

class PdfDocumentPageOffsetRemapperTest extends TestCase
{
    public function test_it_offsets_page_fields_and_page_bearing_source_identifiers(): void
    {
        $payload = [
            'id' => 'pdfjs_42_0_source:0:8',
            'pageIndex' => 0,
            'db_page_number' => 1,
            'promotedSourcePage' => 1,
            'promotedSourceKey' => 'block-1-7-lines-0-0',
            'promotedMergedSourceKeys' => ['block-1-7', 'block-2-4'],
            'nested' => [
                'page_num' => 0,
                'annotation_id' => 'deleted_promoted:block-1-7',
            ],
        ];

        $result = (new PdfDocumentPageOffsetRemapper())->remapPayload($payload, 3, 42);

        $this->assertSame(3, $result['pageIndex']);
        $this->assertSame(4, $result['db_page_number']);
        $this->assertSame(4, $result['promotedSourcePage']);
        $this->assertSame('pdfjs_42_3_source:3:8', $result['id']);
        $this->assertSame('block-4-7-lines-0-0', $result['promotedSourceKey']);
        $this->assertSame(['block-4-7', 'block-5-4'], $result['promotedMergedSourceKeys']);
        $this->assertSame(3, $result['nested']['page_num']);
        $this->assertSame('deleted_promoted:block-4-7', $result['nested']['annotation_id']);
    }
}
