<?php

namespace Tests\Unit;

use App\Support\PdfAnnotationSuppression;
use PHPUnit\Framework\TestCase;

class PdfAnnotationSuppressionTest extends TestCase
{
    public function test_merge_is_suppressed_when_a_component_has_more_specific_children(): void
    {
        $suppressed = PdfAnnotationSuppression::suppressedIds([
            'promoted_1_24',
            'promoted_1_25_lines-0-0',
            'promoted_1_25_lines-1-3',
            'promoted_1_25_lines-4-4',
            'promoted_1_24_merge_promoted_1_25',
        ]);

        $this->assertContains('promoted_1_24_merge_promoted_1_25', $suppressed);
        $this->assertNotContains('promoted_1_24', $suppressed);
        $this->assertNotContains('promoted_1_25_lines-0-0', $suppressed);
        $this->assertNotContains('promoted_1_25_lines-1-3', $suppressed);
        $this->assertNotContains('promoted_1_25_lines-4-4', $suppressed);
    }

    public function test_merge_suppresses_plain_component_blocks_when_no_children_exist(): void
    {
        $suppressed = PdfAnnotationSuppression::suppressedIds([
            'promoted_1_24',
            'promoted_1_25',
            'promoted_1_24_merge_promoted_1_25',
        ]);

        $this->assertContains('promoted_1_24', $suppressed);
        $this->assertContains('promoted_1_25', $suppressed);
        $this->assertNotContains('promoted_1_24_merge_promoted_1_25', $suppressed);
    }

    public function test_enclosing_line_variant_is_suppressed_when_siblings_cover_its_range(): void
    {
        $suppressed = PdfAnnotationSuppression::suppressedIds([
            'promoted_1_100_lines-0-4',
            'promoted_1_100_lines-0-1',
            'promoted_1_100_lines-2-4',
        ]);

        $this->assertContains('promoted_1_100_lines-0-4', $suppressed);
        $this->assertNotContains('promoted_1_100_lines-0-1', $suppressed);
        $this->assertNotContains('promoted_1_100_lines-2-4', $suppressed);
    }

    public function test_enclosing_line_variant_is_not_suppressed_when_siblings_leave_a_gap(): void
    {
        $suppressed = PdfAnnotationSuppression::suppressedIds([
            'promoted_1_100_lines-0-4',
            'promoted_1_100_lines-0-1',
            // 2-3 gap, no sibling covers lines 2-3
            'promoted_1_100_lines-4-4',
        ]);

        $this->assertNotContains('promoted_1_100_lines-0-4', $suppressed);
    }

    public function test_enclosing_line_variant_is_not_suppressed_when_it_is_the_only_variant(): void
    {
        $suppressed = PdfAnnotationSuppression::suppressedIds([
            'promoted_1_100_lines-0-4',
        ]);

        $this->assertNotContains('promoted_1_100_lines-0-4', $suppressed);
    }
}
