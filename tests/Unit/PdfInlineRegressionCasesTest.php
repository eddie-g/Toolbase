<?php

namespace Tests\Unit;

use App\Http\Controllers\PdfTestController;
use App\Models\PdfUploadTestCase;
use App\Support\PdfInlineRegressionCases;
use Tests\TestCase;

class PdfInlineRegressionCasesTest extends TestCase
{
    public function test_all_reported_cases_resolve_after_fixture_document_ids_change(): void
    {
        $cases = PdfInlineRegressionCases::all();
        $this->assertCount(9, $cases);
        $method = new \ReflectionMethod(PdfTestController::class, 'resolveUploadTestScenario');
        foreach ($cases as $case) {
            $testCase = new PdfUploadTestCase([
                'runtime_annotation_id' => preg_replace('/^pdfjs_\d+_/', 'pdfjs_99999_', $case['annotation_id']),
                'page_index' => $case['page_index'],
                'test_comment' => '[inline-regression:'.$case['key'].'] '.$case['instruction'],
            ]);
            $resolved = $method->invoke(new PdfTestController, $testCase);
            $this->assertSame('inline_regression', $resolved['scenario']);
            $this->assertSame($case, $resolved['inline_regression']);
            $testCase->page_index = 99;
            $this->assertNull(PdfInlineRegressionCases::resolve($testCase));
        }
    }

    public function test_unmarked_cases_do_not_override_existing_scenarios(): void
    {
        $this->assertNull(PdfInlineRegressionCases::resolve(new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_7339_0_0:24',
            'page_index' => 0,
            'test_comment' => 'Move the annotation.',
        ])));
    }
}