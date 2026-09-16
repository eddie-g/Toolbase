<?php

namespace App\Support;

use App\Models\PdfUploadTestCase;

class PdfInlineRegressionCases
{
    public static function all(): array
    {
        return json_decode(file_get_contents(resource_path('automated-tests/pdf-inline-regressions.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function resolve(PdfUploadTestCase $testCase): ?array
    {
        foreach (self::all() as $case) {
            $expectedId = preg_replace('/^pdfjs_\d+_/', 'pdfjs_', $case['annotation_id']);
            $actualId = preg_replace('/^pdfjs_\d+_/', 'pdfjs_', $testCase->runtime_annotation_id ?: $testCase->annotation_id);
            if ((int) $testCase->page_index === $case['page_index']
                && $actualId === $expectedId
                && str_contains((string) $testCase->test_comment, '[inline-regression:'.$case['key'].']')) {
                return $case;
            }
        }

        return null;
    }
}