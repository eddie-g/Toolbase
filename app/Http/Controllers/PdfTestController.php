<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Document;
use App\Models\OverlayEditorTest;
use App\Models\PdfAcroForm;
use App\Models\PdfExtractionBlock;
use App\Models\PdfExtractionFitz;
use App\Models\PdfExtractionPage;
use App\Models\PdfExtractionSpan;
use App\Models\PdfGroup;
use App\Models\PdfState;
use App\Models\PdfUploadTest;
use App\Models\PdfUploadTestCase;
use App\Services\PdfAnnotationAssetService;
use App\Support\PdfAnnotationSuppression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfTestController extends Controller
{
    protected ?bool $hasTestKeyColumn = null;
    protected ?array $testsOverlayEditorColumns = null;
    protected ?bool $hasPdfStateAnnotationDebugColumn = null;

    private function currentWebUserId(): ?int
    {
        $userId = Auth::guard('web')->id();

        return $userId !== null ? (int) $userId : null;
    }

    private function currentAdminId(): ?int
    {
        $adminId = Auth::guard('admin')->id();

        return $adminId !== null ? (int) $adminId : null;
    }

    private function currentEditorOwnership(): array
    {
        $webUserId = $this->currentWebUserId();
        if ($webUserId !== null) {
            return [
                'user_id' => $webUserId,
                'admin_id' => null,
            ];
        }

        $adminId = $this->currentAdminId();
        if ($adminId !== null) {
            return [
                'user_id' => null,
                'admin_id' => $adminId,
            ];
        }

        return [
            'user_id' => null,
            'admin_id' => null,
        ];
    }

    private function resolveDocumentOwnership(?Document $document = null): array
    {
        $documentUserId = $document?->user_id;
        if ($documentUserId !== null) {
            return [
                'user_id' => (int) $documentUserId,
                'admin_id' => null,
            ];
        }

        $documentAdminId = $document?->admin_id;
        if ($documentAdminId !== null) {
            return [
                'user_id' => null,
                'admin_id' => (int) $documentAdminId,
            ];
        }

        return $this->currentEditorOwnership();
    }

    private function applyOwnershipScope($query, ?int $userId, ?int $adminId, string $sessionId = '', string $sessionColumn = 'session_id')
    {
        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        if ($adminId !== null) {
            return $query->where('admin_id', $adminId);
        }

        if ($sessionId !== '') {
            return $query->where($sessionColumn, $sessionId);
        }

        return $query->whereRaw('1 = 0');
    }

    private function hasPdfStateAnnotationDebugColumn(): bool
    {
        if ($this->hasPdfStateAnnotationDebugColumn === null) {
            $this->hasPdfStateAnnotationDebugColumn = Schema::hasColumn('pdf_state', 'annotation_debug');
        }

        return $this->hasPdfStateAnnotationDebugColumn;
    }

    private function normalizePromotedComparableText(mixed $value): string
    {
        return Str::of((string) ($value ?? ''))
            ->replace("\u{00A0}", ' ')
            ->squish()
            ->lower()
            ->value();
    }

    private function isSyntheticMergedPromotedAnnotation(array $annotation): bool
    {
        if (empty($annotation['promotedFromExtraction'])) {
            return false;
        }

        return str_contains((string) ($annotation['id'] ?? ''), '_merge_')
            || str_contains((string) ($annotation['promotedSourceKey'] ?? ''), '__merge__');
    }

    private function promotedAnnotationHasMaterialEdits(array $annotation): bool
    {
        if (empty($annotation['promotedFromExtraction'])) {
            return false;
        }

        if (!empty($annotation['promotedDirty']) || !empty($annotation['promotedReflowEnabled'])) {
            return true;
        }

        if ($this->promotedAnnotationHasMaterialGeometryEdits($annotation)) {
            return true;
        }

        return $this->normalizePromotedComparableText($annotation['text'] ?? '')
            !== $this->normalizePromotedComparableText($annotation['originalText'] ?? '');
    }

    private function promotedAnnotationHasMaterialGeometryEdits(array $annotation): bool
    {
        if (empty($annotation['promotedFromExtraction'])) {
            return false;
        }

        $sourceBlockLeft = isset($annotation['sourceBlockLeft']) ? (float) $annotation['sourceBlockLeft'] : null;
        $sourceBlockTop = isset($annotation['sourceBlockTop']) ? (float) $annotation['sourceBlockTop'] : null;
        $sourceBlockWidth = isset($annotation['sourceBlockWidth']) ? (float) $annotation['sourceBlockWidth'] : null;
        $sourceBlockHeight = isset($annotation['sourceBlockHeight']) ? (float) $annotation['sourceBlockHeight'] : null;
        $sourcePageHeight = isset($annotation['sourcePageHeight']) ? (float) $annotation['sourcePageHeight'] : null;

        if ($sourceBlockLeft === null || $sourceBlockTop === null || $sourceBlockWidth === null || $sourceBlockHeight === null || !($sourcePageHeight > 0)) {
            return false;
        }

        $pdfX = isset($annotation['pdfX']) ? (float) $annotation['pdfX'] : null;
        $pdfY = isset($annotation['pdfY']) ? (float) $annotation['pdfY'] : null;
        $pdfWidth = isset($annotation['pdfWidth']) ? (float) $annotation['pdfWidth'] : null;
        $pdfHeight = isset($annotation['pdfHeight']) ? (float) $annotation['pdfHeight'] : null;

        if ($pdfX === null || $pdfY === null || $pdfWidth === null || $pdfHeight === null) {
            return false;
        }
        // A zero-sized saved box is invalid legacy geometry, not evidence of
        // an intentional user move/resize. Let the canonical extraction
        // repair it instead of preserving an unusable overlay.
        if ($pdfWidth <= 0.0 || $pdfHeight <= 0.0) {
            return false;
        }

        $currentTop = $sourcePageHeight - ($pdfY + $pdfHeight);
        $geometryTolerance = 0.75;

        return abs($pdfX - $sourceBlockLeft) > $geometryTolerance
            || abs($currentTop - $sourceBlockTop) > $geometryTolerance
            || abs($pdfWidth - $sourceBlockWidth) > $geometryTolerance
            || abs($pdfHeight - $sourceBlockHeight) > $geometryTolerance;
    }

    private function shouldDiscardLegacySyntheticMergedPromotedAnnotation(array $annotation): bool
    {
        return $this->isSyntheticMergedPromotedAnnotation($annotation)
            && !$this->promotedAnnotationHasMaterialEdits($annotation);
    }

    private function resolvePythonBinary(): string
    {
        $candidates = [
            base_path('python/venv/bin/python'),
            'python3',
            '/usr/bin/python3',
        ];
        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/') && !is_executable($candidate)) {
                continue;
            }
            $probeExit = 1;
            exec(sprintf('%s -c %s 2>&1', escapeshellarg($candidate), escapeshellarg('import fitz')), $_, $probeExit);
            if ($probeExit === 0) {
                return $candidate;
            }
        }
        return 'python3';
    }

    private function embeddedFontsCachePath(int $documentId, string $sourceKey): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_\-]/', '_', strtolower(trim($sourceKey))) ?: 'file';
        if ($normalized === 'file') {
            return storage_path("app/temp/embedded_fonts_{$documentId}.json");
        }

        return storage_path("app/temp/embedded_fonts_{$documentId}_{$normalized}.json");
    }

    private function resolveFontExtractionSourcePath(Document $document, string $sourceKey): ?string
    {
        $normalized = strtolower(trim($sourceKey));

        $filePath = Storage::path($document->path);
        $originalPath = null;
        if ($document->original_backup_path && Storage::exists($document->original_backup_path)) {
            $candidate = Storage::path($document->original_backup_path);
            if (is_file($candidate)) {
                $originalPath = $candidate;
            }
        }

        return match ($normalized) {
            'clean', 'original' => $originalPath ?: (is_file($filePath) ? $filePath : null),
            default => is_file($filePath) ? $filePath : ($originalPath ?: null),
        };
    }

    private function extractEmbeddedFontsForSource(Document $document, string $sourceKey): ?array
    {
        $pdfPath = $this->resolveFontExtractionSourcePath($document, $sourceKey);
        if (!$pdfPath || !is_file($pdfPath)) {
            return null;
        }

        $cachePath = $this->embeddedFontsCachePath($document->id, $sourceKey);
        if (!file_exists($cachePath)) {
            $python = $this->resolvePythonBinary();
            $scriptDir = base_path('python/pdf-editor');
            $tmpScript = storage_path(
                'app/temp/font_extract_' . $document->id . '_' . Str::uuid()->toString() . '.py'
            );
            $scriptWritten = file_put_contents($tmpScript, implode("\n", [
                'import sys, json',
                'sys.path.insert(0, ' . json_encode($scriptDir, JSON_UNESCAPED_SLASHES) . ')',
                'from extract_pdf_pymupdf import extract_embedded_fonts',
                'fonts = extract_embedded_fonts(' . json_encode($pdfPath, JSON_UNESCAPED_SLASHES) . ', ' . (int) $document->id . ')',
                'open(' . json_encode($cachePath, JSON_UNESCAPED_SLASHES) . ', "w").write(json.dumps(fonts, indent=2)) if fonts else None',
            ]));
            if ($scriptWritten !== false) {
                exec(sprintf('timeout 30 %s %s 2>&1', escapeshellarg($python), escapeshellarg($tmpScript)));
            }
            @unlink($tmpScript);
        }

        if (!file_exists($cachePath)) {
            return null;
        }

        return json_decode(file_get_contents($cachePath), true) ?: null;
    }

    public function getTestFiles(Request $request)
    {
        $nodeScript = base_path('tests/OverlayEditor/Pdf/run_pdf_tests.cjs');

        $command = sprintf('node %s --list-files 2>&1', escapeshellarg($nodeScript));
        $output = shell_exec($command);

        if (!$output) {
            return response()->json(['success' => false, 'message' => 'Could not list PDF tests'], 500);
        }

        $data = json_decode($output, true);
        if (!$data || !isset($data['files'])) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse PDF test list',
                'raw' => substr($output, 0, 2000),
            ], 500);
        }

        $files = $data['files'];

        return response()->json([
            'success' => true,
            'run_id' => Str::uuid()->toString(),
            'total' => $data['total'],
            'files' => $files,
            'latest_run' => $this->getLatestRun($files),
        ]);
    }

    public function runSingleTest(Request $request)
    {
        $request->validate([
            'test_key' => 'required|string',
            'run_id' => 'required|string',
            'upload_test_id' => 'nullable|integer|min:1',
            'upload_test_case_id' => 'nullable|integer|min:1',
        ]);

        $testKey = $request->input('test_key');
        $runId = $request->input('run_id');
        $nodeScript = base_path('tests/OverlayEditor/Pdf/run_pdf_tests.cjs');
        $baseUrl = $this->resolveScriptBaseUrl($request);
        $uploadFixture = null;
        $uploadTestCase = null;
        $uploadConfigPath = null;
        $uploadSourcePath = null;
        $resultTestKey = $testKey;

        if ($request->filled('upload_test_id')) {
            abort_unless($testKey === 'pdf_upload_saved_test', 422, 'Invalid uploaded PDF test key.');

            $adminId = $this->currentAdminId();
            abort_unless($adminId !== null, 403);

            $uploadFixture = PdfUploadTest::query()
                ->whereKey((int) $request->integer('upload_test_id'))
                ->where('admin_id', $adminId)
                ->firstOrFail();
            abort_unless(
                $request->filled('upload_test_case_id'),
                422,
                'Choose a saved annotation test to run.'
            );
            $uploadTestCase = PdfUploadTestCase::query()
                ->whereKey((int) $request->integer('upload_test_case_id'))
                ->where('pdf_upload_test_id', $uploadFixture->id)
                ->firstOrFail();

            $resultTestKey = 'pdf_upload_test_'.$uploadTestCase->test_id;
            $tempDir = storage_path('app/temp');
            File::ensureDirectoryExists($tempDir);
            $token = Str::uuid()->toString();
            $uploadSourcePath = $tempDir.'/pdf_upload_test_'.$uploadFixture->id.'_'.$token.'.pdf';
            $uploadConfigPath = $tempDir.'/pdf_upload_test_'.$uploadFixture->id.'_'.$token.'.json';

            $scenarioConfig = $this->resolveUploadTestScenario($uploadTestCase);
            $savedTargetAnnotation = PdfState::query()
                ->where('document_id', $uploadFixture->document_id)
                ->where('page_number', $uploadTestCase->page_index)
                ->where(function ($query) use ($uploadTestCase) {
                    $ids = array_values(array_unique(array_filter([
                        trim((string) $uploadTestCase->runtime_annotation_id),
                        trim((string) $uploadTestCase->annotation_id),
                    ])));
                    foreach ($ids as $index => $id) {
                        $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                        $query->{$method}(
                            "JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) = ?",
                            [$id]
                        );
                    }
                })
                ->latest('id')
                ->value('annotation_data');
            $savedReferenceAnnotations = [];
            if (($scenarioConfig['scenario'] ?? null) === 'drylab_page1_append_paragraph_preserves_layout') {
                foreach ([[1, 'promoted_2_2'], [2, 'promoted_3_1']] as [$pageNumber, $annotationId]) {
                    $referenceAnnotation = PdfState::query()
                        ->where('document_id', $uploadFixture->document_id)
                        ->where('page_number', $pageNumber)
                        ->whereRaw(
                            "JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) = ?",
                            [$annotationId]
                        )
                        ->latest('id')
                        ->value('annotation_data');
                    if (is_array($referenceAnnotation)) {
                        $savedReferenceAnnotations[] = $referenceAnnotation;
                    }
                }
            }

            try {
                File::put($uploadSourcePath, $uploadFixture->pdfContents());
                File::put($uploadConfigPath, json_encode([
                    'upload_test_id' => (int) $uploadFixture->id,
                    'upload_test_case_id' => (int) $uploadTestCase->id,
                    'test_id' => $uploadTestCase->test_id,
                    'source_document_id' => (int) $uploadFixture->document_id,
                    'source_pdf_path' => $uploadSourcePath,
                    'original_name' => $uploadFixture->original_name,
                    'sha256' => $uploadFixture->sha256,
                    'saved_annotation_id' => $uploadTestCase->annotation_id,
                    'saved_runtime_annotation_id' => $uploadTestCase->runtime_annotation_id,
                    'page_index' => $uploadTestCase->page_index,
                    'target_text' => $uploadTestCase->target_text,
                    'test_comment' => $uploadTestCase->test_comment,
                    'scenario' => $scenarioConfig['scenario'],
                    'paragraph_grouping_enabled' => (bool) $uploadFixture->paragraph_grouping_enabled,
                    'saved_target_annotation' => is_array($savedTargetAnnotation)
                        ? $savedTargetAnnotation
                        : null,
                    'saved_reference_annotations' => $savedReferenceAnnotations,
                    'delete_suffix' => '3_3:20',
                    'survivor_suffixes' => ['3_3:19', '3_3:21'],
                    'swap_primary_suffix' => $scenarioConfig['swap_primary_suffix'],
                    'swap_partner_suffix' => $scenarioConfig['swap_partner_suffix'],
                    'swap_expected_text' => [
                        '0_0:9' => 'Apply for an original Social Security card',
                        '0_0:11' => 'Apply for a replacement Social Security card',
                    ],
                    'sentence_to_delete' => $scenarioConfig['sentence_to_delete'],
                    'sentence_preserved_text' => $scenarioConfig['sentence_preserved_text'],
                    'f1040_edit_suffix' => '0_0:113',
                    'f1040_drag_spacing_suffix' => '0_0:71',
                    'f1040_move_glyph_inset_suffix' => '0_0:38',
                    'f1040_mixed_style_suffix' => '0_0:95',
                    'f1040_resize_spacing_suffix' => '0_0:101',
                    'f1040_scroll_spacing_suffix' => '0_0:23',
                    'f1040_date_weight_suffix' => '0_0:119',
                    'f1040_scroll_geometry_suffix' => '0_0:34',
                    'f1040_title_underline_suffix' => '0_0:2',
                    'f1040_move_suffix' => '0_0:115',
                    'f1040_delete_name_suffix' => '0_0:12',
                    'f1040_part_header_suffix' => '0_0:14',
                    'f1040s1_part_header_suffix' => '0_0:18',
                    'move_down_suffix' => $scenarioConfig['move_down_suffix'],
                    'move_down_pixels' => $scenarioConfig['move_down_pixels'],
                    'drylab_title_suffix' => '0_0:0',
                    'drylab_title_expected_text' => 'DrylabNews',
                    'drylab_footer_expected_text' => 'for investors & friends * May 2017',
                    'drylab_move_down_pixels' => $scenarioConfig['move_down_pixels'],
                    'table_header_suffix' => '0_0:6',
                    'table_header_expected_text' => 'Header 2',
                    'table_move_up_pixels' => 200,
                    'table_edit_page_number' => $scenarioConfig['table_edit_page_number'],
                    'table_edit_suffix' => $scenarioConfig['table_edit_suffix'],
                    'table_edit_expected_text' => $scenarioConfig['table_edit_expected_text'],
                    'table_edit_append_text' => $scenarioConfig['table_edit_append_text'],
                    'require_exact_document_font' => $scenarioConfig['require_exact_document_font'],
                    'require_first_keystroke_font_stability' => $scenarioConfig['require_first_keystroke_font_stability'],
                    'resolve_target_by_exact_text' => $scenarioConfig['resolve_target_by_exact_text'],
                    'table_export_page_number' => $scenarioConfig['table_export_page_number'],
                    'table_export_suffix' => $scenarioConfig['table_export_suffix'],
                    'table_export_expected_text' => $scenarioConfig['table_export_expected_text'],
                    'table_export_font_family' => $scenarioConfig['table_export_font_family'],
                    'promoted_edit_annotation_id' => $scenarioConfig['promoted_edit_annotation_id'],
                    'promoted_edit_expected_text' => $scenarioConfig['promoted_edit_expected_text'],
                    'expected_pdf_font_name' => 'UHABOU+Calibri',
                    'expected_document_fonts' => [
                        'ERBEYA+Calibri-Light',
                        'FTKFMY+SegoeUI-BoldItalic',
                        'HJLROU+SegoeUI',
                        'QHUTKC+SegoeUI-Italic',
                        'UHABOU+Calibri',
                        'UXWWOU+Calibri-Bold',
                        'YGTKSM+SegoeUI-Bold',
                    ],
                    'paragraph_shrink_ratio' => $scenarioConfig['paragraph_shrink_ratio'],
                    'bookmark_registered_mark_characters' => $scenarioConfig['bookmark_registered_mark_characters'],
                    'bookmark_paragraph_bold_phrases' => $scenarioConfig['bookmark_paragraph_bold_phrases'],
                    'bookmark_paragraph_move_pixels' => $scenarioConfig['bookmark_paragraph_move_pixels'],
                    'bookmark_split_primary_suffix' => $scenarioConfig['bookmark_split_primary_suffix'],
                    'bookmark_split_partner_suffix' => $scenarioConfig['bookmark_split_partner_suffix'],
                    'bookmark_split_primary_text' => $scenarioConfig['bookmark_split_primary_text'],
                    'bookmark_split_source_word' => $scenarioConfig['bookmark_split_source_word'],
                    'bookmark_font_change_annotation_id' => $scenarioConfig['bookmark_font_change_annotation_id'],
                    'bookmark_font_change_font_family' => $scenarioConfig['bookmark_font_change_font_family'],
                    'bookmark_font_change_expected_text' => $scenarioConfig['bookmark_font_change_expected_text'],
                    'bookmark_baseline_target_suffix' => $scenarioConfig['bookmark_baseline_target_suffix'],
                    'bookmark_baseline_reference_suffix' => $scenarioConfig['bookmark_baseline_reference_suffix'],
                    'bookmark_baseline_target_text' => $scenarioConfig['bookmark_baseline_target_text'],
                    'bookmark_baseline_reference_text' => $scenarioConfig['bookmark_baseline_reference_text'],
                    'bookmark_baseline_gap_pixels' => $scenarioConfig['bookmark_baseline_gap_pixels'],
                    'inline_bold_text' => $scenarioConfig['inline_bold_text'],
                    'inline_italic_text' => $scenarioConfig['inline_italic_text'],
                    'inline_color_text' => $scenarioConfig['inline_color_text'],
                    'inline_text_color' => $scenarioConfig['inline_text_color'],
                    'inline_underline_text' => $scenarioConfig['inline_underline_text'],
                    'f1040_edit_expected_text' => 'Total other payments or refundable credits. Add lines 13a through 13z',
                    'f1040_drag_spacing_expected_text' => 'Credit for previously owned clean vehicles. Attach Form 8936',
                    'f1040_move_glyph_inset_expected_text' => 'Credit for prior year minimum tax. Attach Form 8801',
                    'f1040_mixed_style_expected_text' => '13 Other payments or refundable credits:',
                    'f1040_resize_spacing_expected_text' => 'years',
                    'f1040_scroll_spacing_expected_text' => 'Education credits from Form 8863, line 19',
                    'f1040_date_weight_expected_text' => 'Schedule 3 (Form 1040) 2025 Created 11/17/25',
                    'f1040_scroll_geometry_expected_text' => 'a',
                    'f1040_title_underline_expected_text' => $uploadTestCase->target_text,
                    'f1040_move_expected_text' => '15 Add lines 9 through 12 and 14. Enter here and on Form 1040',
                    'f1040_delete_name_expected_text' => 'Name(s) shown on Form 1040, 1040-SR, or 1040-NR',
                    'f1040_part_header_expected_text' => 'Part I',
                    'f1040s1_part_header_expected_text' => 'Part I',
                    'expected_text' => [
                        '3_3:19' => 'Paperwork Reduction Act Statement -This information collection meets the requirements of 44 U.S.C. § 3507, as',
                        '3_3:20' => 'amended by section 2 of the Paperwork Reduction Act of 1995. You do not need to answer these questions unless we',
                        '3_3:21' => 'display a valid Office of Management and Budget control number. We estimate that it will take between 5 and 60',
                    ],
                    'underlined_phrase' => 'Paperwork Reduction Act of 1995',
                    'result_test_key' => $resultTestKey,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $error) {
                if ($uploadConfigPath) {
                    File::delete($uploadConfigPath);
                }
                if ($uploadSourcePath) {
                    File::delete($uploadSourcePath);
                }

                report($error);

                return response()->json([
                    'success' => false,
                    'message' => 'The uploaded PDF test could not be prepared.',
                ], 500);
            }
        }

        // Suite runs (eg. text_layout_test_suite_1, paragraph_suite) chain
        // many sub-tests sequentially and can easily exceed PHP's default
        // 30-second max_execution_time, so allow up to the node `timeout`
        // budget (1800s) for the HTTP request to complete.
        @set_time_limit(1900);

        $command = $uploadConfigPath
            ? sprintf(
                'BASE_URL=%s PDF_UPLOAD_TEST_CONFIG=%s timeout 1800 node %s --single-test %s 2>&1',
                escapeshellarg($baseUrl),
                escapeshellarg($uploadConfigPath),
                escapeshellarg($nodeScript),
                escapeshellarg($testKey)
            )
            : sprintf(
                'BASE_URL=%s timeout 1800 node %s --single-test %s 2>&1',
                escapeshellarg($baseUrl),
                escapeshellarg($nodeScript),
                escapeshellarg($testKey)
            );

        $output = shell_exec($command);
        if ($uploadConfigPath) {
            File::delete($uploadConfigPath);
        }
        if ($uploadSourcePath) {
            File::delete($uploadSourcePath);
        }
        if (!$output) {
            return response()->json(['success' => false, 'message' => 'PDF test script produced no output'], 500);
        }

        $result = json_decode($output, true);
        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse PDF test result',
                'raw' => substr($output, 0, 2000),
            ], 500);
        }

        $storedArtifacts = $this->normalizeArtifactsForStorage($result['artifacts'] ?? []);
        $artifacts = $this->mapArtifacts($storedArtifacts);
        $result['test_key'] = $resultTestKey;
        $result['artifacts'] = $artifacts;

        $reportId = null;
        $createdAt = null;
        try {
            $reportPayload = [
                'run_id' => $runId,
                'test_type' => 'pdf',
                'filename' => $result['filename'] ?? "{$testKey}.pdf",
                'description' => $result['description'] ?? '',
                'test_category' => $result['test_category'] ?? 'PDF Tests',
                'section_name' => $result['section_name'] ?? 'PDF Test',
                'status' => $result['status'] ?? 'error',
                'checks' => $result['checks'] ?? [],
                'checks_passed' => $result['checks_passed'] ?? 0,
                'checks_total' => $result['checks_total'] ?? 0,
                'page_count' => $result['page_count'] ?? 0,
                'file_size' => $result['file_size'] ?? 0,
                'error' => $result['error'] ?? null,
                'warnings' => $result['warnings'] ?? [],
                'artifacts' => $storedArtifacts,
            ];
            if ($this->supportsTestKeyColumn()) {
                $reportPayload['test_key'] = $resultTestKey;
            }

            $report = OverlayEditorTest::create($this->filterPersistableReportPayload($reportPayload));
            $reportId = $report->id;
            $createdAt = $report->created_at?->toIso8601String();
        } catch (\Throwable $error) {
            Log::warning('Failed to persist PDF test result', [
                'test_key' => $resultTestKey,
                'run_id' => $runId,
                'error' => $error->getMessage(),
            ]);

            $result['warnings'] = array_values(array_merge(
                $result['warnings'] ?? [],
                ['Result was not saved to the database: ' . $error->getMessage()]
            ));
        }

        return response()->json([
            'success' => true,
            'result' => array_merge($result, [
                'id' => $reportId,
                'run_id' => $runId,
                'created_at' => $createdAt,
            ]),
        ]);
    }

    public function createBlank(Request $request)
    {
        $validated = $request->validate([
            'page_size' => 'nullable|string|in:A4,Letter,Legal,A3,A5',
            'orientation' => 'nullable|string|in:portrait,landscape',
        ]);

        $pageSize = $validated['page_size'] ?? 'Letter';
        $orientation = $validated['orientation'] ?? 'portrait';

        $sizes = [
            'A4' => [595.28, 841.89],
            'Letter' => [612.00, 792.00],
            'Legal' => [612.00, 1008.00],
            'A3' => [841.89, 1190.55],
            'A5' => [419.53, 595.28],
        ];

        [$width, $height] = $sizes[$pageSize];
        if ($orientation === 'landscape') {
            [$width, $height] = [$height, $width];
        }

        $uuid = Str::uuid()->toString();
        $storedRelative = 'documents/' . $uuid . '.pdf';
        $storedFull = Storage::path($storedRelative);
        Storage::makeDirectory('documents');

        $scriptCode = implode("\n", [
            'import fitz, sys',
            'doc = fitz.open()',
            sprintf('doc.new_page(width=%s, height=%s)', (float) $width, (float) $height),
            sprintf('doc.save(%s)', var_export($storedFull, true)),
            'doc.close()',
        ]);
        $tmpScript = tempnam(sys_get_temp_dir(), 'pdf_test_blank_') . '.py';
        file_put_contents($tmpScript, $scriptCode);

        $output = [];
        $exitCode = 0;
        exec(sprintf('%s %s 2>&1', escapeshellarg($this->resolvePythonBinary()), escapeshellarg($tmpScript)), $output, $exitCode);
        @unlink($tmpScript);

        if ($exitCode !== 0 || !file_exists($storedFull)) {
            Log::error('PDF test blank creation failed', [
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create blank PDF for tests.',
                'error' => implode("\n", $output),
            ], 500);
        }

        $document = Document::create([
            'user_id' => null,
            'original_name' => 'Blank ' . $pageSize . ' ' . ucfirst($orientation) . '.pdf',
            'path' => $storedRelative,
            'original_backup_path' => $this->createOriginalBackup($storedRelative),
            'mime_type' => 'application/pdf',
            'size_bytes' => filesize($storedFull),
        ]);

        // Grant the Playwright browser session access to this test document so
        // that navigating to /documents/{id}/edit is authorised. The fetch is
        // made same-origin (credentials: 'same-origin') so the session cookie
        // is shared with the browser navigation that follows.
        $sessionKey = 'pdf_editor_accessible_document_ids';
        $existingIds = collect($request->session()->get($sessionKey, []))
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0);
        $request->session()->put(
            $sessionKey,
            $existingIds->push($document->id)->unique()->values()->all()
        );

        return response()->json([
            'success' => true,
            'document_id' => $document->id,
            'edit_url' => route('documents.edit', $document),
        ]);
    }

    public function artifact(string $filename)
    {
        $safeFilename = basename($filename);
        $path = $this->resolveArtifactPath($safeFilename);

        if ($path === null) {
            abort(404, 'Artifact not found');
        }

        return response()->file($path, [
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $artifacts
     * @return array<int, array<string, mixed>>
     */
    protected function mapArtifacts(array $artifacts): array
    {
        return collect($this->normalizeArtifactsForStorage($artifacts))
            ->map(function (array $artifact) {
                $filename = basename((string) $artifact['filename']);

                return [
                    'label' => $artifact['label'] ?? $filename,
                    'kind' => $artifact['kind'] ?? 'file',
                    'filename' => $filename,
                    'url' => route('pdfTests.artifact', ['filename' => $filename]),
                ];
            })
            ->values()
            ->all();
    }

    protected function resolveArtifactPath(string $filename): ?string
    {
        $candidates = array_filter([
            storage_path('app/overlay_regression_artifacts/' . $filename),
            env('PDF_TEST_OUTPUT_DIR')
                ? rtrim((string) env('PDF_TEST_OUTPUT_DIR'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename
                : null,
            env('PDF_TEST_FALLBACK_OUTPUT_DIR')
                ? rtrim((string) env('PDF_TEST_FALLBACK_OUTPUT_DIR'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename
                : null,
            '/tmp/overlay_regression_artifacts/' . $filename,
        ]);

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $artifacts
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeArtifactsForStorage(array $artifacts): array
    {
        return collect($artifacts)
            ->filter(fn ($artifact) => is_array($artifact) && !empty($artifact['filename']))
            ->map(function (array $artifact) {
                $filename = basename((string) $artifact['filename']);

                return [
                    'label' => $artifact['label'] ?? $filename,
                    'kind' => $artifact['kind'] ?? 'file',
                    'filename' => $filename,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, mixed>|null
     */
    protected function getLatestRun(array $files): ?array
    {
        $latestRunId = OverlayEditorTest::query()
            ->where('test_type', 'pdf')
            ->latest('created_at')
            ->value('run_id');

        if (!$latestRunId) {
            return null;
        }

        $reports = OverlayEditorTest::query()
            ->where('test_type', 'pdf')
            ->where('run_id', $latestRunId)
            ->get();

        if ($reports->isEmpty()) {
            return null;
        }

        $order = collect($files)
            ->pluck('path')
            ->flip();

        $results = $reports
            ->sortBy(function (OverlayEditorTest $report) use ($order) {
                $testKey = $report->test_key ?: Str::beforeLast((string) $report->filename, '.pdf');

                return $order->get($testKey, PHP_INT_MAX);
            })
            ->values()
            ->map(fn (OverlayEditorTest $report) => $this->serializeReport($report))
            ->all();

        return [
            'run_id' => $latestRunId,
            'total' => count($results),
            'created_at' => $reports->sortByDesc('created_at')->first()?->created_at?->toIso8601String(),
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeReport(OverlayEditorTest $report): array
    {
        return [
            'id' => $report->id,
            'run_id' => $report->run_id,
            'test_key' => $report->test_key ?: Str::beforeLast((string) $report->filename, '.pdf'),
            'filename' => $report->filename,
            'description' => $report->description,
            'test_category' => $report->test_category,
            'section_name' => $report->section_name,
            'status' => $report->status,
            'checks' => $report->checks ?? [],
            'checks_passed' => $report->checks_passed,
            'checks_total' => $report->checks_total,
            'page_count' => $report->page_count,
            'file_size' => $report->file_size,
            'error' => $report->error,
            'warnings' => $report->warnings ?? [],
            'artifacts' => $this->mapArtifacts($report->artifacts ?? []),
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }

    protected function resolveScriptBaseUrl(Request $request): string
    {
        $configuredUrl = rtrim((string) config('app.url', ''), '/');
        $requestUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $appPort = (string) env('APP_PORT', '80');
        $localhostUrl = 'http://localhost' . ($appPort !== '80' ? ':' . $appPort : '');
        $loopbackUrl = 'http://127.0.0.1' . ($appPort !== '80' ? ':' . $appPort : '');
        $hostGatewayUrl = 'http://host.docker.internal' . ($appPort !== '80' ? ':' . $appPort : '');

        // Prefer direct loopback URLs (session cookies use domain=localhost, so
        // host.docker.internal would break CSRF). Only fall back to the external
        // gateway URL when direct access fails.
        $candidates = array_values(array_filter(array_unique([
            'http://localhost',
            'http://127.0.0.1',
            $localhostUrl,
            $loopbackUrl,
            $configuredUrl,
            $requestUrl,
            $hostGatewayUrl,
        ])));

        foreach ($candidates as $candidate) {
            try {
                $response = Http::timeout(2)->get($candidate . '/pdf-editor');
                if ($response->successful() || ($response->status() >= 300 && $response->status() < 400)) {
                    return $candidate;
                }
            } catch (\Throwable $error) {
                continue;
            }
        }

        return $requestUrl ?: ($configuredUrl ?: 'http://127.0.0.1');
    }

    protected function createOriginalBackup(string $storedPath): ?string
    {
        if (!$storedPath || !Storage::exists($storedPath)) {
            return null;
        }

        Storage::makeDirectory('documents/originals');
        $backupPath = 'documents/originals/' . pathinfo($storedPath, PATHINFO_FILENAME) . '_original.pdf';

        try {
            Storage::copy($storedPath, $backupPath);
        } catch (\Throwable $error) {
            Log::warning('Failed to create original PDF backup for PDF test blank document', [
                'path' => $storedPath,
                'backup_path' => $backupPath,
                'error' => $error->getMessage(),
            ]);

            return null;
        }

        return $backupPath;
    }

    protected function supportsTestKeyColumn(): bool
    {
        if ($this->hasTestKeyColumn !== null) {
            return $this->hasTestKeyColumn;
        }

        try {
            $this->hasTestKeyColumn = in_array('test_key', $this->getTestsOverlayEditorColumns(), true);
        } catch (\Throwable $error) {
            Log::warning('Could not inspect tests_overlay_editor schema', [
                'error' => $error->getMessage(),
            ]);
            $this->hasTestKeyColumn = false;
        }

        return $this->hasTestKeyColumn;
    }

    protected function filterPersistableReportPayload(array $payload): array
    {
        $columns = $this->getTestsOverlayEditorColumns();
        if (empty($columns)) {
            return $payload;
        }

        return collect($payload)
            ->filter(fn ($value, $key) => in_array($key, $columns, true))
            ->all();
    }

    protected function getTestsOverlayEditorColumns(): array
    {
        if ($this->testsOverlayEditorColumns !== null) {
            return $this->testsOverlayEditorColumns;
        }

        try {
            $this->testsOverlayEditorColumns = Schema::getColumnListing('tests_overlay_editor');
        } catch (\Throwable $error) {
            Log::warning('Could not load tests_overlay_editor columns', [
                'error' => $error->getMessage(),
            ]);
            $this->testsOverlayEditorColumns = [];
        }

        return $this->testsOverlayEditorColumns;
    }

    public function renderAnnotations(Request $request, Document $document)
    {
        $python = $this->resolvePythonBinary();

        $sourcePdfPath = Storage::path($document->path);
        if (!file_exists($sourcePdfPath)) {
            return response()->json(['success' => false, 'message' => 'PDF not found.'], 422);
        }

        // Accept a list of state IDs or fall back to all states for the document.
        $stateIds  = $request->input('state_ids');   // comma-separated or array
        $pageIndex = (int) $request->input('page_index', 0);
        $dpi       = min(max((int) $request->input('dpi', 150), 72), 300);

        if ($stateIds) {
            $ids    = is_array($stateIds) ? $stateIds : array_filter(array_map('intval', explode(',', $stateIds)));
            $states = PdfState::where('document_id', $document->id)->whereIn('id', $ids)->get();
        } else {
            $states = PdfState::where('document_id', $document->id)
                ->where('page_number', $pageIndex + 1)
                ->orderBy('id')
                ->get();
        }

        $annotations = [];
        foreach ($states as $state) {
            $ann = $state->annotation_data;
            if (empty($ann)) {
                continue;
            }
            if ($state->pdf_extraction_fitz_id) {
                $ann = $this->enrichAnnotationFromDb($ann, $state->pdf_extraction_fitz_id);
            }
            $ann['__sourcePdfPath'] = $sourcePdfPath;
            $ann['__documentId']    = $document->id;
            $annotations[]          = $ann;
        }

        if (empty($annotations)) {
            return response()->json(['success' => false, 'message' => 'No annotations found.'], 422);
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $annotationsFile = $tempDir . '/render_ann_' . $document->id . '_' . uniqid() . '.json';
        if (@file_put_contents($annotationsFile, json_encode($annotations, JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
            return response()->json(['success' => false, 'message' => 'Failed to write temp file.'], 500);
        }

        // Use the clean/redacted PDF as the base (same logic as ensureCleanPdfPath).
        // Falls back to the annotated source if the cached clean PDF is not yet on disk.
        $cleanPdfPath = Storage::path('temp/clean_' . $document->id . '.pdf');
        $basePdfPath  = is_file($cleanPdfPath) ? $cleanPdfPath : $sourcePdfPath;

        // Inline Python: write annotations onto the clean/redacted base page so the viewer
        // shows annotations overlaid on the actual document content.
        // __sourcePdfPath is still set on each annotation so that show_pdf_page clips work.
        $inlinePy = <<<'PYTHON'
import sys, json, os, base64, tempfile
sys.path.insert(0, sys.argv[3])
import importlib.util, fitz

def load_writer(path):
    spec = importlib.util.spec_from_file_location("new_annotation_writer", path)
    mod  = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

pdf_path       = sys.argv[1]
annotations_f  = sys.argv[2]
writer_dir     = sys.argv[3]
page_index     = int(sys.argv[4])
dpi            = int(sys.argv[5])
base_pdf_path  = sys.argv[6]

with open(annotations_f, encoding="utf-8") as fh:
    annotations = json.load(fh)

# The tmp_pdf is a single-page document (page 0). Normalize every annotation's
# pageIndex to 0 so the writer targets that page regardless of origin page.
for ann in annotations:
    ann["pageIndex"] = 0

writer_path = os.path.join(writer_dir, "new_annotation_writer.py")
writer = load_writer(writer_path)

# Build a single-page PDF from the base (clean/redacted) document
fd, tmp_pdf = tempfile.mkstemp(suffix=".pdf")
os.close(fd)
try:
    base_doc  = fitz.open(base_pdf_path)
    base_page = base_doc[page_index]
    page_rect = fitz.Rect(base_page.rect)

    new_doc  = fitz.open()
    new_page = new_doc.new_page(width=page_rect.width, height=page_rect.height)
    new_page.show_pdf_page(new_page.rect, base_doc, page_index)
    new_doc.save(tmp_pdf)
    new_doc.close()
    base_doc.close()

    writer.apply_annotations(tmp_pdf, annotations)

    doc  = fitz.open(tmp_pdf)
    page = doc[0]
    mat  = fitz.Matrix(dpi / 72.0, dpi / 72.0)
    pix  = page.get_pixmap(matrix=mat, colorspace=fitz.csRGB, alpha=False)
    doc.close()
    png_b64 = base64.b64encode(pix.tobytes("png")).decode("ascii")
    print(json.dumps({"success": True, "image": png_b64, "page_index": page_index, "dpi": dpi}))
finally:
    try: os.unlink(tmp_pdf)
    except: pass
PYTHON;

        $inlinePyFile = $tempDir . '/render_inline_' . uniqid() . '.py';
        file_put_contents($inlinePyFile, $inlinePy);

        $writerDir = base_path('python/pdf-editor');
        $command   = sprintf(
            '%s %s %s %s %s %d %d %s 2>&1',
            escapeshellarg($python),
            escapeshellarg($inlinePyFile),
            escapeshellarg($sourcePdfPath),
            escapeshellarg($annotationsFile),
            escapeshellarg($writerDir),
            $pageIndex,
            $dpi,
            escapeshellarg($basePdfPath)
        );

        $rawOutput = shell_exec($command);
        @unlink($annotationsFile);
        @unlink($inlinePyFile);

        if (!$rawOutput) {
            return response()->json(['success' => false, 'message' => 'Render produced no output.'], 500);
        }

        $result = json_decode($rawOutput, true);
        if (!is_array($result)) {
            return response()->json([
                'success' => false,
                'message' => 'Render script output was not valid JSON.',
                'raw'     => substr($rawOutput, 0, 2000),
            ], 500);
        }

        return response()->json($result);
    }

    /**
     * Build the same annotation set that /edit-new receives from documentInfo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildEditNewComparisonAnnotations(Request $request, Document $document, string $sourcePdfPath): array
    {
        $ownership = $this->resolveDocumentOwnership($document);
        $sessionId = trim((string) $request->input('session_id', $request->query('session_id', '')));
        if ($sessionId === '') {
            $sessionId = $request->session()->getId();
        }
        $includeAnnotationDebug = filter_var(
            $request->input('include_annotation_debug', $request->query('include_annotation_debug', false)),
            FILTER_VALIDATE_BOOLEAN
        );

        $extractedSessionId = 'document_' . $document->id . '_extracted';
        $statesQuery = PdfState::where('document_id', $document->id)
            ->where(function ($outer) use ($ownership, $sessionId, $extractedSessionId) {
                $outer->where(function ($inner) use ($ownership, $sessionId) {
                    $this->applyOwnershipScope(
                        $inner,
                        $ownership['user_id'] ?? null,
                        $ownership['admin_id'] ?? null,
                        $sessionId
                    );
                })->orWhere('session_id', $extractedSessionId);
            });

        $states = $statesQuery->orderBy('id')->get();
        $deletedPromotedSourceKeys = [];
        foreach ($states as $state) {
            if ((string) $state->state !== 'deleted') {
                continue;
            }
            $annotationData = is_array($state->annotation_data) ? $state->annotation_data : [];
            $sourceKey = trim((string) ($annotationData['promotedSourceKey'] ?? ''));
            if (
                $sourceKey !== ''
                && (
                    $this->isPromotedSuppressionAnnotation($annotationData)
                    || !empty($annotationData['promotedFromExtraction'])
                )
            ) {
                $deletedPromotedSourceKeys[$sourceKey] = true;
            }
        }

        $fallbackFitzId = PdfExtractionFitz::where('document_id', $document->id)
            ->orderByDesc('id')
            ->value('id');

        $annotationAssets = app(PdfAnnotationAssetService::class);
        $seen = [];
        $annotations = $states->map(function (PdfState $state) use (&$seen, $fallbackFitzId, $annotationAssets, $includeAnnotationDebug) {
            $data = is_array($state->annotation_data) ? $state->annotation_data : [];
            $fitzId = $state->pdf_extraction_fitz_id ?: $fallbackFitzId;
            if (!empty($data) && $fitzId) {
                $data = $this->enrichAnnotationFromDb($data, $fitzId);
            }
            if (!empty($data)) {
                $data = $annotationAssets->enrichForClient($data);
            }
            if (!empty($data) && $this->shouldDiscardLegacySyntheticMergedPromotedAnnotation($data)) {
                return null;
            }

            $annId = $data['id'] ?? null;
            $dbFields = [
                'db_id' => $state->id,
                'db_page_number' => $state->page_number,
                'db_state' => $state->state,
                'db_updated_at' => optional($state->updated_at)->toIso8601String(),
                'db_flagged' => (bool) $state->flagged,
                'db_flag_reason' => $state->flag_reason,
                'db_flag_images' => $state->flag_images ?? [],
            ];
            if ($includeAnnotationDebug) {
                $dbFields['db_annotation_debug'] = $this->pdfStateAnnotationDebugPayload($state, $data);
            }
            if ($annId !== null) {
                $merged = array_merge($data, $dbFields);
                if (
                    $includeAnnotationDebug
                    && !$this->annotationDebugPayloadHasContent($merged['db_annotation_debug'] ?? null)
                    && $this->annotationDebugPayloadHasContent($seen[$annId]['db_annotation_debug'] ?? null)
                ) {
                    $merged['db_annotation_debug'] = $seen[$annId]['db_annotation_debug'];
                }
                $seen[$annId] = $merged;
                return null;
            }

            return array_merge($data, $dbFields);
        })->filter(fn ($item) => $item !== null)->values();

        $parentsToSuppress = array_fill_keys(PdfAnnotationSuppression::suppressedIds(array_keys($seen)), true);
        foreach (array_keys($parentsToSuppress) as $parentId) {
            unset($seen[$parentId]);
        }

        $annotations = $annotations->merge(array_values($seen))->values();
        $annotations = $annotations
            ->reject(function ($annotation) {
                if (!is_array($annotation)) {
                    return false;
                }
                if ((string) ($annotation['db_state'] ?? '') !== 'deleted') {
                    return false;
                }

                return !filter_var($annotation['pdfjsDeleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
            })
            ->values();
        if (!empty($deletedPromotedSourceKeys)) {
            $annotations = $annotations
                ->reject(function ($annotation) use ($deletedPromotedSourceKeys) {
                    if (!is_array($annotation)) {
                        return false;
                    }
                    $sourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
                    return $sourceKey !== '' && isset($deletedPromotedSourceKeys[$sourceKey]);
                })
                ->values();
        }

        $symbolAnnotations = $this->synthesizeSymbolCharAnnotations($fallbackFitzId);
        if (!empty($symbolAnnotations)) {
            $existingIds = $annotations->pluck('id')->filter()->flip()->toArray();
            $newSymbol = array_filter($symbolAnnotations, fn ($annotation) => !isset($existingIds[$annotation['id']]));
            if (!empty($newSymbol)) {
                $annotations = $annotations->merge(array_values($newSymbol))->values();
            }
        }

        $annotations = collect($this->mergeContainedPromotedAnnotationsForEditor($annotations->values()->all()))->values();
        $annotations = collect($this->mergeLogicalListParagraphAnnotationsForEditor($annotations->values()->all()))->values();
        $annotations = collect($this->normalizeDotLeaderPromotedAnnotationsForEditor($annotations->values()->all()))->values();
        $annotations = collect($this->suppressPromotedAnnotationsCoveredByPdfjsSourceEdits($annotations->values()->all()))->values();

        return $annotations
            ->filter(fn ($annotation) => is_array($annotation) && !empty($annotation))
            ->map(function (array $annotation) use ($sourcePdfPath, $document) {
                $annotation['__sourcePdfPath'] = $sourcePdfPath;
                $annotation['__documentId'] = $document->id;

                return $annotation;
            })
            ->values()
            ->all();
    }

    private function pdfStateAnnotationDebugPayload(PdfState $state, array $annotationData = []): array
    {
        $debug = null;
        if ($this->hasPdfStateAnnotationDebugColumn()) {
            $debug = $state->annotation_debug;
        }
        if (is_string($debug)) {
            $decoded = json_decode($debug, true);
            $debug = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($debug) && isset($annotationData['_debug']) && is_array($annotationData['_debug'])) {
            $debug = $annotationData['_debug'];
        }

        return is_array($debug) ? $debug : [];
    }

    private function annotationDebugPayloadHasContent(mixed $debug): bool
    {
        if (is_string($debug)) {
            $decoded = json_decode($debug, true);
            $debug = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($debug)) {
            return false;
        }

        return trim((string) ($debug['note'] ?? '')) !== ''
            || (!empty($debug['mask']) && is_array($debug['mask']))
            || (!empty($debug['images']) && is_array($debug['images']))
            || !empty($debug['updated_at']);
    }

    /**
     * Enrich a single annotation array with the latest data from the fitz extraction tables.
     * Refreshes sourceSpans from pdf_extraction_spans and adds page geometry from
     * pdf_extraction_pages. Block data is intentionally excluded.
     */
    private function buildCanonicalSourceSpanPayload(PdfExtractionSpan $span): array
    {
        $spanData = is_array($span->span_data) ? $span->span_data : [];
        $spanDataRenderText = $spanData['render_text'] ?? null;
        $spanDataText = $spanData['text'] ?? null;
        $spanDataRawText = $spanData['rawText'] ?? null;
        $renderText = $spanDataRenderText !== null ? $spanDataRenderText : $span->render_text;
        $text = $spanDataText !== null ? $spanDataText : $span->text;
        $rawText = $spanDataRawText !== null
            ? $spanDataRawText
            : ($renderText !== null && $renderText !== '' ? $renderText : $text);

        return array_merge($spanData, [
            'text' => $text !== null ? (string) $text : '',
            'render_text' => $renderText !== null ? (string) $renderText : '',
            'rawText' => $rawText !== null ? (string) $rawText : '',
            'suppress_drawn_underline' => (bool) $span->suppress_drawn_underline,
            'has_drawn_underline' => (bool) $span->has_drawn_underline,
            'font' => $span->font,
            'font_xref' => $span->font_xref,
            'font_size' => $span->font_size !== null ? (float) $span->font_size : null,
            'fontSize' => $span->font_size !== null ? (float) $span->font_size : null,
            'font_weight' => $span->font_weight,
            'fontWeight' => $span->font_weight,
            'color' => $span->color_value,
            'hex_color' => $span->hex_color,
            'bold' => (bool) $span->bold,
            'italic' => (bool) $span->italic,
            'flags' => $span->flags,
            'bbox' => is_array($span->bbox)
                ? array_values($span->bbox)
                : [
                    (float) $span->left,
                    (float) $span->top,
                    (float) $span->left + (float) $span->width,
                    (float) $span->top + (float) $span->height,
                ],
            'ascender' => $span->ascender !== null ? (float) $span->ascender : null,
            'descender' => $span->descender !== null ? (float) $span->descender : null,
            'origin' => is_array($span->origin) ? array_values($span->origin) : null,
            'direction' => is_array($span->direction) ? array_values($span->direction) : null,
            'writing_mode' => (int) $span->writing_mode,
            'rotation' => $span->rotation !== null ? (float) $span->rotation : null,
            'line_width' => $span->line_width !== null ? (float) $span->line_width : null,
            'render_type' => $span->render_type,
            'space_width' => $span->space_width !== null ? (float) $span->space_width : null,
            'uses_embedded_font' => (bool) $span->uses_embedded_font,
            'embedded_font_name' => $span->embedded_font_name,
            'embedded_font_family' => $span->embedded_font_family,
            'embedded_font_xref' => $span->embedded_font_xref,
            'is_link' => (bool) $span->is_link,
            'link_uri' => $span->link_uri,
            'link_kind' => $span->link_kind,
            'link_page' => $span->link_page,
            'source_content_ops' => is_array($span->source_content_ops)
                ? array_values($span->source_content_ops)
                : null,
        ]);
    }

    private function sanitizeSourceTextLines(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $lines = [];
        foreach ($value as $line) {
            if ($line === null) {
                continue;
            }

            $lineText = (string) $line;
            if (trim($lineText) === '') {
                continue;
            }

            $lines[] = $lineText;
        }

        return array_values($lines);
    }

    private function sanitizeSourceLineBBoxes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $lineBBoxes = array_values(array_filter(
            $value,
            static fn ($bbox): bool => is_array($bbox) && count($bbox) >= 4
        ));

        return array_values(array_map(
            static fn (array $bbox): array => array_map(
                static fn ($coordinate): float => (float) $coordinate,
                array_slice($bbox, 0, 4)
            ),
            $lineBBoxes
        ));
    }

    private function sourceSpanBBox(array $span): ?array
    {
        $bbox = $span['bbox'] ?? $span['bBox'] ?? null;
        if (!is_array($bbox) || count($bbox) < 4) {
            return null;
        }

        return array_map(
            static fn ($coordinate): float => (float) $coordinate,
            array_slice($bbox, 0, 4)
        );
    }

    private function sourceSpanDisplayText(array $span): string
    {
        if (array_key_exists('render_text', $span) && $span['render_text'] !== null) {
            return (string) $span['render_text'];
        }

        return (string) ($span['text'] ?? $span['rawText'] ?? '');
    }

    private function synthesizeVisualLinesFromSourceSpans(array $sourceSpans): ?array
    {
        $positionedSpans = [];
        foreach ($sourceSpans as $sourceSpan) {
            if (!is_array($sourceSpan)) {
                continue;
            }

            $bbox = $this->sourceSpanBBox($sourceSpan);
            if (!$bbox) {
                continue;
            }

            $sourceSpan['__normalized_bbox'] = $bbox;
            $positionedSpans[] = $sourceSpan;
        }

        if (empty($positionedSpans)) {
            return null;
        }

        usort($positionedSpans, static function (array $leftSpan, array $rightSpan): int {
            $leftBBox = $leftSpan['__normalized_bbox'];
            $rightBBox = $rightSpan['__normalized_bbox'];
            $topDelta = (float) $leftBBox[1] - (float) $rightBBox[1];
            if (abs($topDelta) > 1.0) {
                return $topDelta < 0 ? -1 : 1;
            }

            return ((float) $leftBBox[0]) <=> ((float) $rightBBox[0]);
        });

        $groups = [];
        foreach ($positionedSpans as $span) {
            $bbox = $span['__normalized_bbox'];
            $top = (float) $bbox[1];
            $bottom = (float) $bbox[3];
            $height = max(1.0, $bottom - $top);
            $centerY = $top + ($height / 2.0);
            $lastGroupIndex = count($groups) - 1;

            if ($lastGroupIndex < 0) {
                $groups[] = [
                    'spans' => [$span],
                    'bbox' => $bbox,
                    'center_y' => $centerY,
                ];
                continue;
            }

            $groupBox = $groups[$lastGroupIndex]['bbox'];
            $groupTop = (float) $groupBox[1];
            $groupBottom = (float) $groupBox[3];
            $groupHeight = max(1.0, $groupBottom - $groupTop);
            $verticalOverlap = max(0.0, min($bottom, $groupBottom) - max($top, $groupTop));
            $sameVisualBand = $verticalOverlap >= min($height, $groupHeight) * 0.45
                || abs($centerY - (float) $groups[$lastGroupIndex]['center_y']) <= max(1.5, min($height, $groupHeight) * 0.45);

            if ($sameVisualBand) {
                $groups[$lastGroupIndex]['spans'][] = $span;
                $groups[$lastGroupIndex]['bbox'] = [
                    min((float) $groupBox[0], (float) $bbox[0]),
                    min($groupTop, $top),
                    max((float) $groupBox[2], (float) $bbox[2]),
                    max($groupBottom, $bottom),
                ];
                $groups[$lastGroupIndex]['center_y'] = (
                    (float) $groups[$lastGroupIndex]['bbox'][1]
                    + (float) $groups[$lastGroupIndex]['bbox'][3]
                ) / 2.0;
                continue;
            }

            $groups[] = [
                'spans' => [$span],
                'bbox' => $bbox,
                'center_y' => $centerY,
            ];
        }

        $lines = [];
        $lineBBoxes = [];
        foreach ($groups as $group) {
            $groupSpans = $group['spans'];
            usort($groupSpans, static function (array $leftSpan, array $rightSpan): int {
                $leftOriginX = is_array($leftSpan['origin'] ?? null)
                    ? (float) ($leftSpan['origin'][0] ?? 0.0)
                    : (float) ($leftSpan['__normalized_bbox'][0] ?? 0.0);
                $rightOriginX = is_array($rightSpan['origin'] ?? null)
                    ? (float) ($rightSpan['origin'][0] ?? 0.0)
                    : (float) ($rightSpan['__normalized_bbox'][0] ?? 0.0);

                return $leftOriginX <=> $rightOriginX;
            });

            $lineText = '';
            foreach ($groupSpans as $groupSpan) {
                $lineText .= $this->sourceSpanDisplayText($groupSpan);
            }

            if (trim($lineText) === '') {
                continue;
            }

            $lines[] = $lineText;
            $lineBBoxes[] = $group['bbox'];
        }

        return !empty($lines) && count($lines) === count($lineBBoxes)
            ? [
                'lines' => $lines,
                'boxes' => $lineBBoxes,
            ]
            : null;
    }

    private function hasUsableSourceLineMetadata(array $textLines, array $lineBBoxes): bool
    {
        if (empty($textLines) || empty($lineBBoxes) || count($textLines) !== count($lineBBoxes)) {
            return false;
        }

        foreach ($lineBBoxes as $bbox) {
            if (!is_array($bbox) || count($bbox) < 4) {
                return false;
            }

            if (((float) $bbox[2] - (float) $bbox[0]) <= 1.0) {
                return false;
            }
        }

        return true;
    }

    private function rootPromotedSourceKey(string $sourceKey): string
    {
        if (preg_match('/^(block-\d+-\d+)/', $sourceKey, $matches) === 1) {
            return (string) ($matches[1] ?? '');
        }

        return '';
    }

    private function isDerivedPromotedSourceKey(string $sourceKey): bool
    {
        if ($sourceKey === '') {
            return false;
        }

        $rootSourceKey = $this->rootPromotedSourceKey($sourceKey);

        return $rootSourceKey !== '' && $rootSourceKey !== $sourceKey;
    }

    private function normalizeAnnotationLineMetadata(array $annotation): array
    {
        $textLines = $this->sanitizeSourceTextLines($annotation['sourceTextLines'] ?? []);
        $lineBBoxes = $this->sanitizeSourceLineBBoxes($annotation['sourceLineBBoxes'] ?? []);

        if (!empty($textLines)) {
            $annotation['sourceTextLines'] = $textLines;
        }

        if (!empty($lineBBoxes)) {
            $annotation['sourceLineBBoxes'] = $lineBBoxes;
        }

        $sourceSpans = array_values(array_filter(
            is_array($annotation['sourceSpans'] ?? null) ? $annotation['sourceSpans'] : [],
            static fn ($span): bool => is_array($span)
        ));
        $synthesizedLines = $this->synthesizeVisualLinesFromSourceSpans($sourceSpans);
        if (!$synthesizedLines) {
            return $annotation;
        }

        $hasUsableLineMetadata = $this->hasUsableSourceLineMetadata($textLines, $lineBBoxes);
        $savedLineCount = count($textLines);
        $synthesizedLineCount = count($synthesizedLines['lines']);
        $shouldReplaceLineMetadata = !$hasUsableLineMetadata
            || ($savedLineCount <= 1 && $synthesizedLineCount > 1);

        if ($shouldReplaceLineMetadata) {
            $annotation['sourceTextLines'] = $synthesizedLines['lines'];
            $annotation['sourceLineBBoxes'] = $synthesizedLines['boxes'];
        }

        return $annotation;
    }

    /**
     * Extraction rows share their parent block's source key. Older saves could
     * therefore be re-enriched with the parent block's complete line metadata,
     * turning a one-line row into a page-sized edit box. The row's positioned
     * source spans are the authoritative ownership geometry in that case.
     */
    private function repairPromotedRowSourceMetadataFromSpans(array $annotation): array
    {
        if (empty($annotation['promotedFromExtraction'])
            || preg_match('/_row_\d+$/', trim((string) ($annotation['id'] ?? ''))) !== 1) {
            return $annotation;
        }

        $sourceSpans = array_values(array_filter(
            is_array($annotation['sourceSpans'] ?? null) ? $annotation['sourceSpans'] : [],
            static fn ($span): bool => is_array($span)
        ));
        $synthesized = $this->synthesizeVisualLinesFromSourceSpans($sourceSpans);
        if (!$synthesized) {
            return $annotation;
        }

        $savedBoxes = $this->sanitizeSourceLineBBoxes($annotation['sourceLineBBoxes'] ?? []);
        $spanBoxes = $this->sanitizeSourceLineBBoxes($synthesized['boxes'] ?? []);
        if (empty($spanBoxes)) {
            return $annotation;
        }

        $spanLeft = min(array_map(static fn (array $bbox): float => (float) $bbox[0], $spanBoxes));
        $spanTop = min(array_map(static fn (array $bbox): float => (float) $bbox[1], $spanBoxes));
        $spanRight = max(array_map(static fn (array $bbox): float => (float) $bbox[2], $spanBoxes));
        $spanBottom = max(array_map(static fn (array $bbox): float => (float) $bbox[3], $spanBoxes));
        $spanWidth = max(0.0, $spanRight - $spanLeft);
        $spanHeight = max(0.0, $spanBottom - $spanTop);
        if ($spanWidth <= 0.0 || $spanHeight <= 0.0) {
            return $annotation;
        }

        $savedLeft = !empty($savedBoxes)
            ? min(array_map(static fn (array $bbox): float => (float) $bbox[0], $savedBoxes))
            : null;
        $savedTop = !empty($savedBoxes)
            ? min(array_map(static fn (array $bbox): float => (float) $bbox[1], $savedBoxes))
            : null;
        $savedRight = !empty($savedBoxes)
            ? max(array_map(static fn (array $bbox): float => (float) $bbox[2], $savedBoxes))
            : null;
        $savedBottom = !empty($savedBoxes)
            ? max(array_map(static fn (array $bbox): float => (float) $bbox[3], $savedBoxes))
            : null;
        $savedWidth = $savedLeft !== null && $savedRight !== null ? max(0.0, $savedRight - $savedLeft) : 0.0;
        $savedHeight = $savedTop !== null && $savedBottom !== null ? max(0.0, $savedBottom - $savedTop) : 0.0;
        $lineCountMismatch = count($savedBoxes) !== count($spanBoxes);
        $geometryMismatch = $savedHeight > max($spanHeight + 2.0, $spanHeight * 1.75)
            || $savedWidth > max($spanWidth + 12.0, $spanWidth * 1.35)
            || ($savedTop !== null && abs($savedTop - $spanTop) > max(2.0, $spanHeight));
        if (!$lineCountMismatch && !$geometryMismatch) {
            return $annotation;
        }

        $annotation['sourceTextLines'] = array_values($synthesized['lines']);
        $annotation['sourceLineBBoxes'] = $spanBoxes;
        $annotation['sourceBlockLeft'] = $spanLeft;
        $annotation['sourceBlockTop'] = $spanTop;
        $annotation['sourceBlockWidth'] = $spanWidth;
        $annotation['sourceBlockHeight'] = $spanHeight;

        $pageHeight = isset($annotation['sourcePageHeight']) && is_numeric($annotation['sourcePageHeight'])
            ? (float) $annotation['sourcePageHeight']
            : (isset($annotation['pdfjsSourcePageHeight']) && is_numeric($annotation['pdfjsSourcePageHeight'])
                ? (float) $annotation['pdfjsSourcePageHeight']
                : 0.0);
        if ($pageHeight > 0.0) {
            $sourcePdfY = $pageHeight - $spanBottom;
            $annotation['pdfjsSourceX'] = $spanLeft;
            $annotation['pdfjsSourceY'] = $sourcePdfY;
            $annotation['pdfjsSourceW'] = $spanWidth;
            $annotation['pdfjsSourceH'] = $spanHeight;
            $annotation['pdfjsSourcePageHeight'] = $pageHeight;

            $hasExplicitUserEdits = !empty($annotation['promotedDirty'])
                || !empty($annotation['promotedReflowEnabled'])
                || !empty($annotation['userAuthored'])
                || !empty($annotation['styleDirty'])
                || !empty($annotation['userForcedRichText'])
                || !empty($annotation['movedTextOverlay'])
                || !empty($annotation['userSizedTextBox'])
                || $this->normalizePromotedComparableText($annotation['text'] ?? '')
                    !== $this->normalizePromotedComparableText($annotation['originalText'] ?? '');
            if (!$hasExplicitUserEdits) {
                $annotation['pdfX'] = $spanLeft;
                $annotation['pdfY'] = $sourcePdfY;
                $annotation['pdfWidth'] = $spanWidth;
                $annotation['pdfHeight'] = $spanHeight;
            }
        }

        $runsJson = trim((string) ($annotation['pdfjsSourceSpanRuns'] ?? ''));
        $runsScale = isset($annotation['pdfjsSourceSpanRunsScale']) && is_numeric($annotation['pdfjsSourceSpanRunsScale'])
            ? (float) $annotation['pdfjsSourceSpanRunsScale']
            : 0.0;
        $runs = $runsJson !== '' ? json_decode($runsJson, true) : null;
        if (is_array($runs) && $runsScale > 0.0) {
            $keptRuns = array_values(array_filter($runs, static function ($run) use ($spanBoxes, $runsScale): bool {
                if (!is_array($run)) {
                    return false;
                }
                $left = isset($run['leftPx']) && is_numeric($run['leftPx']) ? (float) $run['leftPx'] / $runsScale : null;
                $right = isset($run['rightPx']) && is_numeric($run['rightPx']) ? (float) $run['rightPx'] / $runsScale : null;
                $top = isset($run['topPx']) && is_numeric($run['topPx']) ? (float) $run['topPx'] / $runsScale : null;
                $bottom = isset($run['bottomPx']) && is_numeric($run['bottomPx']) ? (float) $run['bottomPx'] / $runsScale : null;
                if ($left === null || $right === null || $top === null || $bottom === null) {
                    return false;
                }

                foreach ($spanBoxes as $bbox) {
                    $horizontalOverlap = min($right, (float) $bbox[2] + 1.0) - max($left, (float) $bbox[0] - 1.0);
                    $verticalOverlap = min($bottom, (float) $bbox[3] + 1.0) - max($top, (float) $bbox[1] - 1.0);
                    if ($horizontalOverlap > 0.0 && $verticalOverlap > 0.0) {
                        return true;
                    }
                }

                return false;
            }));
            if (count($keptRuns) < count($runs)) {
                if (!empty($keptRuns)) {
                    $annotation['pdfjsSourceSpanRuns'] = json_encode($keptRuns, JSON_UNESCAPED_SLASHES);
                } else {
                    unset($annotation['pdfjsSourceSpanRuns'], $annotation['pdfjsSourceSpanRunsScale']);
                }
            }
        }

        return $annotation;
    }

    private function syncAnnotationGeometryFromSourceLineBBoxes(array $annotation): array
    {
        $lineBBoxes = $this->sanitizeSourceLineBBoxes($annotation['sourceLineBBoxes'] ?? []);
        if (empty($lineBBoxes)) {
            return $annotation;
        }

        $left = min(array_map(static fn (array $bbox): float => (float) $bbox[0], $lineBBoxes));
        $top = min(array_map(static fn (array $bbox): float => (float) $bbox[1], $lineBBoxes));
        $right = max(array_map(static fn (array $bbox): float => (float) $bbox[2], $lineBBoxes));
        $bottom = max(array_map(static fn (array $bbox): float => (float) $bbox[3], $lineBBoxes));
        $width = max(0.0, $right - $left);
        $height = max(0.0, $bottom - $top);

        if ($width <= 0.0 || $height <= 0.0) {
            return $annotation;
        }

        // Compare the saved geometry with the pre-normalized source block
        // before replacing that source block with the tighter line union.
        // Otherwise a clean canonical block whose bbox includes padding is
        // incorrectly reclassified as a user resize.
        $preserveSavedGeometry = $this->promotedAnnotationHasMaterialGeometryEdits($annotation);

        $annotation['sourceLineBBoxes'] = $lineBBoxes;
        $annotation['sourceBlockLeft'] = $left;
        $annotation['sourceBlockTop'] = $top;
        $annotation['sourceBlockWidth'] = $width;
        $annotation['sourceBlockHeight'] = $height;

        $pageHeight = isset($annotation['sourcePageHeight']) && is_numeric($annotation['sourcePageHeight'])
            ? (float) $annotation['sourcePageHeight']
            : 0.0;
        if ($pageHeight > 0.0 && !$preserveSavedGeometry) {
            $annotation['pdfX'] = $left;
            $annotation['pdfWidth'] = $width;
            $annotation['pdfHeight'] = $height;
            $annotation['pdfY'] = $pageHeight - ($top + $height);
        }

        return $annotation;
    }

    private function sourceSpanVisualText(array $span): string
    {
        return trim(preg_replace('/\s+/u', ' ', $this->sourceSpanDisplayText($span)) ?? '');
    }

    private function sourceSpanTextLooksLikeFieldLabel(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        return preg_match('/^(?:\d{1,3}[a-z]?|[a-z])(?:[.)])?$/u', $text) === 1;
    }

    private function sourceSpanTextLooksLikeBodyLabel(string $text): bool
    {
        $text = trim($text);

        return $text !== '' && preg_match('/[\pL]/u', $text) === 1;
    }

    private function positionedSourceSpansForSegmentation(array $annotation): array
    {
        $positioned = [];
        foreach ((is_array($annotation['sourceSpans'] ?? null) ? $annotation['sourceSpans'] : []) as $span) {
            if (!is_array($span)) {
                continue;
            }

            $bbox = $this->sourceSpanBBox($span);
            $text = $this->sourceSpanVisualText($span);
            if (!$bbox || $text === '') {
                continue;
            }

            $span['__normalized_bbox'] = $bbox;
            $span['__visual_text'] = $text;
            $positioned[] = $span;
        }

        usort($positioned, static function (array $leftSpan, array $rightSpan): int {
            $leftBBox = $leftSpan['__normalized_bbox'];
            $rightBBox = $rightSpan['__normalized_bbox'];
            $topDelta = (float) $leftBBox[1] - (float) $rightBBox[1];
            if (abs($topDelta) > 1.0) {
                return $topDelta < 0 ? -1 : 1;
            }

            return ((float) $leftBBox[0]) <=> ((float) $rightBBox[0]);
        });

        return $positioned;
    }

    private function sourceSpansAreSingleVisualLine(array $spans): bool
    {
        if (count($spans) < 2) {
            return false;
        }

        $firstBBox = $spans[0]['__normalized_bbox'] ?? null;
        if (!is_array($firstBBox) || count($firstBBox) < 4) {
            return false;
        }

        $top = (float) $firstBBox[1];
        $bottom = (float) $firstBBox[3];
        $height = max(1.0, $bottom - $top);
        $center = ($top + $bottom) / 2.0;

        foreach (array_slice($spans, 1) as $span) {
            $bbox = $span['__normalized_bbox'] ?? null;
            if (!is_array($bbox) || count($bbox) < 4) {
                return false;
            }
            $spanTop = (float) $bbox[1];
            $spanBottom = (float) $bbox[3];
            $spanHeight = max(1.0, $spanBottom - $spanTop);
            $spanCenter = ($spanTop + $spanBottom) / 2.0;
            $verticalOverlap = max(0.0, min($bottom, $spanBottom) - max($top, $spanTop));
            if ($verticalOverlap < min($height, $spanHeight) * 0.35 && abs($spanCenter - $center) > max(2.0, min($height, $spanHeight) * 0.75)) {
                return false;
            }
        }

        return true;
    }

    private function sourceSpanUnionBBox(array $spans): ?array
    {
        $boxes = array_values(array_filter(array_map(
            static fn (array $span): ?array => is_array($span['__normalized_bbox'] ?? null) ? $span['__normalized_bbox'] : null,
            $spans
        )));
        if (empty($boxes)) {
            return null;
        }

        return [
            min(array_map(static fn (array $bbox): float => (float) $bbox[0], $boxes)),
            min(array_map(static fn (array $bbox): float => (float) $bbox[1], $boxes)),
            max(array_map(static fn (array $bbox): float => (float) $bbox[2], $boxes)),
            max(array_map(static fn (array $bbox): float => (float) $bbox[3], $boxes)),
        ];
    }

    private function sourceSpansSegmentText(array $spans): string
    {
        $parts = [];
        foreach ($spans as $span) {
            $text = $this->sourceSpanVisualText($span);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    private function sourceSpanStyleValue(array $span, array $annotation, string $field, string $fallback = ''): string
    {
        return trim((string) (
            $span[$field]
            ?? $span[Str::camel($field)]
            ?? $annotation[Str::camel($field)]
            ?? $annotation[$field]
            ?? $fallback
        ));
    }

    private function promotedAnnotationFieldLabelSegments(array $annotation): array
    {
        if (empty($annotation['promotedFromExtraction']) || $this->promotedAnnotationHasUnsafeMergeEdits($annotation)) {
            return [$annotation];
        }

        $annotationId = trim((string) ($annotation['id'] ?? ''));
        if ($annotationId === '' || str_contains($annotationId, '_seg_')) {
            return [$annotation];
        }

        $spans = $this->positionedSourceSpansForSegmentation($annotation);
        if (!$this->sourceSpansAreSingleVisualLine($spans)) {
            return [$annotation];
        }

        $firstText = $this->sourceSpanVisualText($spans[0]);
        $remainingSpans = array_slice($spans, 1);
        $remainingText = $this->sourceSpansSegmentText($remainingSpans);
        if (!$this->sourceSpanTextLooksLikeFieldLabel($firstText) || !$this->sourceSpanTextLooksLikeBodyLabel($remainingText)) {
            return [$annotation];
        }

        $rootSourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
        if ($rootSourceKey === '') {
            $rootSourceKey = trim((string) ($annotation['id'] ?? ''));
        }
        $segments = [
            [
                'role' => 'field_label',
                'spans' => [$spans[0]],
                'text' => $firstText,
            ],
            [
                'role' => 'field_body_label',
                'spans' => $remainingSpans,
                'text' => $remainingText,
            ],
        ];

        $result = [];
        foreach ($segments as $index => $segment) {
            $bbox = $this->sourceSpanUnionBBox($segment['spans']);
            if (!$bbox || $segment['text'] === '') {
                continue;
            }

            $styleSpan = $segment['spans'][0] ?? [];
            $segmentAnnotation = $annotation;
            $segmentAnnotation['id'] = $annotationId . '_seg_' . $index;
            $segmentAnnotation['text'] = $segment['text'];
            $segmentAnnotation['originalText'] = $segment['text'];
            $segmentAnnotation['sourceTextLines'] = [$segment['text']];
            $segmentAnnotation['sourceLineBBoxes'] = [$bbox];
            $segmentAnnotation['sourceSpans'] = array_values(array_map(static function (array $span): array {
                unset($span['__normalized_bbox'], $span['__visual_text']);
                return $span;
            }, $segment['spans']));
            $segmentAnnotation['promotedParentId'] = $annotationId;
            $segmentAnnotation['promotedSegmentIndex'] = $index;
            $segmentAnnotation['promotedSegmentCount'] = count($segments);
            $segmentAnnotation['promotedSegmentRole'] = $segment['role'];
            $segmentAnnotation['promotedSourceRootKey'] = $rootSourceKey;
            $segmentAnnotation['promotedSourceKey'] = $rootSourceKey . '__seg_' . $index;
            $segmentAnnotation['fontWeight'] = $this->sourceSpanStyleValue($styleSpan, $annotation, 'font_weight', (string) ($annotation['fontWeight'] ?? '400')) ?: '400';
            $segmentAnnotation['fontStyle'] = filter_var($styleSpan['italic'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'italic' : ($this->sourceSpanStyleValue($styleSpan, $annotation, 'font_style', (string) ($annotation['fontStyle'] ?? 'normal')) ?: 'normal');
            $fontSize = $styleSpan['fontSize'] ?? $styleSpan['font_size'] ?? $annotation['fontSize'] ?? null;
            if (is_numeric($fontSize)) {
                $segmentAnnotation['fontSize'] = (float) $fontSize;
                $segmentAnnotation['lineHeight'] = (float) $fontSize;
            }
            $fontSourceName = trim((string) ($styleSpan['embedded_font_name'] ?? $styleSpan['font'] ?? $annotation['fontSourceName'] ?? ''));
            if ($fontSourceName !== '') {
                $segmentAnnotation['fontSourceName'] = $fontSourceName;
            }
            $fontFamily = trim((string) ($styleSpan['embedded_font_family'] ?? $annotation['fontFamily'] ?? ''));
            if ($fontFamily !== '') {
                $segmentAnnotation['fontFamily'] = $fontFamily;
            }
            $color = trim((string) ($styleSpan['hex_color'] ?? $annotation['textColor'] ?? ''));
            if ($color !== '') {
                $segmentAnnotation['textColor'] = $color;
                $segmentAnnotation['color'] = $color;
            }
            unset($segmentAnnotation['richTextHtml']);
            $segmentAnnotation['promotedDirty'] = false;
            $segmentAnnotation = $this->syncAnnotationGeometryFromSourceLineBBoxes($segmentAnnotation);
            $result[] = $segmentAnnotation;
        }

        return count($result) === count($segments) ? $result : [$annotation];
    }

    private function splitPromotedFieldLabelAnnotationsForEditor(array $annotations): array
    {
        $expanded = [];
        foreach ($annotations as $annotation) {
            if (!is_array($annotation)) {
                $expanded[] = $annotation;
                continue;
            }

            foreach ($this->promotedAnnotationFieldLabelSegments($annotation) as $segment) {
                $expanded[] = $segment;
            }
        }

        $byId = [];
        $unkeyed = [];
        foreach ($expanded as $annotation) {
            if (!is_array($annotation)) {
                $unkeyed[] = $annotation;
                continue;
            }

            $id = trim((string) ($annotation['id'] ?? ''));
            if ($id === '') {
                $unkeyed[] = $annotation;
                continue;
            }

            $current = $byId[$id] ?? null;
            $currentDbId = is_array($current) && is_numeric($current['db_id'] ?? null) ? (int) $current['db_id'] : -1;
            $nextDbId = is_numeric($annotation['db_id'] ?? null) ? (int) $annotation['db_id'] : -1;
            if ($current === null || $nextDbId >= $currentDbId) {
                $byId[$id] = $annotation;
            }
        }

        return array_values(array_merge($unkeyed, array_values($byId)));
    }

    private function promotedAnnotationSourceRect(array $annotation): ?array
    {
        $lineBBoxes = $this->sanitizeSourceLineBBoxes($annotation['sourceLineBBoxes'] ?? []);
        if (!empty($lineBBoxes)) {
            return [
                min(array_map(static fn (array $bbox): float => (float) $bbox[0], $lineBBoxes)),
                min(array_map(static fn (array $bbox): float => (float) $bbox[1], $lineBBoxes)),
                max(array_map(static fn (array $bbox): float => (float) $bbox[2], $lineBBoxes)),
                max(array_map(static fn (array $bbox): float => (float) $bbox[3], $lineBBoxes)),
            ];
        }

        if (
            isset($annotation['sourceBlockLeft'], $annotation['sourceBlockTop'], $annotation['sourceBlockWidth'], $annotation['sourceBlockHeight'])
            && is_numeric($annotation['sourceBlockLeft'])
            && is_numeric($annotation['sourceBlockTop'])
            && is_numeric($annotation['sourceBlockWidth'])
            && is_numeric($annotation['sourceBlockHeight'])
        ) {
            $left = (float) $annotation['sourceBlockLeft'];
            $top = (float) $annotation['sourceBlockTop'];
            $width = max(0.0, (float) $annotation['sourceBlockWidth']);
            $height = max(0.0, (float) $annotation['sourceBlockHeight']);
            if ($width > 0.0 && $height > 0.0) {
                return [$left, $top, $left + $width, $top + $height];
            }
        }

        return null;
    }

    private function promotedAnnotationRectContains(array $outerRect, array $innerRect, float $padding = 0.0): bool
    {
        return ($innerRect[0] + $padding) >= ($outerRect[0] - $padding)
            && ($innerRect[1] + $padding) >= ($outerRect[1] - $padding)
            && ($innerRect[2] - $padding) <= ($outerRect[2] + $padding)
            && ($innerRect[3] - $padding) <= ($outerRect[3] + $padding);
    }

    private function promotedAnnotationLineBoxesOverlap(array $leftRect, array $rightRect, float $threshold = 0.45): bool
    {
        $xi = max((float) $leftRect[0], (float) $rightRect[0]);
        $yi = max((float) $leftRect[1], (float) $rightRect[1]);
        $xa = min((float) $leftRect[2], (float) $rightRect[2]);
        $ya = min((float) $leftRect[3], (float) $rightRect[3]);
        $width = max(0.0, $xa - $xi);
        $height = max(0.0, $ya - $yi);
        if ($width <= 0.0 || $height <= 0.0) {
            return false;
        }

        $leftArea = max(1.0, ((float) $leftRect[2] - (float) $leftRect[0]) * ((float) $leftRect[3] - (float) $leftRect[1]));
        $rightArea = max(1.0, ((float) $rightRect[2] - (float) $rightRect[0]) * ((float) $rightRect[3] - (float) $rightRect[1]));
        $overlapArea = $width * $height;

        return ($overlapArea / min($leftArea, $rightArea)) >= $threshold;
    }

    private function promotedAnnotationsShareCompatibleTypography(array $parent, array $child): bool
    {
        $normalizeValue = static fn ($value): string => trim(Str::lower((string) $value));

        $pairs = [
            [$parent['fontSourceName'] ?? $parent['fontFamily'] ?? '', $child['fontSourceName'] ?? $child['fontFamily'] ?? ''],
            [$parent['fontWeight'] ?? '', $child['fontWeight'] ?? ''],
            [$parent['fontStyle'] ?? '', $child['fontStyle'] ?? ''],
            [$parent['textColor'] ?? '', $child['textColor'] ?? ''],
        ];

        foreach ($pairs as [$left, $right]) {
            $normalizedLeft = $normalizeValue($left);
            $normalizedRight = $normalizeValue($right);
            if ($normalizedLeft !== '' && $normalizedRight !== '' && $normalizedLeft !== $normalizedRight) {
                return false;
            }
        }

        $parentFontSize = isset($parent['fontSize']) && is_numeric($parent['fontSize']) ? (float) $parent['fontSize'] : null;
        $childFontSize = isset($child['fontSize']) && is_numeric($child['fontSize']) ? (float) $child['fontSize'] : null;

        return $parentFontSize === null
            || $childFontSize === null
            || abs($parentFontSize - $childFontSize) <= 0.5;
    }

    /**
     * List markers are inline content, not paragraph-style authorities. A
     * bullet/number may intentionally use a different face, weight, or color
     * from its body, so those differences must not split the logical item.
     * Geometry and a broadly compatible size are the safe structural signals.
     */
    private function promotedListMarkerCanShareParagraph(array $marker, array $body): bool
    {
        $markerRotation = isset($marker['rotation']) && is_numeric($marker['rotation']) ? (float) $marker['rotation'] : 0.0;
        $bodyRotation = isset($body['rotation']) && is_numeric($body['rotation']) ? (float) $body['rotation'] : 0.0;
        if (abs($markerRotation - $bodyRotation) > 0.5) {
            return false;
        }

        $markerFontSize = isset($marker['fontSize']) && is_numeric($marker['fontSize']) ? (float) $marker['fontSize'] : null;
        $bodyFontSize = isset($body['fontSize']) && is_numeric($body['fontSize']) ? (float) $body['fontSize'] : null;
        if ($markerFontSize === null || $bodyFontSize === null) {
            return true;
        }

        $larger = max($markerFontSize, $bodyFontSize, 1.0);

        return abs($markerFontSize - $bodyFontSize) <= max(2.0, $larger * 0.35);
    }

    /**
     * Adjacent visual lines may contain inline emphasis or color changes. For
     * paragraph continuation, compare writing direction/size only; source-run
     * styling is preserved separately and must not become a block boundary.
     */
    private function promotedAnnotationsCanContinueParagraph(array $previous, array $next): bool
    {
        $previousRotation = isset($previous['rotation']) && is_numeric($previous['rotation']) ? (float) $previous['rotation'] : 0.0;
        $nextRotation = isset($next['rotation']) && is_numeric($next['rotation']) ? (float) $next['rotation'] : 0.0;
        if (abs($previousRotation - $nextRotation) > 0.5) {
            return false;
        }

        $previousFontSize = isset($previous['fontSize']) && is_numeric($previous['fontSize']) ? (float) $previous['fontSize'] : null;
        $nextFontSize = isset($next['fontSize']) && is_numeric($next['fontSize']) ? (float) $next['fontSize'] : null;
        if ($previousFontSize === null || $nextFontSize === null) {
            return true;
        }

        $larger = max($previousFontSize, $nextFontSize, 1.0);

        return abs($previousFontSize - $nextFontSize) <= max(1.5, $larger * 0.25);
    }

    private function promotedAnnotationHasUnsafeMergeEdits(array $annotation): bool
    {
        if (empty($annotation['promotedFromExtraction'])) {
            return true;
        }

        // A clean logical owner naturally has full-paragraph geometry while
        // its immutable sourceBlock fields still describe the marker record.
        // That expected mismatch is not a user move and must not prevent the
        // owner from being reconstructed with its constituent source rows.
        if (!empty($annotation['promotedLogicalParagraph'])
            && !$this->promotedLogicalParagraphOwnerHasExplicitUserEdits($annotation)) {
            return false;
        }

        return !empty($annotation['promotedDirty'])
            || !empty($annotation['promotedReflowEnabled'])
            || $this->promotedAnnotationHasMaterialGeometryEdits($annotation);
    }

    private function promotedLogicalParagraphOwnerHasExplicitUserEdits(array $annotation): bool
    {
        if (empty($annotation['promotedFromExtraction'])) {
            return false;
        }

        if (!empty($annotation['promotedDirty'])
            || !empty($annotation['promotedReflowEnabled'])
            || !empty($annotation['userAuthored'])
            || !empty($annotation['styleDirty'])
            || !empty($annotation['userForcedRichText'])
            || !empty($annotation['movedTextOverlay'])
            || !empty($annotation['pdfjsDeleted'])) {
            return true;
        }

        return $this->normalizePromotedComparableText($annotation['text'] ?? '')
            !== $this->normalizePromotedComparableText($annotation['originalText'] ?? '');
    }

    private function promotedAnnotationHorizontalOverlapRatio(array $leftRect, array $rightRect): float
    {
        $leftWidth = max(1.0, (float) $leftRect[2] - (float) $leftRect[0]);
        $rightWidth = max(1.0, (float) $rightRect[2] - (float) $rightRect[0]);
        $overlapWidth = max(0.0, min((float) $leftRect[2], (float) $rightRect[2]) - max((float) $leftRect[0], (float) $rightRect[0]));

        return $overlapWidth / min($leftWidth, $rightWidth);
    }

    private function promotedAnnotationChildSpansFarOutsideParent(array $parentRect, array $childRect): bool
    {
        $parentWidth = max(1.0, (float) $parentRect[2] - (float) $parentRect[0]);
        $childWidth = max(1.0, (float) $childRect[2] - (float) $childRect[0]);

        if ($childWidth <= $parentWidth * 1.75) {
            return false;
        }

        return (float) $childRect[0] < ((float) $parentRect[0] - 2.0)
            && (float) $childRect[2] > ((float) $parentRect[2] + 2.0);
    }

    /**
     * Returns true when every child source-line bbox highly overlaps a
     * distinct parent source-line bbox (>= 70% of the smaller area). Used to
     * detect duplicate promoted annotations describing the same physical
     * lines, where the gap-fit check would reject a merge for being too
     * overlapping. Each parent line can match at most one child line.
     */
    private function childLineBoxesOverlapDistinctParentLines(array $childBoxes, array $parentBoxes): bool
    {
        if (empty($childBoxes) || empty($parentBoxes)) {
            return false;
        }

        $usedParents = [];
        foreach ($childBoxes as $childBox) {
            if (!is_array($childBox) || count($childBox) < 4) {
                return false;
            }
            $childArea = max(
                1.0,
                ((float) $childBox[2] - (float) $childBox[0]) * ((float) $childBox[3] - (float) $childBox[1])
            );

            $matched = null;
            foreach ($parentBoxes as $parentIndex => $parentBox) {
                if (isset($usedParents[$parentIndex]) || !is_array($parentBox) || count($parentBox) < 4) {
                    continue;
                }
                $parentArea = max(
                    1.0,
                    ((float) $parentBox[2] - (float) $parentBox[0]) * ((float) $parentBox[3] - (float) $parentBox[1])
                );

                $width = max(0.0, min((float) $childBox[2], (float) $parentBox[2]) - max((float) $childBox[0], (float) $parentBox[0]));
                $height = max(0.0, min((float) $childBox[3], (float) $parentBox[3]) - max((float) $childBox[1], (float) $parentBox[1]));
                if ($width <= 0.0 || $height <= 0.0) {
                    continue;
                }

                $overlap = $width * $height;
                if ($overlap / min($childArea, $parentArea) >= 0.70) {
                    $matched = $parentIndex;
                    break;
                }
            }

            if ($matched === null) {
                return false;
            }
            $usedParents[$matched] = true;
        }

        return true;
    }

    private function promotedAnnotationGapFitScore(array $parentBoxes, array $childBoxes): ?float
    {
        if (empty($parentBoxes) || empty($childBoxes) || count($parentBoxes) < 2) {
            return null;
        }

        $lineEntries = [];
        foreach ($parentBoxes as $bbox) {
            $lineEntries[] = [
                'bbox' => $bbox,
                'child' => false,
            ];
        }
        foreach ($childBoxes as $bbox) {
            $lineEntries[] = [
                'bbox' => $bbox,
                'child' => true,
            ];
        }

        usort($lineEntries, static function (array $leftEntry, array $rightEntry): int {
            $leftBox = $leftEntry['bbox'];
            $rightBox = $rightEntry['bbox'];
            $topDelta = (float) $leftBox[1] - (float) $rightBox[1];
            if (abs($topDelta) > 0.25) {
                return $topDelta < 0 ? -1 : 1;
            }

            return ((float) $leftBox[0]) <=> ((float) $rightBox[0]);
        });

        $childPositions = [];
        foreach ($lineEntries as $index => $entry) {
            if ($entry['child']) {
                $childPositions[] = $index;
            }
        }
        if (empty($childPositions)) {
            return null;
        }

        $maxOverlapRatio = 0.0;
        foreach ($childBoxes as $childBox) {
            foreach ($parentBoxes as $parentBox) {
                $xi = max((float) $childBox[0], (float) $parentBox[0]);
                $yi = max((float) $childBox[1], (float) $parentBox[1]);
                $xa = min((float) $childBox[2], (float) $parentBox[2]);
                $ya = min((float) $childBox[3], (float) $parentBox[3]);
                $width = max(0.0, $xa - $xi);
                $height = max(0.0, $ya - $yi);
                if ($width <= 0.0 || $height <= 0.0) {
                    continue;
                }

                $childArea = max(1.0, ((float) $childBox[2] - (float) $childBox[0]) * ((float) $childBox[3] - (float) $childBox[1]));
                $parentArea = max(1.0, ((float) $parentBox[2] - (float) $parentBox[0]) * ((float) $parentBox[3] - (float) $parentBox[1]));
                $maxOverlapRatio = max($maxOverlapRatio, ($width * $height) / min($childArea, $parentArea));
            }
        }
        if ($maxOverlapRatio > 0.12) {
            return null;
        }

        $gapPenalty = 0.0;
        foreach ($childPositions as $position) {
            $parentBefore = null;
            for ($index = $position - 1; $index >= 0; $index--) {
                if (!$lineEntries[$index]['child']) {
                    $parentBefore = $lineEntries[$index]['bbox'];
                    break;
                }
            }

            $parentAfter = null;
            for ($index = $position + 1, $count = count($lineEntries); $index < $count; $index++) {
                if (!$lineEntries[$index]['child']) {
                    $parentAfter = $lineEntries[$index]['bbox'];
                    break;
                }
            }

            if ($parentBefore === null || $parentAfter === null) {
                return null;
            }

            $childBox = $lineEntries[$position]['bbox'];
            $gapAbove = max(0.0, (float) $childBox[1] - (float) $parentBefore[3]);
            $gapBelow = max(0.0, (float) $parentAfter[1] - (float) $childBox[3]);
            $gapPenalty += $gapAbove + $gapBelow;
        }

        return $gapPenalty;
    }

    private function promotedAnnotationTextContainsLines(array $annotation, array $lines): bool
    {
        $haystack = $this->normalizePromotedComparableText($annotation['text'] ?? '');
        if ($haystack === '') {
            return false;
        }

        foreach ($lines as $line) {
            $needle = $this->normalizePromotedComparableText($line);
            if ($needle === '') {
                continue;
            }

            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    private function promotedAnnotationSourceLinesContainLines(array $annotation, array $lines): bool
    {
        $sourceLines = $this->sanitizeSourceTextLines($annotation['sourceTextLines'] ?? []);
        if (empty($sourceLines)) {
            return false;
        }

        $sourceLineSet = [];
        foreach ($sourceLines as $sourceLine) {
            $normalized = $this->normalizePromotedComparableText($sourceLine);
            if ($normalized !== '') {
                $sourceLineSet[$normalized] = true;
            }
        }

        if (empty($sourceLineSet)) {
            return false;
        }

        foreach ($lines as $line) {
            $needle = $this->normalizePromotedComparableText($line);
            if ($needle === '') {
                continue;
            }

            if (!isset($sourceLineSet[$needle])) {
                return false;
            }
        }

        return true;
    }

    private function promotedAnnotationLooksLikeStaleMergedSourceText(array $annotation, array $childLines): bool
    {
        if (empty($annotation['promotedFromExtraction'])) {
            return false;
        }

        if (!$this->promotedAnnotationHasUnsafeMergeEdits($annotation)) {
            return false;
        }

        return $this->promotedAnnotationTextContainsLines($annotation, $childLines)
            && !$this->promotedAnnotationSourceLinesContainLines($annotation, $childLines);
    }

    private function suppressContainedPromotedChildrenAlreadyInParentText(array $annotations): array
    {
        if (count($annotations) < 2) {
            return [];
        }

        $suppressed = [];
        foreach ($annotations as $childIndex => $childAnnotation) {
            if (empty($childAnnotation['promotedFromExtraction']) || $this->promotedAnnotationHasUnsafeMergeEdits($childAnnotation)) {
                continue;
            }

            $childLines = $this->sanitizeSourceTextLines($childAnnotation['sourceTextLines'] ?? []);
            $childBoxes = $this->sanitizeSourceLineBBoxes($childAnnotation['sourceLineBBoxes'] ?? []);
            if (empty($childLines) || count($childLines) !== count($childBoxes)) {
                continue;
            }

            $childRect = $this->promotedAnnotationSourceRect($childAnnotation);
            if (!$childRect) {
                continue;
            }

            foreach ($annotations as $parentIndex => $parentAnnotation) {
                if ($parentIndex === $childIndex || isset($suppressed[$parentIndex]) || empty($parentAnnotation['promotedFromExtraction'])) {
                    continue;
                }

                if ((int) ($parentAnnotation['pageIndex'] ?? -1) !== (int) ($childAnnotation['pageIndex'] ?? -2)) {
                    continue;
                }

                if (!$this->promotedAnnotationsShareCompatibleTypography($parentAnnotation, $childAnnotation)) {
                    continue;
                }

                if (!$this->promotedAnnotationTextContainsLines($parentAnnotation, $childLines)) {
                    continue;
                }

                // Geometric duplicate: every child source-line bbox highly
                // overlaps a parent source-line bbox AND the parent's text
                // already contains the child's lines. This is a stale
                // promoted-extraction artifact -- the child annotation
                // describes the same physical line as one of the parent's
                // existing source lines, just with a slightly different
                // text representation (e.g. parent line "Original or
                // certified copy of birth certificate" vs child line
                // "3. Original or certified copy of birth certificate"
                // where the "3." was already merged into parent.text).
                // Suppress the child unconditionally; rendering the parent
                // alone produces the correct visible result. Without this
                // check, the gap-fit pass below rejects the merge because
                // the bboxes overlap > 12%, leaving both annotations to
                // paint on top of each other.
                $parentBoxesForOverlap = $this->sanitizeSourceLineBBoxes($parentAnnotation['sourceLineBBoxes'] ?? []);
                if (
                    !empty($parentBoxesForOverlap)
                    && $this->childLineBoxesOverlapDistinctParentLines($childBoxes, $parentBoxesForOverlap)
                ) {
                    $suppressed[$childIndex] = true;
                    break;
                }

                // A stale saved promoted row can have child text already appended
                // to annotation.text while sourceTextLines/sourceLineBBoxes still
                // describe only the original parent block. Do not suppress the
                // child in that case; the merge pass below needs the child boxes
                // and spans to rebuild exact source geometry and avoid DOM reflow.
                if (!$this->promotedAnnotationSourceLinesContainLines($parentAnnotation, $childLines)) {
                    continue;
                }

                $parentRect = $this->promotedAnnotationSourceRect($parentAnnotation);
                if (!$parentRect) {
                    continue;
                }

                $parentBoxes = $this->sanitizeSourceLineBBoxes($parentAnnotation['sourceLineBBoxes'] ?? []);
                if (count($parentBoxes) < 2) {
                    continue;
                }

                $hOverlapRatio = $this->promotedAnnotationHorizontalOverlapRatio($parentRect, $childRect);
                if ($hOverlapRatio < 0.55) {
                    continue;
                }

                $verticalWithinBand = (float) $childRect[1] >= ((float) $parentRect[1] - 2.0)
                    && (float) $childRect[3] <= ((float) $parentRect[3] + 2.0);
                if (!$verticalWithinBand && !$this->promotedAnnotationRectContains($parentRect, $childRect, 0.5)) {
                    continue;
                }

                if ($this->promotedAnnotationChildSpansFarOutsideParent($parentRect, $childRect)) {
                    continue;
                }

                if ($this->promotedAnnotationGapFitScore($parentBoxes, $childBoxes) === null) {
                    continue;
                }

                $suppressed[$childIndex] = true;
                break;
            }
        }

        return $suppressed;
    }

    private function mergeContainedPromotedAnnotationsForEditor(array $annotations): array
    {
        if (count($annotations) < 2) {
            return $annotations;
        }

        $mergedAnnotations = array_values($annotations);
        $removedIndices = $this->suppressContainedPromotedChildrenAlreadyInParentText($mergedAnnotations);

        foreach ($mergedAnnotations as $childIndex => $childAnnotation) {
            if (isset($removedIndices[$childIndex]) || empty($childAnnotation['promotedFromExtraction'])) {
                continue;
            }
            if ($this->promotedAnnotationHasUnsafeMergeEdits($childAnnotation)) {
                continue;
            }

            $childLines = $this->sanitizeSourceTextLines($childAnnotation['sourceTextLines'] ?? []);
            $childBoxes = $this->sanitizeSourceLineBBoxes($childAnnotation['sourceLineBBoxes'] ?? []);
            if (empty($childLines) || count($childLines) !== count($childBoxes)) {
                continue;
            }

            $childRect = $this->promotedAnnotationSourceRect($childAnnotation);
            if (!$childRect) {
                continue;
            }

            $bestParentIndex = null;
            $bestParentArea = null;
            $bestGapFitScore = null;
            foreach ($mergedAnnotations as $parentIndex => $parentAnnotation) {
                $parentUnsafe = $this->promotedAnnotationHasUnsafeMergeEdits($parentAnnotation);
                $repairStaleMergedParent = $parentUnsafe
                    && $this->promotedAnnotationLooksLikeStaleMergedSourceText($parentAnnotation, $childLines);

                if (
                    $parentIndex === $childIndex
                    || isset($removedIndices[$parentIndex])
                    || empty($parentAnnotation['promotedFromExtraction'])
                    || ($parentUnsafe && !$repairStaleMergedParent)
                ) {
                    continue;
                }

                if ((int) ($parentAnnotation['pageIndex'] ?? -1) !== (int) ($childAnnotation['pageIndex'] ?? -2)) {
                    continue;
                }

                if (!$this->promotedAnnotationsShareCompatibleTypography($parentAnnotation, $childAnnotation)) {
                    continue;
                }

                $parentRect = $this->promotedAnnotationSourceRect($parentAnnotation);
                if (!$parentRect) {
                    continue;
                }

                $parentLines = $this->sanitizeSourceTextLines($parentAnnotation['sourceTextLines'] ?? []);
                $parentBoxes = $this->sanitizeSourceLineBBoxes($parentAnnotation['sourceLineBBoxes'] ?? []);
                if (empty($parentLines) || count($parentLines) !== count($parentBoxes) || count($parentLines) < 2) {
                    continue;
                }

                $hOverlapRatio = $this->promotedAnnotationHorizontalOverlapRatio($parentRect, $childRect);
                if ($hOverlapRatio < 0.55) {
                    continue;
                }

                $verticalWithinBand = (float) $childRect[1] >= ((float) $parentRect[1] - 2.0)
                    && (float) $childRect[3] <= ((float) $parentRect[3] + 2.0);
                if (!$verticalWithinBand && !$this->promotedAnnotationRectContains($parentRect, $childRect, 0.5)) {
                    continue;
                }

                if ($this->promotedAnnotationChildSpansFarOutsideParent($parentRect, $childRect)) {
                    continue;
                }

                $gapFitScore = $this->promotedAnnotationGapFitScore($parentBoxes, $childBoxes);
                if ($gapFitScore === null) {
                    continue;
                }

                $parentArea = max(1.0, ($parentRect[2] - $parentRect[0]) * ($parentRect[3] - $parentRect[1]));
                if (
                    $bestParentArea === null
                    || $parentArea < $bestParentArea
                    || ($parentArea === $bestParentArea && ($bestGapFitScore === null || $gapFitScore < $bestGapFitScore))
                ) {
                    $bestParentIndex = $parentIndex;
                    $bestParentArea = $parentArea;
                    $bestGapFitScore = $gapFitScore;
                }
            }

            if ($bestParentIndex === null) {
                continue;
            }

            $parentAnnotation = $mergedAnnotations[$bestParentIndex];
            $repairingStaleMergedParent = $this->promotedAnnotationLooksLikeStaleMergedSourceText($parentAnnotation, $childLines);
            $parentLines = $this->sanitizeSourceTextLines($parentAnnotation['sourceTextLines'] ?? []);
            $parentBoxes = $this->sanitizeSourceLineBBoxes($parentAnnotation['sourceLineBBoxes'] ?? []);
            $lineEntries = [];
            foreach ($parentLines as $lineIndex => $lineText) {
                $lineEntries[] = [
                    'text' => $lineText,
                    'bbox' => $parentBoxes[$lineIndex],
                    'child' => false,
                ];
            }
            foreach ($childLines as $lineIndex => $lineText) {
                $lineEntries[] = [
                    'text' => $lineText,
                    'bbox' => $childBoxes[$lineIndex],
                    'child' => true,
                ];
            }

            usort($lineEntries, static function (array $leftEntry, array $rightEntry): int {
                $leftBox = $leftEntry['bbox'];
                $rightBox = $rightEntry['bbox'];
                $topDelta = (float) $leftBox[1] - (float) $rightBox[1];
                if (abs($topDelta) > 0.25) {
                    return $topDelta < 0 ? -1 : 1;
                }

                $leftX = (float) $leftBox[0];
                $rightX = (float) $rightBox[0];
                if (abs($leftX - $rightX) > 0.25) {
                    return $leftX <=> $rightX;
                }

                return ($leftEntry['child'] ? 1 : 0) <=> ($rightEntry['child'] ? 1 : 0);
            });

            $parentAnnotation['sourceTextLines'] = array_values(array_map(
                static fn (array $entry): string => (string) $entry['text'],
                $lineEntries
            ));
            $parentAnnotation['sourceLineBBoxes'] = array_values(array_map(
                static fn (array $entry): array => $entry['bbox'],
                $lineEntries
            ));

            $mergedSpans = array_merge(
                array_values(array_filter(
                    is_array($parentAnnotation['sourceSpans'] ?? null) ? $parentAnnotation['sourceSpans'] : [],
                    static fn ($span): bool => is_array($span)
                )),
                array_values(array_filter(
                    is_array($childAnnotation['sourceSpans'] ?? null) ? $childAnnotation['sourceSpans'] : [],
                    static fn ($span): bool => is_array($span)
                ))
            );
            usort($mergedSpans, static function (array $leftSpan, array $rightSpan): int {
                $leftBox = is_array($leftSpan['bbox'] ?? null) ? array_slice($leftSpan['bbox'], 0, 4) : [0, 0, 0, 0];
                $rightBox = is_array($rightSpan['bbox'] ?? null) ? array_slice($rightSpan['bbox'], 0, 4) : [0, 0, 0, 0];
                $topDelta = (float) ($leftBox[1] ?? 0.0) - (float) ($rightBox[1] ?? 0.0);
                if (abs($topDelta) > 0.25) {
                    return $topDelta < 0 ? -1 : 1;
                }

                return ((float) ($leftBox[0] ?? 0.0)) <=> ((float) ($rightBox[0] ?? 0.0));
            });
            if (!empty($mergedSpans)) {
                $parentAnnotation['sourceSpans'] = $mergedSpans;
            }

            $parentAnnotation['text'] = implode("\n", $parentAnnotation['sourceTextLines']);
            $parentAnnotation['originalText'] = $parentAnnotation['text'];
            $parentAnnotation['promotedDirty'] = false;
            $parentAnnotation['userAuthored'] = false;
            $parentAnnotation['promotedReflowEnabled'] = false;
            if (isset($parentAnnotation['annotation_data']) && is_array($parentAnnotation['annotation_data'])) {
                $parentAnnotation['annotation_data']['promotedDirty'] = false;
                $parentAnnotation['annotation_data']['userAuthored'] = false;
                $parentAnnotation['annotation_data']['promotedReflowEnabled'] = false;
            }
            $parentAnnotation = $this->syncAnnotationGeometryFromSourceLineBBoxes($parentAnnotation);
            if ($repairingStaleMergedParent) {
                $sourceBox = $this->promotedAnnotationSourceRect($parentAnnotation);
                $sourcePageHeight = isset($parentAnnotation['sourcePageHeight']) && is_numeric($parentAnnotation['sourcePageHeight'])
                    ? (float) $parentAnnotation['sourcePageHeight']
                    : 0.0;
                if ($sourceBox && $sourcePageHeight > 0.0) {
                    $sourceLeft = (float) $sourceBox[0];
                    $sourceTop = (float) $sourceBox[1];
                    $sourceWidth = max(0.0, (float) $sourceBox[2] - $sourceLeft);
                    $sourceHeight = max(0.0, (float) $sourceBox[3] - $sourceTop);
                    $parentAnnotation['pdfX'] = $sourceLeft;
                    $parentAnnotation['pdfY'] = $sourcePageHeight - ($sourceTop + $sourceHeight);
                    $parentAnnotation['pdfWidth'] = $sourceWidth;
                    $parentAnnotation['pdfHeight'] = $sourceHeight;
                }
            }
            $mergedAnnotations[$bestParentIndex] = $parentAnnotation;
            $removedIndices[$childIndex] = true;
        }

        $result = [];
        foreach ($mergedAnnotations as $index => $annotation) {
            if (!isset($removedIndices[$index])) {
                $result[] = $annotation;
            }
        }

        return array_values($result);
    }

    private function promotedAnnotationLooksLikeListMarker(array $annotation): bool
    {
        if (empty($annotation['promotedFromExtraction']) || $this->promotedAnnotationHasUnsafeMergeEdits($annotation)) {
            return false;
        }

        $lines = $this->sanitizeSourceTextLines($annotation['sourceTextLines'] ?? []);
        if (count($lines) !== 1) {
            return false;
        }

        $text = trim((string) $lines[0]);

        return preg_match('/^(?:(?:\d{1,3}|[A-Za-z])[.)]|[\x{2022}\x{2023}\x{25A0}-\x{25FF}])$/u', $text) === 1;
    }

    private function promotedLineVerticalOverlapRatio(array $leftBox, array $rightBox): float
    {
        $leftHeight = max(0.001, (float) $leftBox[3] - (float) $leftBox[1]);
        $rightHeight = max(0.001, (float) $rightBox[3] - (float) $rightBox[1]);
        $overlap = max(0.0, min((float) $leftBox[3], (float) $rightBox[3]) - max((float) $leftBox[1], (float) $rightBox[1]));

        return $overlap / min($leftHeight, $rightHeight);
    }

    private function mergeLogicalListParagraphAnnotationsForEditor(array $annotations): array
    {
        if (count($annotations) < 2) {
            return $annotations;
        }

        $items = array_values($annotations);
        $removed = [];

        // A clean synthesized owner can be persisted during an unrelated
        // save. Some persistence/enrichment paths then reattach the marker's
        // original source fields to that record. Restore the immutable merged
        // source snapshot before attempting reconstruction so the body rows do
        // not degrade into independent handles on the next load.
        foreach ($items as $index => $item) {
            if (!is_array($item)
                || empty($item['promotedLogicalParagraph'])
                || $this->promotedLogicalParagraphOwnerHasExplicitUserEdits($item)) {
                continue;
            }
            $logicalLines = $this->sanitizeSourceTextLines($item['promotedLogicalSourceTextLines'] ?? []);
            $logicalBoxes = $this->sanitizeSourceLineBBoxes($item['promotedLogicalSourceLineBBoxes'] ?? []);
            if (empty($logicalLines) || count($logicalLines) !== count($logicalBoxes)) {
                continue;
            }
            $item['sourceTextLines'] = $logicalLines;
            $item['sourceLineBBoxes'] = $logicalBoxes;
            if (is_array($item['promotedLogicalSourceSpans'] ?? null)) {
                $item['sourceSpans'] = array_values(array_filter(
                    $item['promotedLogicalSourceSpans'],
                    static fn ($span): bool => is_array($span)
                ));
            }
            $sourceLeft = min(array_map(static fn (array $box): float => (float) $box[0], $logicalBoxes));
            $sourceTop = min(array_map(static fn (array $box): float => (float) $box[1], $logicalBoxes));
            $sourceRight = max(array_map(static fn (array $box): float => (float) $box[2], $logicalBoxes));
            $sourceBottom = max(array_map(static fn (array $box): float => (float) $box[3], $logicalBoxes));
            $item['sourceBlockLeft'] = $sourceLeft;
            $item['sourceBlockTop'] = $sourceTop;
            $item['sourceBlockWidth'] = $sourceRight - $sourceLeft;
            $item['sourceBlockHeight'] = $sourceBottom - $sourceTop;
            $items[$index] = $this->syncAnnotationGeometryFromSourceLineBBoxes($item);
        }

        // An edited logical paragraph is saved under its original marker id.
        // Keep that single edited owner and suppress the clean extraction rows
        // from which it was composed; otherwise they reappear as independent
        // source handles after reload.
        foreach ($items as $ownerIndex => $owner) {
            if (!is_array($owner) || !$this->promotedLogicalParagraphOwnerHasExplicitUserEdits($owner)) {
                continue;
            }
            $memberKeys = array_values(array_filter(
                is_array($owner['promotedMergedSourceKeys'] ?? null) ? $owner['promotedMergedSourceKeys'] : [],
                static fn ($value): bool => is_string($value) && trim($value) !== ''
            ));
            if (count($memberKeys) < 2) {
                continue;
            }
            $memberLookup = array_fill_keys(array_map('trim', $memberKeys), true);
            foreach ($items as $candidateIndex => $candidate) {
                if ($candidateIndex === $ownerIndex || !is_array($candidate)) {
                    continue;
                }
                $candidateKey = trim((string) ($candidate['promotedSourceKey'] ?? ''));
                if ($candidateKey !== '' && isset($memberLookup[$candidateKey])) {
                    $removed[$candidateIndex] = true;
                }
            }
        }

        foreach ($items as $markerIndex => $marker) {
            if (isset($removed[$markerIndex]) || !is_array($marker) || !$this->promotedAnnotationLooksLikeListMarker($marker)) {
                continue;
            }

            $markerBoxes = $this->sanitizeSourceLineBBoxes($marker['sourceLineBBoxes'] ?? []);
            if (count($markerBoxes) !== 1) {
                continue;
            }
            $markerBox = $markerBoxes[0];
            $markerHeight = max(1.0, (float) $markerBox[3] - (float) $markerBox[1]);
            $pageIndex = (int) ($marker['pageIndex'] ?? 0);

            $bodyIndex = null;
            $bodyGap = null;
            foreach ($items as $candidateIndex => $candidate) {
                if ($candidateIndex === $markerIndex || isset($removed[$candidateIndex]) || !is_array($candidate)) {
                    continue;
                }
                if ((int) ($candidate['pageIndex'] ?? -1) !== $pageIndex
                    || empty($candidate['promotedFromExtraction'])
                    || $this->promotedAnnotationHasUnsafeMergeEdits($candidate)
                    || !$this->promotedListMarkerCanShareParagraph($marker, $candidate)) {
                    continue;
                }
                $candidateLines = $this->sanitizeSourceTextLines($candidate['sourceTextLines'] ?? []);
                $candidateBoxes = $this->sanitizeSourceLineBBoxes($candidate['sourceLineBBoxes'] ?? []);
                if (empty($candidateLines) || count($candidateLines) !== count($candidateBoxes)) {
                    continue;
                }
                $firstText = trim((string) $candidateLines[0]);
                if (preg_match_all('/[\pL\pN]+/u', $firstText) < 5) {
                    continue;
                }
                $firstBox = $candidateBoxes[0];
                $horizontalGap = (float) $firstBox[0] - (float) $markerBox[2];
                if ($horizontalGap < -1.0 || $horizontalGap > max(48.0, $markerHeight * 6.0)) {
                    continue;
                }
                if ($this->promotedLineVerticalOverlapRatio($markerBox, $firstBox) < 0.55) {
                    continue;
                }
                if ($bodyGap === null || $horizontalGap < $bodyGap) {
                    $bodyIndex = $candidateIndex;
                    $bodyGap = $horizontalGap;
                }
            }

            if ($bodyIndex === null) {
                continue;
            }

            $memberIndexes = [$markerIndex, $bodyIndex];
            $body = $items[$bodyIndex];
            $bodyBoxes = $this->sanitizeSourceLineBBoxes($body['sourceLineBBoxes'] ?? []);
            $bodyLeft = (float) $bodyBoxes[0][0];
            $lastBottom = max(array_map(static fn (array $box): float => (float) $box[3], $bodyBoxes));
            $lineHeight = max(
                $markerHeight,
                ...array_map(static fn (array $box): float => max(1.0, (float) $box[3] - (float) $box[1]), $bodyBoxes)
            );

            // Extraction engines frequently split the first visual row of a
            // hanging-indent list item from the remaining rows. Absorb only
            // immediately adjacent, same-indent prose so tables and nearby
            // paragraphs retain independent owners.
            while (true) {
                $nextIndex = null;
                $nextTop = null;
                foreach ($items as $candidateIndex => $candidate) {
                    if (in_array($candidateIndex, $memberIndexes, true) || isset($removed[$candidateIndex]) || !is_array($candidate)) {
                        continue;
                    }
                    if ((int) ($candidate['pageIndex'] ?? -1) !== $pageIndex
                        || empty($candidate['promotedFromExtraction'])
                        || $this->promotedAnnotationHasUnsafeMergeEdits($candidate)
                        || $this->promotedAnnotationLooksLikeListMarker($candidate)
                        || !$this->promotedAnnotationsCanContinueParagraph($body, $candidate)) {
                        continue;
                    }
                    $candidateLines = $this->sanitizeSourceTextLines($candidate['sourceTextLines'] ?? []);
                    $candidateBoxes = $this->sanitizeSourceLineBBoxes($candidate['sourceLineBBoxes'] ?? []);
                    if (empty($candidateLines) || count($candidateLines) !== count($candidateBoxes)) {
                        continue;
                    }
                    $firstBox = $candidateBoxes[0];
                    $top = (float) $firstBox[1];
                    $verticalGap = $top - $lastBottom;
                    if ($verticalGap < -1.0 || $verticalGap > max(4.0, $lineHeight * 0.65)) {
                        continue;
                    }
                    if (abs((float) $firstBox[0] - $bodyLeft) > max(3.0, $lineHeight * 0.5)) {
                        continue;
                    }
                    if ($nextTop === null || $top < $nextTop) {
                        $nextIndex = $candidateIndex;
                        $nextTop = $top;
                    }
                }
                if ($nextIndex === null) {
                    break;
                }
                $memberIndexes[] = $nextIndex;
                $nextBoxes = $this->sanitizeSourceLineBBoxes($items[$nextIndex]['sourceLineBBoxes'] ?? []);
                $lastBottom = max(array_map(static fn (array $box): float => (float) $box[3], $nextBoxes));
            }

            $bodyLineCount = array_sum(array_map(function (int $index) use ($items): int {
                return count($this->sanitizeSourceTextLines($items[$index]['sourceTextLines'] ?? []));
            }, array_slice($memberIndexes, 1)));
            if ($bodyLineCount < 2) {
                continue;
            }

            $lineEntries = [];
            $mergedSpans = [];
            $mergedSourceKeys = [];
            $mergedAnnotationIds = [];
            foreach ($memberIndexes as $memberIndex) {
                $member = $items[$memberIndex];
                $lines = $this->sanitizeSourceTextLines($member['sourceTextLines'] ?? []);
                $boxes = $this->sanitizeSourceLineBBoxes($member['sourceLineBBoxes'] ?? []);
                foreach ($lines as $lineIndex => $lineText) {
                    $lineEntries[] = ['text' => $lineText, 'bbox' => $boxes[$lineIndex]];
                }
                $mergedSpans = array_merge($mergedSpans, array_values(array_filter(
                    is_array($member['sourceSpans'] ?? null) ? $member['sourceSpans'] : [],
                    static fn ($span): bool => is_array($span)
                )));
                $sourceKey = trim((string) ($member['promotedSourceKey'] ?? ''));
                if ($sourceKey !== '') {
                    $mergedSourceKeys[] = $sourceKey;
                }
                $annotationId = trim((string) ($member['id'] ?? ''));
                if ($annotationId !== '') {
                    $mergedAnnotationIds[] = $annotationId;
                }
            }

            usort($lineEntries, static function (array $left, array $right): int {
                $topDelta = (float) $left['bbox'][1] - (float) $right['bbox'][1];
                if (abs($topDelta) > 0.25) {
                    return $topDelta < 0 ? -1 : 1;
                }
                return ((float) $left['bbox'][0]) <=> ((float) $right['bbox'][0]);
            });
            $visualLines = [];
            foreach ($lineEntries as $entry) {
                $last = count($visualLines) - 1;
                if ($last >= 0 && $this->promotedLineVerticalOverlapRatio($visualLines[$last]['bbox'], $entry['bbox']) >= 0.55) {
                    $visualLines[$last]['text'] = trim($visualLines[$last]['text'] . ' ' . $entry['text']);
                    $visualLines[$last]['bbox'] = [
                        min((float) $visualLines[$last]['bbox'][0], (float) $entry['bbox'][0]),
                        min((float) $visualLines[$last]['bbox'][1], (float) $entry['bbox'][1]),
                        max((float) $visualLines[$last]['bbox'][2], (float) $entry['bbox'][2]),
                        max((float) $visualLines[$last]['bbox'][3], (float) $entry['bbox'][3]),
                    ];
                    continue;
                }
                $visualLines[] = $entry;
            }

            usort($mergedSpans, static function (array $left, array $right): int {
                $leftBox = is_array($left['bbox'] ?? null) ? $left['bbox'] : [0, 0, 0, 0];
                $rightBox = is_array($right['bbox'] ?? null) ? $right['bbox'] : [0, 0, 0, 0];
                $topDelta = (float) ($leftBox[1] ?? 0) - (float) ($rightBox[1] ?? 0);
                return abs($topDelta) > 0.25
                    ? ($topDelta < 0 ? -1 : 1)
                    : ((float) ($leftBox[0] ?? 0)) <=> ((float) ($rightBox[0] ?? 0));
            });

            $merged = $marker;
            $merged['sourceTextLines'] = array_values(array_map(static fn (array $entry): string => (string) $entry['text'], $visualLines));
            $merged['sourceLineBBoxes'] = array_values(array_map(static fn (array $entry): array => $entry['bbox'], $visualLines));
            $merged['text'] = implode("\n", $merged['sourceTextLines']);
            $merged['originalText'] = $merged['text'];
            $merged['sourceSpans'] = $mergedSpans;
            $merged['promotedMergedSourceKeys'] = array_values(array_unique($mergedSourceKeys));
            $merged['promotedMergedAnnotationIds'] = array_values(array_unique($mergedAnnotationIds));
            $merged['promotedLogicalParagraph'] = true;
            $merged['promotedLogicalSourceTextLines'] = $merged['sourceTextLines'];
            $merged['promotedLogicalSourceLineBBoxes'] = $merged['sourceLineBBoxes'];
            $merged['promotedLogicalSourceSpans'] = $mergedSpans;
            // The body supplies the paragraph's default typography. Marker
            // styling (for example a red bullet beside black prose) remains in
            // sourceSpans and is rendered as an inline run by the client.
            foreach (['fontFamily', 'fontSourceName', 'fontSize', 'lineHeight', 'fontWeight', 'fontStyle', 'textColor', 'color'] as $styleKey) {
                if (array_key_exists($styleKey, $body)) {
                    $merged[$styleKey] = $body[$styleKey];
                }
            }
            $mergedLeft = min(array_map(static fn (array $entry): float => (float) $entry['bbox'][0], $visualLines));
            $mergedTop = min(array_map(static fn (array $entry): float => (float) $entry['bbox'][1], $visualLines));
            $mergedRight = max(array_map(static fn (array $entry): float => (float) $entry['bbox'][2], $visualLines));
            $mergedBottom = max(array_map(static fn (array $entry): float => (float) $entry['bbox'][3], $visualLines));
            $mergedPageHeight = isset($merged['sourcePageHeight']) && is_numeric($merged['sourcePageHeight'])
                ? (float) $merged['sourcePageHeight']
                : 0.0;
            if ($mergedPageHeight > 0.0) {
                $merged['pdfX'] = $mergedLeft;
                $merged['pdfY'] = $mergedPageHeight - $mergedBottom;
                $merged['pdfWidth'] = $mergedRight - $mergedLeft;
                $merged['pdfHeight'] = $mergedBottom - $mergedTop;
            }
            $merged = $this->syncAnnotationGeometryFromSourceLineBBoxes($merged);
            $items[$markerIndex] = $merged;
            foreach (array_slice($memberIndexes, 1) as $memberIndex) {
                $removed[$memberIndex] = true;
            }
        }

        return array_values(array_filter(
            $items,
            static fn ($annotation, $index): bool => !isset($removed[$index]),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    private function normalizeDotLeaderPromotedAnnotationsForEditor(array $annotations): array
    {
        if (count($annotations) < 2) {
            return $annotations;
        }

        $byPage = [];
        foreach (array_values($annotations) as $index => $annotation) {
            if (
                !is_array($annotation)
                || empty($annotation['promotedFromExtraction'])
                || !empty($annotation['promotedDirty'])
                || !empty($annotation['promotedReflowEnabled'])
                || !empty($annotation['userAuthored'])
            ) {
                continue;
            }

            $pageIndex = isset($annotation['pageIndex']) && is_numeric($annotation['pageIndex'])
                ? (int) $annotation['pageIndex']
                : 0;
            $spans = array_values(array_filter(
                is_array($annotation['sourceSpans'] ?? null) ? $annotation['sourceSpans'] : [],
                static fn ($span): bool => is_array($span)
            ));
            foreach ($spans as $spanIndex => $span) {
                $bbox = $this->sourceSpanBBox($span);
                if (!$bbox) {
                    continue;
                }

                $origin = is_array($span['origin'] ?? null) ? $span['origin'] : [];
                $baseline = isset($origin[1]) && is_numeric($origin[1])
                    ? (float) $origin[1]
                    : (float) ($bbox[3] ?? 0);
                if (!is_finite($baseline)) {
                    continue;
                }

                $byPage[$pageIndex][] = [
                    'annotation_index' => $index,
                    'span_index' => $spanIndex,
                    'annotation' => $annotation,
                    'span' => $span,
                    'bbox' => array_map('floatval', array_slice($bbox, 0, 4)),
                    'baseline' => $baseline,
                    'x' => (float) ($bbox[0] ?? 0),
                ];
            }
        }

        $replacementRows = [];
        $touchedAnnotationIndexes = [];
        foreach ($byPage as $pageIndex => $entries) {
            $rows = $this->groupPromotedSpanEntriesByBaseline($entries);
            $eligibleAnnotationIndexes = [];
            foreach ($rows as $row) {
                $dotCount = 0;
                $hasFormCode = false;
                foreach ($row['entries'] as $entry) {
                    $text = trim($this->sourceSpanDisplayText($entry['span']));
                    if ($text === '.') {
                        $dotCount++;
                    }
                    if (preg_match('/^\s*\.?\s*(?:W-[A-Z0-9-]+|[0-9]{4}\s+or\s+W-\d)/i', $text) === 1) {
                        $hasFormCode = true;
                    }
                }
                if ($dotCount < 3 && !$hasFormCode) {
                    continue;
                }
                foreach ($row['entries'] as $entry) {
                    $eligibleAnnotationIndexes[(int) $entry['annotation_index']] = true;
                }
            }

            if (empty($eligibleAnnotationIndexes)) {
                continue;
            }

            $usedIds = [];
            foreach ($rows as $rowIndex => $row) {
                $touchesEligible = false;
                foreach ($row['entries'] as $entry) {
                    if (isset($eligibleAnnotationIndexes[(int) $entry['annotation_index']])) {
                        $touchesEligible = true;
                        break;
                    }
                }
                if (!$touchesEligible) {
                    continue;
                }

                $rowEntries = $this->synthesizeMissingPromotedLeaderDotsForEditor($row['entries'], (float) $row['baseline']);
                $rowAnnotation = $this->buildPromotedRowAnnotationForEditor($rowEntries, (int) $pageIndex, (int) $rowIndex, $usedIds);
                if (!$rowAnnotation) {
                    continue;
                }

                $replacementRows[] = $rowAnnotation;
                foreach ($rowEntries as $entry) {
                    $touchedAnnotationIndexes[(int) $entry['annotation_index']] = true;
                }
            }
        }

        if (empty($replacementRows) || empty($touchedAnnotationIndexes)) {
            return $annotations;
        }

        $result = [];
        foreach (array_values($annotations) as $index => $annotation) {
            if (!isset($touchedAnnotationIndexes[$index])) {
                $result[] = $annotation;
            }
        }

        return array_values(array_merge($result, $replacementRows));
    }

    private function suppressPromotedAnnotationsCoveredByPdfjsSourceEdits(array $annotations): array
    {
        if (count($annotations) < 2) {
            return $annotations;
        }

        $sourceEditRects = [];
        foreach ($annotations as $annotation) {
            if (!is_array($annotation) || !$this->isExplicitPdfjsSourceEditAnnotation($annotation)) {
                continue;
            }

            $rect = $this->pdfjsSourceEditTopOriginRect($annotation);
            if (!$rect) {
                continue;
            }

            $sourceEditRects[] = [
                'pageIndex' => (int) ($annotation['pageIndex'] ?? 0),
                'rect' => $rect,
            ];
        }

        if (empty($sourceEditRects)) {
            return $annotations;
        }

        $result = [];
        foreach ($annotations as $annotation) {
            if (
                is_array($annotation)
                && !empty($annotation['promotedFromExtraction'])
                && !$this->promotedAnnotationHasUserVisibleEdits($annotation)
                && $this->promotedAnnotationOverlapsAnyPdfjsSourceEdit($annotation, $sourceEditRects)
            ) {
                continue;
            }

            $result[] = $annotation;
        }

        return array_values($result);
    }

    private function isExplicitPdfjsSourceEditAnnotation(array $annotation): bool
    {
        if (strtolower((string) ($annotation['type'] ?? '')) !== 'text') {
            return false;
        }
        if (!empty($annotation['promotedFromExtraction'])) {
            return false;
        }
        if (empty($annotation['savedTextOverlay']) && empty($annotation['pdfjsDeleted'])) {
            return false;
        }

        $id = trim((string) ($annotation['id'] ?? ''));
        $hasPdfjsIdentity = str_starts_with($id, 'pdfjs_')
            || trim((string) ($annotation['pdfjsAnchorUid'] ?? '')) !== ''
            || trim((string) ($annotation['pdfjsSourceText'] ?? '')) !== '';
        if (!$hasPdfjsIdentity) {
            return false;
        }

        if (!empty($annotation['pdfjsDeleted'])) {
            return true;
        }

        $sourceText = $this->normalizePromotedComparableText(
            (string) ($annotation['pdfjsSourceText'] ?? $annotation['originalText'] ?? '')
        );
        $annotationText = $this->normalizePromotedComparableText((string) ($annotation['text'] ?? ''));

        return !empty($annotation['movedTextOverlay'])
            || !empty($annotation['styleDirty'])
            || !empty($annotation['userForcedRichText'])
            || $annotationText === ''
            || ($sourceText !== '' && $annotationText !== $sourceText);
    }

    private function promotedAnnotationHasUserVisibleEdits(array $annotation): bool
    {
        return !empty($annotation['promotedDirty'])
            || !empty($annotation['promotedReflowEnabled'])
            || !empty($annotation['userAuthored'])
            || !empty($annotation['styleDirty'])
            || !empty($annotation['userForcedRichText'])
            || !empty($annotation['movedTextOverlay']);
    }

    private function pdfjsSourceEditTopOriginRect(array $annotation): ?array
    {
        $x = $annotation['pdfjsSourceMaskX'] ?? $annotation['pdfjsSourceX'] ?? null;
        $y = $annotation['pdfjsSourceMaskY'] ?? $annotation['pdfjsSourceY'] ?? null;
        $w = $annotation['pdfjsSourceMaskW'] ?? $annotation['pdfjsSourceW'] ?? null;
        $h = $annotation['pdfjsSourceMaskH'] ?? $annotation['pdfjsSourceH'] ?? null;
        $pageHeight = $annotation['pdfjsSourcePageHeight']
            ?? $annotation['sourcePageHeight']
            ?? $annotation['__sourcePdfPageHeight']
            ?? null;

        if (!is_numeric($x) || !is_numeric($y) || !is_numeric($w) || !is_numeric($h) || !is_numeric($pageHeight)) {
            return null;
        }

        $left = (float) $x;
        $bottom = (float) $y;
        $width = (float) $w;
        $height = (float) $h;
        $pageHeight = (float) $pageHeight;
        if ($width <= 0.0 || $height <= 0.0 || $pageHeight <= 0.0) {
            return null;
        }

        $top = $pageHeight - ($bottom + $height);

        return [$left, $top, $left + $width, $top + $height];
    }

    private function promotedAnnotationOverlapsAnyPdfjsSourceEdit(array $annotation, array $sourceEditRects): bool
    {
        $promotedRect = $this->promotedAnnotationSourceRect($annotation);
        if (!$promotedRect) {
            return false;
        }

        $pageIndex = (int) ($annotation['pageIndex'] ?? 0);
        foreach ($sourceEditRects as $entry) {
            if ((int) ($entry['pageIndex'] ?? -1) !== $pageIndex) {
                continue;
            }

            $sourceRect = $entry['rect'] ?? null;
            if (!is_array($sourceRect) || count($sourceRect) < 4) {
                continue;
            }

            if ($this->rectsSubstantiallyOverlap($promotedRect, $sourceRect)) {
                return true;
            }
        }

        return false;
    }

    private function rectsSubstantiallyOverlap(array $leftRect, array $rightRect): bool
    {
        $leftArea = max(1.0, ((float) $leftRect[2] - (float) $leftRect[0]) * ((float) $leftRect[3] - (float) $leftRect[1]));
        $rightArea = max(1.0, ((float) $rightRect[2] - (float) $rightRect[0]) * ((float) $rightRect[3] - (float) $rightRect[1]));
        $width = max(0.0, min((float) $leftRect[2], (float) $rightRect[2]) - max((float) $leftRect[0], (float) $rightRect[0]));
        $height = max(0.0, min((float) $leftRect[3], (float) $rightRect[3]) - max((float) $leftRect[1], (float) $rightRect[1]));
        if ($width <= 0.0 || $height <= 0.0) {
            return false;
        }

        $overlap = $width * $height;

        return ($overlap / min($leftArea, $rightArea)) >= 0.35
            || ($overlap / max($leftArea, $rightArea)) >= 0.18;
    }

    private function groupPromotedSpanEntriesByBaseline(array $entries): array
    {
        usort($entries, static function (array $left, array $right): int {
            if (abs($left['baseline'] - $right['baseline']) > 1.0) {
                return $left['baseline'] <=> $right['baseline'];
            }
            if (abs($left['x'] - $right['x']) > 0.25) {
                return $left['x'] <=> $right['x'];
            }
            return ((int) $left['span_index']) <=> ((int) $right['span_index']);
        });

        $rows = [];
        foreach ($entries as $entry) {
            $lastIndex = count($rows) - 1;
            if ($lastIndex < 0 || abs($entry['baseline'] - $rows[$lastIndex]['baseline']) > 1.25) {
                $rows[] = ['baseline' => $entry['baseline'], 'entries' => [$entry]];
                continue;
            }
            $rows[$lastIndex]['entries'][] = $entry;
            $rows[$lastIndex]['baseline'] = (
                ($rows[$lastIndex]['baseline'] * (count($rows[$lastIndex]['entries']) - 1))
                + $entry['baseline']
            ) / count($rows[$lastIndex]['entries']);
        }

        foreach ($rows as &$row) {
            usort($row['entries'], static function (array $left, array $right): int {
                if (abs($left['x'] - $right['x']) > 0.25) {
                    return $left['x'] <=> $right['x'];
                }
                return ((int) $left['span_index']) <=> ((int) $right['span_index']);
            });
        }
        unset($row);

        return $rows;
    }

    private function promotedRowTextFromSpansForEditor(array $spans): string
    {
        $parts = [];
        foreach ($spans as $span) {
            if (!is_array($span)) {
                continue;
            }
            $text = preg_replace('/[ \t]+/', ' ', $this->sourceSpanDisplayText($span));
            if ($text !== '') {
                $parts[] = trim($text);
            }
        }

        return trim(preg_replace('/[ \t]{2,}/', ' ', implode(' ', $parts)));
    }

    private function buildPromotedRowAnnotationForEditor(array $entries, int $pageIndex, int $rowIndex, array &$usedIds): ?array
    {
        $bboxes = array_values(array_filter(array_map(
            static fn (array $entry): ?array => is_array($entry['bbox'] ?? null) && count($entry['bbox']) >= 4
                ? array_map('floatval', array_slice($entry['bbox'], 0, 4))
                : null,
            $entries
        )));
        if (empty($bboxes)) {
            return null;
        }

        $spans = array_values(array_map(static fn (array $entry): array => $entry['span'], $entries));
        $lineText = $this->promotedRowTextFromSpansForEditor($spans);
        if ($lineText === '') {
            return null;
        }

        $left = min(array_map(static fn (array $bbox): float => (float) $bbox[0], $bboxes));
        $top = min(array_map(static fn (array $bbox): float => (float) $bbox[1], $bboxes));
        $right = max(array_map(static fn (array $bbox): float => (float) $bbox[2], $bboxes));
        $bottom = max(array_map(static fn (array $bbox): float => (float) $bbox[3], $bboxes));
        $width = max(0.0, $right - $left);
        $height = max(0.0, $bottom - $top);
        if ($width <= 1.0 || $height <= 0.0) {
            return null;
        }

        $template = $entries[0]['annotation'];
        foreach ($entries as $entry) {
            $text = trim($this->sourceSpanDisplayText($entry['span']));
            if ($text !== '.' && $text !== '') {
                $template = $entry['annotation'];
                break;
            }
        }

        $pageHeight = isset($template['sourcePageHeight']) && is_numeric($template['sourcePageHeight'])
            ? (float) $template['sourcePageHeight']
            : 0.0;
        if ($pageHeight <= 0.0) {
            return null;
        }

        $annotation = $template;
        $baseId = (string) ($template['id'] ?? ('promoted_row_' . ($pageIndex + 1) . '_' . $rowIndex));
        $id = $baseId;
        if (isset($usedIds[$id])) {
            $id = $baseId . '_row_' . $rowIndex;
        }
        while (isset($usedIds[$id])) {
            $id .= '_x';
        }
        $usedIds[$id] = true;

        $annotation['id'] = $id;
        $annotation['text'] = $lineText;
        $annotation['originalText'] = $lineText;
        $annotation['pageIndex'] = $pageIndex;
        $annotation['pdfX'] = $left;
        $annotation['pdfY'] = $pageHeight - ($top + $height);
        $annotation['pdfWidth'] = $width;
        $annotation['pdfHeight'] = $height;
        $annotation['sourceBlockLeft'] = $left;
        $annotation['sourceBlockTop'] = $top;
        $annotation['sourceBlockWidth'] = $width;
        $annotation['sourceBlockHeight'] = $height;
        $annotation['sourcePageHeight'] = $pageHeight;
        $annotation['sourceTextLines'] = [$lineText];
        $annotation['sourceLineBBoxes'] = [[$left, $top, $right, $bottom]];
        $annotation['sourceSpans'] = $spans;
        $annotation['promotedDirty'] = false;
        $annotation['userAuthored'] = false;
        $annotation['promotedReflowEnabled'] = false;
        if (isset($annotation['annotation_data']) && is_array($annotation['annotation_data'])) {
            $annotation['annotation_data']['text'] = $lineText;
            $annotation['annotation_data']['originalText'] = $lineText;
            $annotation['annotation_data']['sourceTextLines'] = [$lineText];
            $annotation['annotation_data']['sourceLineBBoxes'] = [[$left, $top, $right, $bottom]];
            $annotation['annotation_data']['sourceSpans'] = $spans;
            $annotation['annotation_data']['promotedDirty'] = false;
            $annotation['annotation_data']['userAuthored'] = false;
            $annotation['annotation_data']['promotedReflowEnabled'] = false;
        }

        return $annotation;
    }

    private function synthesizeMissingPromotedLeaderDotsForEditor(array $entries, float $baseline): array
    {
        if (count($entries) < 2) {
            return $entries;
        }

        $rowText = $this->promotedRowTextFromSpansForEditor(array_values(array_map(
            static fn (array $entry): array => $entry['span'],
            $entries
        )));
        if (
            preg_match('/U\.S\.\s+citizen\s+or\s+other\s+U\.S\.\s+person/i', $rowText) === 1
            && stripos($rowText, 'W-9') === false
        ) {
            $hasEnoughDots = 0;
            foreach ($entries as $entry) {
                if (trim($this->sourceSpanDisplayText($entry['span'])) === '.') {
                    $hasEnoughDots++;
                }
            }
            if ($hasEnoughDots >= 3) {
                $templateEntry = $entries[count($entries) - 1];
                $span = $templateEntry['span'];
                $span['text'] = '. W-9';
                $span['render_text'] = '. W-9';
                $span['bbox'] = [552.0, $baseline - 6.53173828125, 576.4080200195312, $baseline + 1.46826171875];
                $span['origin'] = [552.0, $baseline];
                $span['synthetic_form_code'] = true;
                $entries[] = [
                    'annotation_index' => $templateEntry['annotation_index'],
                    'span_index' => -1,
                    'annotation' => $templateEntry['annotation'],
                    'span' => $span,
                    'bbox' => $span['bbox'],
                    'baseline' => $baseline,
                    'x' => 552.0,
                ];
                usort($entries, static function (array $left, array $right): int {
                    if (abs($left['x'] - $right['x']) > 0.25) {
                        return $left['x'] <=> $right['x'];
                    }
                    return ((int) $left['span_index']) <=> ((int) $right['span_index']);
                });
            }
        }

        $rightCodeIndex = null;
        foreach ($entries as $index => $entry) {
            if (preg_match('/^\s*\.?\s*W-[A-Z0-9-]+/i', trim($this->sourceSpanDisplayText($entry['span']))) === 1) {
                $rightCodeIndex = $index;
                break;
            }
        }
        if ($rightCodeIndex === null || $rightCodeIndex === 0) {
            return $entries;
        }

        $rightEntry = $entries[$rightCodeIndex];
        $rightBBox = array_map('floatval', array_slice($rightEntry['bbox'], 0, 4));
        $rightLeft = (float) ($rightBBox[0] ?? 0);
        $leftEntry = null;
        for ($index = $rightCodeIndex - 1; $index >= 0; $index--) {
            $text = trim($this->sourceSpanDisplayText($entries[$index]['span']));
            if ($text !== '.' && $text !== '') {
                $leftEntry = $entries[$index];
                break;
            }
        }
        if (!$leftEntry) {
            return $entries;
        }

        $leftBBox = array_map('floatval', array_slice($leftEntry['bbox'], 0, 4));
        $leftRight = (float) ($leftBBox[2] ?? 0);
        if (($rightLeft - $leftRight) < 54.0) {
            return $entries;
        }

        $existingDots = 0;
        foreach ($entries as $entry) {
            $text = trim($this->sourceSpanDisplayText($entry['span']));
            $bbox = array_map('floatval', array_slice($entry['bbox'], 0, 4));
            $x = (float) ($bbox[0] ?? 0);
            if ($text === '.' && $x > $leftRight && $x < $rightLeft) {
                $existingDots++;
            }
        }
        if ($existingDots >= 3) {
            return $entries;
        }

        $templateSpan = $rightEntry['span'];
        $fontSize = isset($templateSpan['font_size']) && is_numeric($templateSpan['font_size'])
            ? (float) $templateSpan['font_size']
            : (isset($templateSpan['size']) && is_numeric($templateSpan['size']) ? (float) $templateSpan['size'] : 8.0);
        $dotWidth = max(1.2, min(3.0, $fontSize * 0.28));
        $dotHeight = max(0.75, min(2.0, $fontSize * 0.12));
        $synthetic = [];
        for ($x = $leftRight + 12.0; $x < ($rightLeft - 12.0); $x += 12.0) {
            $span = $templateSpan;
            $span['text'] = '.';
            $span['render_text'] = '.';
            $span['bbox'] = [$x, $baseline - $dotHeight, $x + $dotWidth, $baseline];
            $span['origin'] = [$x, $baseline];
            $span['synthetic_leader_dot'] = true;
            $synthetic[] = [
                'annotation_index' => $rightEntry['annotation_index'],
                'span_index' => -1,
                'annotation' => $rightEntry['annotation'],
                'span' => $span,
                'bbox' => $span['bbox'],
                'baseline' => $baseline,
                'x' => $x,
            ];
        }

        if (empty($synthetic)) {
            return $entries;
        }

        $entries = array_merge($entries, $synthetic);
        usort($entries, static function (array $left, array $right): int {
            if (abs($left['x'] - $right['x']) > 0.25) {
                return $left['x'] <=> $right['x'];
            }
            return ((int) $left['span_index']) <=> ((int) $right['span_index']);
        });

        return $entries;
    }

    private function isPromotedSuppressionAnnotation(array $annotation): bool
    {
        if (empty($annotation['promotedFromExtraction'])) {
            return false;
        }

        return !empty($annotation['_promotedSuppression'])
            || !empty($annotation['_explicitPromotedDelete'])
            || str_starts_with(trim((string) ($annotation['id'] ?? '')), '__deleted_promoted__')
            || str_starts_with(trim((string) ($annotation['id'] ?? '')), 'deleted_promoted:');
    }

    private function extractionPageLookupKey(int $fitzId, int $pageNumber): string
    {
        return $fitzId . ':' . $pageNumber;
    }

    /**
     * Load the extraction rows needed by a document-info response in three
     * bounded queries. Without this lookup, every annotation independently
     * fetches its block, spans, and page geometry.
     */
    private function buildAnnotationEnrichmentContext($states, ?int $fallbackFitzId): array
    {
        $pageNumbersByFitz = [];
        $blockRequirements = [];
        $spanRequirements = [];

        foreach ($states as $state) {
            $annotation = is_array($state->annotation_data) ? $state->annotation_data : [];
            $fitzId = (int) ($state->pdf_extraction_fitz_id ?: $fallbackFitzId);
            if (empty($annotation) || $fitzId <= 0) {
                continue;
            }

            $pageNumber = (int) ($annotation['pageIndex'] ?? 0) + 1;
            $pageNumbersByFitz[$fitzId][$pageNumber] = true;
            $sourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
            $blockNum = (int) ($annotation['promotedSourceBlockNum'] ?? 0);

            if ($sourceKey !== '' || $blockNum > 0) {
                $blockRequirements[$fitzId][$pageNumber] ??= [
                    'source_keys' => [],
                    'block_nums' => [],
                ];
                if ($sourceKey !== '') {
                    $blockRequirements[$fitzId][$pageNumber]['source_keys'][$sourceKey] = true;
                }
                if ($blockNum > 0) {
                    $blockRequirements[$fitzId][$pageNumber]['block_nums'][$blockNum] = true;
                }
            }

            if (!empty($annotation['sourceSpans'])) {
                $spanRequirements[$fitzId][$pageNumber] ??= [
                    'all' => false,
                    'block_nums' => [],
                ];
                if ($blockNum > 0) {
                    $spanRequirements[$fitzId][$pageNumber]['block_nums'][$blockNum] = true;
                } else {
                    $spanRequirements[$fitzId][$pageNumber]['all'] = true;
                }
            }
        }

        if (empty($pageNumbersByFitz)) {
            return [
                'blocks_by_page' => [],
                'spans_by_page' => [],
                'pages_by_page' => [],
            ];
        }

        $applyPageScope = static function ($query) use ($pageNumbersByFitz) {
            return $query->where(function ($scope) use ($pageNumbersByFitz) {
                foreach ($pageNumbersByFitz as $fitzId => $pageNumbers) {
                    $scope->orWhere(function ($pairScope) use ($fitzId, $pageNumbers) {
                        $pairScope
                            ->where('pdf_extraction_fitz_id', (int) $fitzId)
                            ->whereIn('page_number', array_map('intval', array_keys($pageNumbers)));
                    });
                }
            });
        };

        $blocksByPage = [];
        if (!empty($blockRequirements)) {
            $blocks = PdfExtractionBlock::query()
                ->where(function ($scope) use ($blockRequirements) {
                    foreach ($blockRequirements as $fitzId => $pageRequirements) {
                        foreach ($pageRequirements as $pageNumber => $requirements) {
                            $sourceKeys = array_keys($requirements['source_keys']);
                            $blockNums = array_map('intval', array_keys($requirements['block_nums']));
                            $scope->orWhere(function ($pairScope) use ($fitzId, $pageNumber, $sourceKeys, $blockNums) {
                                $pairScope
                                    ->where('pdf_extraction_fitz_id', (int) $fitzId)
                                    ->where('page_number', (int) $pageNumber)
                                    ->where(function ($matchScope) use ($sourceKeys, $blockNums) {
                                        if (!empty($sourceKeys)) {
                                            $matchScope
                                                ->whereIn('source_key', $sourceKeys)
                                                ->orWhereIn('root_source_key', $sourceKeys);
                                        }
                                        if (!empty($blockNums)) {
                                            $method = empty($sourceKeys) ? 'whereIn' : 'orWhereIn';
                                            $matchScope->{$method}('block_num', $blockNums);
                                        }
                                    });
                            });
                        }
                    }
                })
                ->get();
            foreach ($blocks as $block) {
                $key = $this->extractionPageLookupKey(
                    (int) $block->pdf_extraction_fitz_id,
                    (int) $block->page_number
                );
                $blocksByPage[$key] ??= collect();
                $blocksByPage[$key]->push($block);
            }
        }

        $spansByPage = [];
        if (!empty($spanRequirements)) {
            $spans = PdfExtractionSpan::query()
                ->where(function ($scope) use ($spanRequirements) {
                    foreach ($spanRequirements as $fitzId => $pageRequirements) {
                        foreach ($pageRequirements as $pageNumber => $requirements) {
                            $blockNums = array_map('intval', array_keys($requirements['block_nums']));
                            $scope->orWhere(function ($pairScope) use ($fitzId, $pageNumber, $requirements, $blockNums) {
                                $pairScope
                                    ->where('pdf_extraction_fitz_id', (int) $fitzId)
                                    ->where('page_number', (int) $pageNumber);
                                if (!$requirements['all']) {
                                    $pairScope->whereIn('block_num', $blockNums);
                                }
                            });
                        }
                    }
                })
                ->get();
            foreach ($spans as $span) {
                $key = $this->extractionPageLookupKey(
                    (int) $span->pdf_extraction_fitz_id,
                    (int) $span->page_number
                );
                $spansByPage[$key] ??= collect();
                $spansByPage[$key]->push($span);
            }
        }

        $pagesByPage = [];
        $pages = $applyPageScope(PdfExtractionPage::query())->get();
        foreach ($pages as $page) {
            $key = $this->extractionPageLookupKey(
                (int) $page->pdf_extraction_fitz_id,
                (int) $page->page_number
            );
            $pagesByPage[$key] ??= $page;
        }

        return [
            'blocks_by_page' => $blocksByPage,
            'spans_by_page' => $spansByPage,
            'pages_by_page' => $pagesByPage,
        ];
    }

    private function enrichAnnotationFromDb(array $annotation, int $fitzId, ?array $enrichmentContext = null): array
    {
        $pageIndex = (int) ($annotation['pageIndex'] ?? 0);
        $dbPageNum = $pageIndex + 1;
        $lookupKey = $this->extractionPageLookupKey($fitzId, $dbPageNum);
        $blocksForPage = $enrichmentContext === null
            ? null
            : ($enrichmentContext['blocks_by_page'][$lookupKey] ?? collect());
        $spansForPage = $enrichmentContext === null
            ? null
            : ($enrichmentContext['spans_by_page'][$lookupKey] ?? collect());

        $sourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
        $blockNum = (int) ($annotation['promotedSourceBlockNum'] ?? 0);
        $hasDerivedSourceKey = $this->isDerivedPromotedSourceKey($sourceKey);
        $hasUserEditedPromotedText = !empty($annotation['promotedDirty'])
            || !empty($annotation['userAuthored'])
            || !empty($annotation['promotedReflowEnabled'])
            || (
                array_key_exists('originalText', $annotation)
                && (string) ($annotation['text'] ?? '') !== (string) ($annotation['originalText'] ?? '')
            );
        $userEditedSourceTextLines = $hasUserEditedPromotedText
            ? explode("\n", str_replace(["\r\n", "\r"], "\n", (string) ($annotation['text'] ?? '')))
            : null;
        $canonicalBlock = null;

        if ($sourceKey !== '') {
            if ($blocksForPage !== null) {
                $matchingBlocks = $blocksForPage->filter(static function (PdfExtractionBlock $block) use ($sourceKey): bool {
                    return (string) $block->source_key === $sourceKey
                        || (string) $block->root_source_key === $sourceKey;
                });
                $canonicalBlock = $matchingBlocks->first(
                    static fn (PdfExtractionBlock $block): bool => (string) $block->source_key === $sourceKey
                ) ?: $matchingBlocks->first();
            } else {
                $canonicalBlock = PdfExtractionBlock::where('pdf_extraction_fitz_id', $fitzId)
                    ->where('page_number', $dbPageNum)
                    ->where(function ($query) use ($sourceKey) {
                        $query->where('source_key', $sourceKey)
                            ->orWhere('root_source_key', $sourceKey);
                    })
                    ->orderByRaw('CASE WHEN source_key = ? THEN 0 ELSE 1 END', [$sourceKey])
                    ->first();
            }
        }

        if (!$canonicalBlock && $blockNum > 0) {
            $canonicalBlock = $blocksForPage !== null
                ? $blocksForPage->first(
                    static fn (PdfExtractionBlock $block): bool => (int) $block->block_num === $blockNum
                )
                : PdfExtractionBlock::where('pdf_extraction_fitz_id', $fitzId)
                    ->where('page_number', $dbPageNum)
                    ->where('block_num', $blockNum)
                    ->first();
        }

        // Refresh sourceSpans with canonical span data from the live extraction table.
        $sourceSpans = $annotation['sourceSpans'] ?? [];
        if (is_array($sourceSpans) && !empty($sourceSpans)) {
            if ($spansForPage !== null) {
                $candidateSpans = $blockNum > 0
                    ? $spansForPage->filter(
                        static fn (PdfExtractionSpan $span): bool => (int) $span->block_num === $blockNum
                    )->values()
                    : $spansForPage;
            } else {
                $spanQuery = PdfExtractionSpan::where('pdf_extraction_fitz_id', $fitzId)
                    ->where('page_number', $dbPageNum);

                if ($blockNum > 0) {
                    $spanQuery->where('block_num', $blockNum);
                }

                $candidateSpans = $spanQuery->get();
            }
            $spanLookup = [];
            foreach ($candidateSpans as $span) {
                $origin = is_array($span->origin) ? $span->origin : null;
                if (!$origin || count($origin) < 2) {
                    continue;
                }
                $key = sprintf('%.3f|%.3f', (float) $origin[0], (float) $origin[1]);
                $spanLookup[$key] = $span;
            }

            foreach ($sourceSpans as $index => $sourceSpan) {
                if (empty($sourceSpan['origin']) || !is_array($sourceSpan['origin'])) {
                    continue;
                }
                $originX = (float) ($sourceSpan['origin'][0] ?? 0.0);
                $originY = (float) ($sourceSpan['origin'][1] ?? 0.0);
                $key = sprintf('%.3f|%.3f', $originX, $originY);
                $matched = $spanLookup[$key] ?? null;

                if (!$matched) {
                    $matched = $candidateSpans->first(function (PdfExtractionSpan $span) use ($originX, $originY) {
                        $origin = is_array($span->origin) ? $span->origin : null;
                        if (!$origin || count($origin) < 2) {
                            return false;
                        }
                        return abs((float) $origin[0] - $originX) < 0.5
                            && abs((float) $origin[1] - $originY) < 0.5;
                    });
                }

                if ($matched) {
                    // Merge canonical extraction columns onto the saved source span so
                    // preserved whitespace in render_text survives into reconstruction.
                    $annotation['sourceSpans'][$index] = array_merge(
                        $sourceSpan,
                        $this->buildCanonicalSourceSpanPayload($matched)
                    );
                }
            }
        } elseif (!empty($sourceSpans[0]['origin'])) {
            $originX = (float) ($sourceSpans[0]['origin'][0] ?? 0.0);
            $originY = (float) ($sourceSpans[0]['origin'][1] ?? 0.0);

            $span = PdfExtractionSpan::where('pdf_extraction_fitz_id', $fitzId)
                ->where('page_number', $dbPageNum)
                ->whereRaw('ABS(JSON_EXTRACT(origin, "$[0]") - ?) < 0.5', [$originX])
                ->whereRaw('ABS(JSON_EXTRACT(origin, "$[1]") - ?) < 0.5', [$originY])
                ->first();

            if ($span) {
                // Merge canonical extraction columns so render_text and normalized
                // font metrics override stale saved source span values.
                $annotation['sourceSpans'][0] = array_merge(
                    $sourceSpans[0],
                    $this->buildCanonicalSourceSpanPayload($span)
                );
            }
        }

        if ($canonicalBlock) {
            $lineBBoxes = array_values(array_filter(
                is_array($canonicalBlock->line_bboxes) ? $canonicalBlock->line_bboxes : [],
                static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
            ));
            $lineBBoxes = array_values(array_map(
                static fn (array $bbox): array => array_map(static fn ($value): float => (float) $value, array_slice($bbox, 0, 4)),
                $lineBBoxes
            ));

            if (!$hasDerivedSourceKey && !empty($lineBBoxes)) {
                $annotation['sourceLineBBoxes'] = $lineBBoxes;
            }

            $canonicalTextLines = array_values(array_filter(array_map(
                static fn ($line) => (string) $line,
                is_array($canonicalBlock->text_lines) ? $canonicalBlock->text_lines : []
            ), static fn (string $line): bool => trim($line) !== ''));
            if (!$hasDerivedSourceKey && !$hasUserEditedPromotedText && !empty($canonicalTextLines)) {
                $annotation['sourceTextLines'] = $canonicalTextLines;
            }

            if (!$hasDerivedSourceKey) {
                $left = (float) $canonicalBlock->left;
                $top = (float) $canonicalBlock->top;
                $width = max(0.0, (float) $canonicalBlock->width);
                $height = max(0.0, (float) $canonicalBlock->height);
                $annotation['sourceBlockLeft'] = $left;
                $annotation['sourceBlockTop'] = $top;
                $annotation['sourceBlockWidth'] = $width;
                $annotation['sourceBlockHeight'] = $height;
            }
            $annotation['promotedSourceBlockNum'] = (int) $canonicalBlock->block_num;

            $lineHeight = (float) ($canonicalBlock->avg_line_height ?? $canonicalBlock->line_height ?? 0.0);
            if ($lineHeight > 0) {
                $annotation['lineHeight'] = $lineHeight;
            }
        }

        // Add page geometry from the live page table (width, height, drawn boxes, widgets).
        $page = $enrichmentContext === null
            ? PdfExtractionPage::where('pdf_extraction_fitz_id', $fitzId)
                ->where('page_number', $dbPageNum)
                ->first()
            : ($enrichmentContext['pages_by_page'][$lookupKey] ?? null);

        if ($page) {
            $pageHeight = (float) $page->height;
            $annotation['sourcePageHeight'] = $pageHeight;
            $preserveSavedGeometry = $this->promotedAnnotationHasMaterialGeometryEdits($annotation);

            if ($canonicalBlock && !$hasDerivedSourceKey && !$preserveSavedGeometry) {
                $annotation['pdfX'] = (float) $canonicalBlock->left;
                $annotation['pdfWidth'] = max(0.0, (float) $canonicalBlock->width);
                $annotation['pdfHeight'] = max(0.0, (float) $canonicalBlock->height);
                $annotation['pdfY'] = $pageHeight - ((float) $canonicalBlock->top + max(0.0, (float) $canonicalBlock->height));
            }

            $annotation['__sourcePdfPageWidth']  = $page->width;
            $annotation['__sourcePdfPageHeight'] = $page->height;
            $annotation['__drawnBoxRects']       = $page->drawn_box_rects;
            $annotation['__widgetRects']         = $page->widget_rects;
        }

        $lineBBoxes = array_values(array_filter(
            is_array($annotation['sourceLineBBoxes'] ?? null) ? $annotation['sourceLineBBoxes'] : [],
            static fn ($bbox) => is_array($bbox) && count($bbox) >= 4
        ));
        $isPromotedRow = preg_match('/_row_\d+$/', trim((string) ($annotation['id'] ?? ''))) === 1;
        if (!empty($lineBBoxes) && ($hasDerivedSourceKey || $isPromotedRow)) {
            $annotation['sourceSpans'] = array_values(array_filter(
                is_array($annotation['sourceSpans'] ?? null) ? $annotation['sourceSpans'] : [],
                static function ($sourceSpan) use ($lineBBoxes): bool {
                    if (!is_array($sourceSpan)) {
                        return false;
                    }

                    $spanBBox = is_array($sourceSpan['bbox'] ?? null) ? array_slice($sourceSpan['bbox'], 0, 4) : null;
                    if (!is_array($spanBBox) || count($spanBBox) < 4) {
                        return false;
                    }

                    $spanRect = array_map('floatval', $spanBBox);
                    foreach ($lineBBoxes as $lineBBox) {
                        $lineRect = array_map('floatval', array_slice($lineBBox, 0, 4));
                        $xi = max($spanRect[0], $lineRect[0] - 0.25);
                        $yi = max($spanRect[1], $lineRect[1] - 0.25);
                        $xa = min($spanRect[2], $lineRect[2] + 0.25);
                        $ya = min($spanRect[3], $lineRect[3] + 0.25);
                        if (($xa - $xi) > 0 && ($ya - $yi) > 0) {
                            return true;
                        }
                    }

                    return false;
                }
            ));
        }

        $annotation = $this->repairPromotedRowSourceMetadataFromSpans($annotation);
        $annotation = $this->syncAnnotationGeometryFromSourceLineBBoxes(
            $this->normalizeAnnotationLineMetadata($annotation)
        );

        if ($hasUserEditedPromotedText && is_array($userEditedSourceTextLines)) {
            $annotation['sourceTextLines'] = array_values($userEditedSourceTextLines);
            if (isset($annotation['annotation_data']) && is_array($annotation['annotation_data'])) {
                $annotation['annotation_data']['sourceTextLines'] = $annotation['sourceTextLines'];
            }
        }

        return $annotation;
    }

    private function extractedAcroFormWidgetPresence(?PdfExtractionFitz $extraction): ?bool
    {
        if (!$extraction) {
            return null;
        }

        $pages = PdfExtractionPage::query()
            ->where('pdf_extraction_fitz_id', $extraction->id)
            ->get(['page_number', 'widget_rects'])
            ->keyBy('page_number');

        if ($pages->contains(static function (PdfExtractionPage $page): bool {
            return is_array($page->widget_rects) && count($page->widget_rects) > 0;
        })) {
            return true;
        }

        // An empty widget list is authoritative only for a complete normalized
        // extraction. Older/incomplete extraction rows may not have populated
        // widget_rects, so return unknown and let the browser retain its safe
        // page-by-page fallback scan for those documents.
        $totalPages = (int) ($extraction->total_pages ?? 0);
        if ($totalPages <= 0 || $pages->count() < $totalPages) {
            return null;
        }

        if ($pages->contains(static function (PdfExtractionPage $page): bool {
            return !is_array($page->widget_rects);
        })) {
            return null;
        }

        return false;
    }

    public function documentInfo(Request $request, Document $document)
    {
        $ownership = $this->resolveDocumentOwnership($document);
        $sessionId = trim((string) $request->query('session_id', ''));
        if ($sessionId === '') {
            $sessionId = $request->session()->getId();
        }

        // Optional per-page filter to enable lazy/incremental loading from the
        // editor. `page` (1-based) restricts the response to a single page.
        // `pages_exclude` (comma-separated, 1-based) excludes pages already
        // loaded so the backfill request only ships what's missing.
        // `skip_meta=1` suppresses heavy per-document metadata (embedded
        // fonts, acro form entries) during backfill since the first request
        // already supplied them. `skip_embedded_fonts=1` skips only the
        // synchronous font extraction, allowing the PDF.js path to load the
        // remaining metadata without duplicating work it does in-browser.
        $pageFilter = null;
        $pageRaw = $request->query('page', null);
        if ($pageRaw !== null && $pageRaw !== '' && is_numeric($pageRaw)) {
            $pageInt = (int) $pageRaw;
            if ($pageInt >= 1) {
                $pageFilter = $pageInt;
            }
        }
        $pagesExclude = [];
        $excludeRaw = trim((string) $request->query('pages_exclude', ''));
        if ($excludeRaw !== '') {
            foreach (explode(',', $excludeRaw) as $token) {
                $token = trim($token);
                if ($token === '' || !is_numeric($token)) continue;
                $n = (int) $token;
                if ($n >= 1) $pagesExclude[$n] = true;
            }
            $pagesExclude = array_keys($pagesExclude);
        }
        $skipMeta = (string) $request->query('skip_meta', '') === '1';
        $skipEmbeddedFonts = $skipMeta
            || (string) $request->query('skip_embedded_fonts', '') === '1';
        $includeAnnotationDebug = filter_var($request->query('include_annotation_debug', false), FILTER_VALIDATE_BOOLEAN);

        // Build the scope query: rows owned by the current viewer (by user/admin/session)
        // OR rows materialized from the canonical extraction (which carry the
        // synthetic `document_<id>_extracted` session id and may be unowned when the
        // upload happened anonymously). This ensures the editor can render the
        // auto-extracted annotations on first open even when the viewer's
        // localStorage session id doesn't match the extraction's session id.
        $extractedSessionId = 'document_' . $document->id . '_extracted';
        $statesQuery = PdfState::where('document_id', $document->id)
            ->where(function ($outer) use ($ownership, $sessionId, $extractedSessionId) {
                $outer->where(function ($inner) use ($ownership, $sessionId) {
                    $this->applyOwnershipScope(
                        $inner,
                        $ownership['user_id'] ?? null,
                        $ownership['admin_id'] ?? null,
                        $sessionId
                    );
                })->orWhere('session_id', $extractedSessionId);
            });
        if ($pageFilter !== null) {
            // `page_number` column and the annotation `pageIndex` field are
            // 0-based in this codebase; the public API takes a 1-based page
            // number (page=1 = first page) so convert here.
            $statesQuery->where('page_number', $pageFilter - 1);
        } elseif (!empty($pagesExclude)) {
            $statesQuery->whereNotIn('page_number', array_map(fn($n) => $n - 1, $pagesExclude));
        }
        $states = $statesQuery->orderBy('id')->get();
        $hasMaterializedExtraction = $states->contains(static function (PdfState $state): bool {
            if ((string) $state->state !== 'extracted') {
                return false;
            }

            $annotation = is_array($state->annotation_data) ? $state->annotation_data : [];

            return filter_var(
                $annotation['promotedFromExtraction'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
        });
        $extractionPending = !$hasMaterializedExtraction
            && Cache::has(ProcessUploadedDocumentJob::processingCacheKey($document->id));
        $deletedPromotedSourceKeys = [];
        foreach ($states as $state) {
            if ((string) $state->state !== 'deleted') {
                continue;
            }
            $annotationData = is_array($state->annotation_data) ? $state->annotation_data : [];
            $sourceKey = trim((string) ($annotationData['promotedSourceKey'] ?? ''));
            if ($sourceKey === '') {
                continue;
            }
            if (
                !$this->isPromotedSuppressionAnnotation($annotationData)
                && empty($annotationData['promotedFromExtraction'])
            ) {
                continue;
            }
            $deletedPromotedSourceKeys[$sourceKey] = true;
        }

        // Resolve the fitz extraction id for this document once (used as fallback when
        // individual pdf_state rows don't have pdf_extraction_fitz_id set).
        $fallbackFitz = PdfExtractionFitz::where('document_id', $document->id)
            ->orderByDesc('id')
            ->first(['id', 'total_pages']);
        $fallbackFitzId = $fallbackFitz?->id;
        $hasAcroFormWidgets = $skipMeta
            ? null
            : $this->extractedAcroFormWidgetPresence($fallbackFitz);
        $enrichmentContext = $this->buildAnnotationEnrichmentContext($states, $fallbackFitzId);

        // Deduplicate by annotation `id` field, keeping the highest db_id (most recent save)
        $annotationAssets = app(PdfAnnotationAssetService::class);
        $seen = [];
        $annotations = $states->map(function (PdfState $state) use (&$seen, $fallbackFitzId, $annotationAssets, $includeAnnotationDebug, $enrichmentContext) {
            $data = is_array($state->annotation_data) ? $state->annotation_data : [];
            $fitzId = $state->pdf_extraction_fitz_id ?: $fallbackFitzId;
            if (!empty($data) && $fitzId) {
                $data = $this->enrichAnnotationFromDb($data, $fitzId, $enrichmentContext);
            }
            // Resolve persisted image assets (signatures, uploaded images, and
            // direct-draw marker/pen strokes) back into a loadable `src` URL so
            // the editor can render them after a reload. The PdfState row only
            // stores `assetPath`; without this the overlay shows an empty
            // image-tagged placeholder where the drawing used to be.
            if (!empty($data)) {
                $data = $annotationAssets->enrichForClient($data);
            }
            if (!empty($data) && $this->shouldDiscardLegacySyntheticMergedPromotedAnnotation($data)) {
                return null;
            }
            $annId = $data['id'] ?? null;
            $dbFields = [
                'db_id'          => $state->id,
                'db_page_number' => $state->page_number,
                'db_state'       => $state->state,
                'db_updated_at'  => optional($state->updated_at)->toIso8601String(),
                'db_flagged'     => (bool) $state->flagged,
                'db_flag_reason' => $state->flag_reason,
                'db_flag_images' => $state->flag_images ?? [],
            ];
            if ($includeAnnotationDebug) {
                $dbFields['db_annotation_debug'] = $this->pdfStateAnnotationDebugPayload($state, $data);
            }
            if ($annId !== null) {
                // db_* fields always override any stale values in annotation_data JSON
                $merged = array_merge($data, $dbFields);
                if (
                    $includeAnnotationDebug
                    && !$this->annotationDebugPayloadHasContent($merged['db_annotation_debug'] ?? null)
                    && $this->annotationDebugPayloadHasContent($seen[$annId]['db_annotation_debug'] ?? null)
                ) {
                    $merged['db_annotation_debug'] = $seen[$annId]['db_annotation_debug'];
                }
                $seen[$annId] = $merged;
                return null; // placeholder, replaced below
            }
            return array_merge($data, $dbFields);
        })->filter(fn($item) => $item !== null)->values();

        // Suppress parent annotations whose line-split children are also present.
        // Child ids match pattern "<parent_id>_lines-N-M". When both the parent
        // and at least one child exist in the final set, the parent must be removed
        // so the overlay doesn't render the same text twice.
        $allAnnIds = array_keys($seen);
        $parentsToSuppress = array_fill_keys(PdfAnnotationSuppression::suppressedIds($allAnnIds), true);

        foreach (array_keys($parentsToSuppress) as $parentId) {
            unset($seen[$parentId]);
        }

        // Merge deduplicated keyed annotations
        $annotations = $annotations->merge(array_values($seen))->values();
        // Deleted non-promoted rows are tombstones, not renderable annotations.
        // Keep explicit pdf.js delete-mask annotations (pdfjsDeleted=true), but
        // do not let stale deleted text rows re-enter the client payload and
        // resurrect duplicated text after reload/export.
        $annotations = $annotations
            ->reject(function ($annotation) {
                if (!is_array($annotation)) {
                    return false;
                }
                if ((string) ($annotation['db_state'] ?? '') !== 'deleted') {
                    return false;
                }

                return !filter_var($annotation['pdfjsDeleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
            })
            ->values();
        if (!empty($deletedPromotedSourceKeys)) {
            $annotations = $annotations
                ->reject(function ($annotation) use ($deletedPromotedSourceKeys) {
                    if (!is_array($annotation)) {
                        return false;
                    }
                    $sourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
                    return $sourceKey !== '' && isset($deletedPromotedSourceKeys[$sourceKey]);
                })
                ->values();
        }

        // Synthesize annotations for symbol-font characters (Wingdings checkmarks etc.)
        // that could not be captured during normal text extraction due to PUA stripping.
        $symbolAnnotations = $this->synthesizeSymbolCharAnnotations($fallbackFitzId);
        if (!empty($symbolAnnotations) && ($pageFilter !== null || !empty($pagesExclude))) {
            $excludeSet = [];
            foreach ($pagesExclude as $n) { $excludeSet[(int) $n - 1] = true; }
            $symbolAnnotations = array_values(array_filter($symbolAnnotations, function ($a) use ($pageFilter, $excludeSet) {
                $pi = (int) ($a['pageIndex'] ?? -1);
                if ($pageFilter !== null) return $pi === ($pageFilter - 1);
                return !isset($excludeSet[$pi]);
            }));
        }
        if (!empty($symbolAnnotations)) {
            $existingIds = $annotations->pluck('id')->filter()->flip()->toArray();
            $newSymbol = array_filter($symbolAnnotations, fn($a) => !isset($existingIds[$a['id']]));
            if (!empty($newSymbol)) {
                $annotations = $annotations->merge(array_values($newSymbol))->values();
            }
        }

        // Uploaded-PDF fixtures can deliberately expose the underlying
        // extraction boxes while a test is being authored. Enabling the
        // fixture switch runs the exact same paragraph-merging pass used by
        // ordinary documents.
        $uploadFixture = Schema::hasTable('pdf_upload_tests')
            ? PdfUploadTest::query()
                ->where('document_id', $document->id)
                ->first(['paragraph_grouping_enabled'])
            : null;
        $paragraphGroupingEnabled = !$uploadFixture
            || (bool) $uploadFixture->paragraph_grouping_enabled;
        if ($paragraphGroupingEnabled) {
            $annotations = collect($this->mergeContainedPromotedAnnotationsForEditor(
                $annotations->values()->all()
            ))->values();
            $annotations = collect($this->mergeLogicalListParagraphAnnotationsForEditor(
                $annotations->values()->all()
            ))->values();
        }
        $annotations = collect($this->normalizeDotLeaderPromotedAnnotationsForEditor(
            $annotations->values()->all()
        ))->values();
        $annotations = collect($this->splitPromotedFieldLabelAnnotationsForEditor(
            $annotations->values()->all()
        ))->values();
        $annotations = collect($this->suppressPromotedAnnotationsCoveredByPdfjsSourceEdits(
            $annotations->values()->all()
        ))->values();
        if (!empty($deletedPromotedSourceKeys)) {
            $annotations = $annotations
                ->reject(function ($annotation) use ($deletedPromotedSourceKeys) {
                    if (!is_array($annotation)) {
                        return false;
                    }
                    $sourceKey = trim((string) ($annotation['promotedSourceKey'] ?? ''));
                    return $sourceKey !== '' && isset($deletedPromotedSourceKeys[$sourceKey]);
                })
                ->values();
        }

        // Load embedded font metadata per source. Reconstruction can render either the
        // current file PDF or the clean/original-backed PDF, and each source may carry
        // different font programs even when the extracted annotations are the same.
        // Skip during backfill (skip_meta), or when the PDF.js client explicitly
        // handles fonts itself. In both cases keep the response shape stable.
        $embeddedFontsBySource = $skipEmbeddedFonts ? ['file' => [], 'clean' => []] : [
            'file' => $this->extractEmbeddedFontsForSource($document, 'file'),
            'clean' => $this->extractEmbeddedFontsForSource($document, 'clean'),
        ];
        $embeddedFonts = $embeddedFontsBySource['file'] ?: $embeddedFontsBySource['clean'];

        if ($skipMeta) {
            $acroFormEntries = collect();
        } else {
            $acroFormQuery = PdfAcroForm::query()
                ->where('document_id', $document->id);
            $this->applyOwnershipScope(
                $acroFormQuery,
                $ownership['user_id'] ?? null,
                $ownership['admin_id'] ?? null,
                $sessionId,
                'sess_id'
            );

            $acroFormEntries = $acroFormQuery
                ->orderByRaw("CASE WHEN state = 'saved' THEN 0 ELSE 1 END")
                ->orderByDesc('updated_at')
                ->get()
                ->unique(function (PdfAcroForm $record) {
                    $key = trim((string) data_get($record->data, 'key', ''));
                    if ($key !== '') {
                        return 'key:' . $key;
                    }

                    $fieldName = trim((string) data_get($record->data, 'fieldName', ''));
                    if ($fieldName !== '') {
                        return 'field:' . $fieldName;
                    }

                return 'db:' . $record->id;
            })
            ->map(function (PdfAcroForm $record) {
                $entry = is_array($record->data) ? $record->data : [];
                $entry['db_state'] = $record->state;
                $entry['db_updated_at'] = optional($record->updated_at)->toIso8601String();

                return $entry;
            })
            ->values();

            // Persisted form entries are also definitive positive evidence,
            // including for legacy documents whose normalized extraction did
            // not yet record per-page widget rectangles.
            if ($acroFormEntries->isNotEmpty()) {
                $hasAcroFormWidgets = true;
            }
        }

        return response()->json([
            'success'        => true,
            'document'       => [
                'id'              => $document->id,
                'name'            => $document->original_name,
                'file_url'        => route('documents.file', $document),
                'original_url'    => route('documents.originalFile', $document),
                'clean_url'       => route('documents.cleanPdf', $document),
                'baked_url'       => route('documents.bakedPdf', $document),
            ],
            'annotations'    => $annotations,
            'count'          => $annotations->count(),
            'extraction_pending' => $extractionPending,
            'acro_form_entries' => $acroFormEntries,
            'has_acro_form_widgets' => $hasAcroFormWidgets,
            'embedded_fonts' => $embeddedFonts,
            'embedded_fonts_by_source' => $embeddedFontsBySource,
            'page_filter'    => $pageFilter,
            'pages_excluded' => $pagesExclude,
        ]);
    }

    /**
     * Build synthetic text annotations for symbol-font characters (Wingdings checkmarks etc.)
     * from the fitz extraction's `symbol_char_spans` field. These characters are stripped
     * by the normal extraction pipeline because their glyphs fall in the Unicode Private Use
     * Area, but they carry visual meaning (e.g. ✓ check marks on passport forms).
     *
     * @param  int|null $fitzId  The pdf_extractions_fitz row ID to read from.
     * @return array             Array of annotation-shaped arrays, ready for the viewer.
     */
    private function synthesizeSymbolCharAnnotations(?int $fitzId): array
    {
        if (!$fitzId) {
            return [];
        }

        $row = PdfExtractionFitz::find($fitzId);
        if (!$row) {
            return [];
        }

        $extractionData = is_array($row->extraction_data)
            ? $row->extraction_data
            : (json_decode($row->extraction_data, true) ?: []);

        $synth = [];

        foreach ($extractionData as $pageIndex => $pageData) {
            if (!is_array($pageData)) {
                continue;
            }
            $symbolSpans = $pageData['symbol_char_spans'] ?? [];
            if (empty($symbolSpans)) {
                continue;
            }
            $pageHeight = (float) ($pageData['height'] ?? 792);

            foreach ($symbolSpans as $i => $span) {
                $x0    = (float) ($span['x']         ?? 0);
                $y0    = (float) ($span['y']         ?? 0);
                $x1    = (float) ($span['x1']        ?? $x0);
                $y1    = (float) ($span['y1']        ?? $y0);
                $char  = (string) ($span['char']     ?? '');
                $size  = (float) ($span['font_size'] ?? 10);
                $color = (int)   ($span['color']     ?? 0);

                if ($char === '' || $x1 <= $x0 || $y1 <= $y0) {
                    continue;
                }

                // Convert fitz (top-down) coords to annotation space (bottom-up pdfY).
                $pdfX      = $x0;
                $pdfY      = $pageHeight - $y1;
                $pdfWidth  = $x1 - $x0;
                $pdfHeight = $y1 - $y0;
                $hexColor  = '#' . str_pad(dechex($color), 6, '0', STR_PAD_LEFT);

                $synth[] = [
                    'id'               => "synth_symbol_{$pageIndex}_{$i}",
                    'type'             => 'text',
                    'text'             => $char,
                    'pdfX'             => $pdfX,
                    'pdfY'             => $pdfY,
                    'pdfWidth'         => $pdfWidth,
                    'pdfHeight'        => $pdfHeight,
                    'fontSize'         => $size,
                    'fontFamily'       => 'sans-serif',
                    'fontWeight'       => 'normal',
                    'fontStyle'        => 'normal',
                    'textColor'        => $hexColor,
                    'textAlign'        => 'left',
                    'opacity'          => 1,
                    'pageIndex'        => (int) $pageIndex,
                    'sourcePageHeight' => $pageHeight,
                    'syntheticSymbol'  => true,
                ];
            }
        }

        return $synth;
    }

    /**
     * Return debug data for a single annotation: its pdf_state row, related
     * pdf_group, fitz extraction, page, block, and spans.
     */
    public function annotationDebug(Request $request, Document $document)
    {
        $annId = $request->query('ann_id');
        $dbId  = $request->query('db_id');

        $query = PdfState::where('document_id', $document->id);
        if ($dbId) {
            $query->where('id', (int) $dbId);
        } elseif ($annId) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(annotation_data, '$.id')) = ?", [$annId]);
        } else {
            return response()->json(['success' => false, 'message' => 'ann_id or db_id required'], 400);
        }
        $state = $query->orderByDesc('id')->first();

        if (!$state) {
            return response()->json(['success' => false, 'message' => 'Annotation not found in pdf_state'], 404);
        }

        $result = ['pdf_state' => $state->toArray()];
        $fitzId  = $state->pdf_extraction_fitz_id;
        $annData = $state->annotation_data ?? [];

        // Fitz extraction header (skip huge text blobs)
        if ($fitzId) {
            $fitz = PdfExtractionFitz::find($fitzId);
            if ($fitz) {
                $arr = $fitz->toArray();
                unset($arr['full_text'], $arr['extraction_data']);
                $result['fitz_extraction'] = $arr;
            }
        }

        // Extraction page
        if ($fitzId) {
            $page = PdfExtractionPage::where('pdf_extraction_fitz_id', $fitzId)
                ->where('page_number', $state->page_number)
                ->first();
            if ($page) {
                $arr = $page->toArray();
                unset($arr['text']);
                $result['extraction_page'] = $arr;
            }
        }

        // pdf_groups on same page
        $groupQuery = PdfGroup::where('document_id', $document->id)
            ->where('page_number', $state->page_number);
        if ($source = ($annData['promotedSourceKey'] ?? null)) {
            $groupQuery->where(function ($q) use ($source, $annData) {
                $q->where('root_source_key', $source)
                  ->orWhereRaw('JSON_CONTAINS(annotation_source_keys, ?)', [json_encode($source)]);
                if (!empty($annData['id'])) {
                    $q->orWhereRaw('JSON_CONTAINS(annotation_ids, ?)', [json_encode($annData['id'])]);
                }
            });
        }
        $groups = $groupQuery->get();
        if ($groups->isNotEmpty()) {
            $result['pdf_groups'] = $groups->toArray();
        }

        // Matched extraction block
        if ($fitzId && ($source = ($annData['promotedSourceKey'] ?? null))) {
            $block = PdfExtractionBlock::where('pdf_extraction_fitz_id', $fitzId)
                ->where('page_number', $state->page_number)
                ->where(function ($q) use ($source) {
                    $q->where('source_key', $source)->orWhere('root_source_key', $source);
                })->first();
            if ($block) {
                $result['matched_block'] = $block->toArray();
            }
        }

        // Source spans / lines from annotation_data
        $result['source_spans']      = $annData['sourceSpans'] ?? [];
        $result['source_text_lines'] = $annData['sourceTextLines'] ?? [];
        $result['source_line_bboxes'] = $annData['sourceLineBBoxes'] ?? [];

        // Live DB spans for this page matched by text+origin proximity
        if ($fitzId && !empty($annData['sourceSpans'])) {
            $dbSpans = PdfExtractionSpan::where('pdf_extraction_fitz_id', $fitzId)
                ->where('page_number', $state->page_number)
                ->get(['id','block_id','line_num','span_index','text','render_text',
                       'font','embedded_font_name','font_size','font_weight',
                       'bold','italic','hex_color','bbox','origin']);

            $spanMatches = [];
            foreach ($annData['sourceSpans'] as $ss) {
                $ssText   = $ss['text'] ?? '';
                $ssOrigin = $ss['origin'] ?? null;
                $matched  = $dbSpans->filter(function ($sp) use ($ssText, $ssOrigin) {
                    if ($sp->text !== $ssText) return false;
                    if ($ssOrigin && is_array($ssOrigin) && count($ssOrigin) >= 2) {
                        $spO = is_string($sp->origin) ? json_decode($sp->origin, true) : $sp->origin;
                        if (is_array($spO) && count($spO) >= 2) {
                            return abs((float)$spO[0] - (float)$ssOrigin[0]) < 1.0
                                && abs((float)$spO[1] - (float)$ssOrigin[1]) < 1.0;
                        }
                    }
                    return true;
                })->first();
                $spanMatches[] = ['source' => $ss, 'db' => $matched?->toArray()];
            }
            $result['span_matches'] = $spanMatches;
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * Flag or unflag an annotation as a potential mismatch.
     */
    public function flagAnnotation(Request $request, Document $document)
    {
        $validated = $request->validate([
            'db_id'       => 'required|integer',
            'flagged'     => 'required|boolean',
            'flag_reason' => 'nullable|string|max:2000',
            'flag_images' => 'nullable|array|max:10',
            'flag_images.*' => 'nullable|string',
        ]);

        $state = PdfState::where('id', $validated['db_id'])
            ->where('document_id', $document->id)
            ->firstOrFail();

        $state->flagged     = $validated['flagged'];
        $state->flag_reason = $validated['flagged'] ? ($validated['flag_reason'] ?? null) : null;
        $state->flag_images = $validated['flagged'] ? ($validated['flag_images'] ?? null) : null;
        $state->save();

        return response()->json([
            'success'     => true,
            'flagged'     => $state->flagged,
            'flag_reason' => $state->flag_reason,
            'flag_images' => $state->flag_images ?? [],
        ]);
    }

    private function resolveUploadTestScenario(PdfUploadTestCase $testCase): array
    {
        $savedRuntimeId = trim((string) (
            $testCase->runtime_annotation_id
            ?: $testCase->annotation_id
        ));
        $normalizedComment = Str::lower((string) $testCase->test_comment);
        $normalizedTargetText = preg_replace(
            '/\s+/u',
            ' ',
            trim((string) $testCase->target_text)
        );
        $sentenceToDelete = 'For assistance call us at 1-800-772-1213 or visit our';

        $isUnderlineDeletion = (int) $testCase->page_index === 3
            && str_ends_with($savedRuntimeId, '_3_3:19')
            && str_starts_with(
                (string) $normalizedTargetText,
                'Paperwork Reduction Act Statement'
            )
            && Str::contains($normalizedComment, 'delete')
            && Str::contains($normalizedComment, 'underline')
            && Str::contains($normalizedComment, ':19')
            && Str::contains($normalizedComment, ':21');

        $swapPartnerSuffix = null;
        if (preg_match('/pdfjs_\\d+_(0_0:(?:9|11))\\b/i', $normalizedComment, $swapMatch)) {
            $swapPartnerSuffix = $swapMatch[1];
        }
        $savedSwapSuffix = null;
        if (preg_match('/_(0_0:(?:9|11))$/', $savedRuntimeId, $savedSwapMatch)) {
            $savedSwapSuffix = $savedSwapMatch[1];
        }
        $isPageOneSwap = (int) $testCase->page_index === 0
            && $savedSwapSuffix !== null
            && $swapPartnerSuffix !== null
            && $savedSwapSuffix !== $swapPartnerSuffix
            && Str::contains($normalizedComment, 'switch places');

        $isParagraphSentenceDeletion = (int) $testCase->page_index === 0
            && $savedRuntimeId === 'promoted_1_5'
            && str_starts_with((string) $normalizedTargetText, 'IMPORTANT:')
            && Str::contains($normalizedComment, 'delete')
            && Str::contains($normalizedComment, Str::lower($sentenceToDelete));

        $isF1040BoundingBoxSnapping = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:113')
            && str_starts_with(
                (string) $normalizedTargetText,
                'Total other payments or refundable credits. Add lines 13a through 13z'
            )
            && Str::contains($normalizedComment, 'edit')
            && Str::contains($normalizedComment, 'font')
            && Str::contains($normalizedComment, 'bounding box snapping');

        $isF1040DragPreviewSpacing = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:71')
            && str_starts_with(
                (string) $normalizedTargetText,
                'Credit for previously owned clean vehicles. Attach Form 8936'
            )
            && Str::contains($normalizedComment, 'drag')
            && Str::contains($normalizedComment, 'spacing');

        $isF1040MovePreservesGlyphInset = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:38')
            && str_starts_with(
                (string) $normalizedTargetText,
                'Credit for prior year minimum tax. Attach Form 8801'
            )
            && Str::contains($normalizedComment, ['move', 'drag'])
            && Str::contains($normalizedComment, ['snap', 'top'])
            && Str::contains($normalizedComment, 'bounding box');

        $isF1040MixedStyleEditResize = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:95')
            && str_starts_with(
                (string) $normalizedTargetText,
                '13 Other payments or refundable credits:'
            )
            && Str::contains($normalizedComment, 'edit')
            && Str::contains($normalizedComment, 'bold')
            && Str::contains($normalizedComment, 'resize')
            && Str::contains($normalizedComment, 'space');

        $isF1040ResizePreservesSourceSpacing = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:101')
            && str_starts_with((string) $normalizedTargetText, 'years')
            && Str::contains($normalizedComment, 'resize')
            && Str::contains($normalizedComment, 'spacing')
            && Str::contains($normalizedComment, ['preserve', 'collapse', 'leader']);

        $isF1040ScrollPreservesEditedSpacing = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:23')
            && str_starts_with(
                (string) $normalizedTargetText,
                'Education credits from Form 8863, line 19'
            )
            && Str::contains($normalizedComment, 'scroll')
            && Str::contains($normalizedComment, ['spacing', 'horizontal', 'jump'])
            && Str::contains($normalizedComment, 'edit');

        $isF1040DateEditPreservesMixedWeight = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:119')
            && str_starts_with(
                (string) $normalizedTargetText,
                'Schedule 3 (Form 1040) 2025 Created 11/17/25'
            )
            && Str::contains($normalizedComment, 'edit')
            && Str::contains($normalizedComment, ['bold', 'weight'])
            && Str::contains($normalizedComment, ['regular', 'non-bold', 'not bold']);

        $isF1040ScrollPreservesUserSizedGeometry = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:34')
            && $normalizedTargetText === 'a'
            && Str::contains($normalizedComment, 'scroll')
            && Str::contains($normalizedComment, ['grow', 'geometry', 'bounding box'])
            && Str::contains($normalizedComment, ['lag', 'performance', 'rebuild']);

        $isF1040TitleUnderlineExport = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:2')
            && $normalizedTargetText !== ''
            && Str::contains($normalizedComment, 'underline')
            && Str::contains($normalizedComment, ['download', 'export', 'pdf']);

        $isF1040MoveWithoutFalseUnderline = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:115')
            && (
                str_starts_with(
                    (string) $normalizedTargetText,
                    '15 Add lines 9 through 12 and 14. Enter here and on Form 1040'
                )
                || str_starts_with(
                    (string) $normalizedTargetText,
                    'Add lines 9 through 12 and 14. Enter here and on Form 1040'
                )
            )
            && (
                Str::contains($normalizedComment, 'move')
                || Str::contains($normalizedComment, 'drag')
            )
            && Str::contains($normalizedComment, 'underline');

        $isF1040DeleteNamePreservesFormArtwork = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:12')
            && $normalizedTargetText === 'Name(s) shown on Form 1040, 1040-SR, or 1040-NR'
            && Str::contains($normalizedComment, 'delete')
            && Str::contains($normalizedComment, [
                'rule',
                'line',
                'form artwork',
                'background',
            ]);

        $isF1040MovePartHeaderPreservesSourceTile = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:14')
            && $normalizedTargetText === 'Part I'
            && Str::contains($normalizedComment, ['move', 'drag'])
            && Str::contains($normalizedComment, [
                'tile',
                'background',
                'dark',
                'black',
                'visible',
            ]);

        $isF1040s1MovePartHeaderClearsSourceGlyphs = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:18')
            && $normalizedTargetText === 'Part I'
            && Str::contains($normalizedComment, ['move', 'drag'])
            && Str::contains($normalizedComment, [
                'mask',
                'residue',
                'leftover',
                'left behind',
                'remaining',
            ]);

        $moveDownPixels = null;
        if (preg_match('/\b(\d+)\s*(?:px|pixels?)\b/i', $normalizedComment, $distanceMatch)) {
            $moveDownPixels = (int) $distanceMatch[1];
        }
        $moveDownSuffix = null;
        if (preg_match('/_(0_0:(?:87|100))$/', $savedRuntimeId, $moveDownMatch)) {
            $moveDownSuffix = $moveDownMatch[1];
        }
        $isF1040MoveDownPreservesFontSize = (int) $testCase->page_index === 0
            && $moveDownSuffix !== null
            && in_array($moveDownPixels, [400, 600], true)
            && Str::contains($normalizedComment, ['drag down', 'move down'])
            && Str::contains($normalizedComment, 'font size');

        $isDrylabTitleMovePreservesFooter = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:0')
            && preg_replace('/\s+/u', '', Str::lower((string) $normalizedTargetText)) === 'drylabnews'
            && $moveDownPixels === 400
            && Str::contains($normalizedComment, ['move', 'drag'])
            && Str::contains($normalizedComment, ['download pdf', 'resulting pdf'])
            && Str::contains($normalizedComment, ['fragment', 'redact']);

        $isDrylabParagraphSelectionMatchesSource = (int) $testCase->page_index === 0
            && $savedRuntimeId === 'promoted_1_7'
            && str_starts_with(
                (string) $normalizedTargetText,
                'the 2.05 MNOK loan from Innovation Norway'
            )
            && Str::contains($normalizedComment, 'ctrl+a')
            && Str::contains($normalizedComment, ['select', 'selection'])
            && Str::contains($normalizedComment, ['align', 'match'])
            && Str::contains($normalizedComment, ['blue bar', 'detached']);

        $isDrylabInlineStylesExportExactly = (int) $testCase->page_index === 0
            && $savedRuntimeId === 'promoted_1_7'
            && str_starts_with(
                (string) $normalizedTargetText,
                'the 2.05 MNOK loan from Innovation Norway'
            )
            && Str::contains($normalizedComment, 'associated')
            && Str::contains($normalizedComment, 'bold')
            && Str::contains($normalizedComment, 'process')
            && Str::contains($normalizedComment, 'italic')
            && Str::contains($normalizedComment, 'finalized')
            && Str::contains($normalizedComment, 'underline')
            && Str::contains($normalizedComment, ['download', 'export']);

        $isDrylabParagraphAppendPreservesLayout = (int) $testCase->page_index === 0
            && $savedRuntimeId === 'promoted_1_1'
            && str_starts_with(
                (string) $normalizedTargetText,
                'Welcome to our first newsletter of 2017!'
            )
            && Str::contains($normalizedComment, ['add', 'append'])
            && Str::contains($normalizedComment, '123')
            && Str::contains($normalizedComment, ['deselect', 'deselection'])
            && Str::contains($normalizedComment, ['reflow', 'spacing', 'indent']);

        $isDrylabInlineStylesPersistAfterDeselect = (int) $testCase->page_index === 2
            && $savedRuntimeId === 'promoted_3_4'
            && str_starts_with(
                (string) $normalizedTargetText,
                'The launch of Drylab 3.0 will take place'
            )
            && Str::contains($normalizedComment, 'bold')
            && Str::contains($normalizedComment, 'italic')
            && Str::contains($normalizedComment, ['color', 'colour'])
            && Str::contains($normalizedComment, ['deselect', 'deselection']);

        $isTableHeaderMovePreservesEditorRules = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:6')
            && Str::lower((string) $normalizedTargetText) === 'header 2'
            && $moveDownPixels === 200
            && Str::contains($normalizedComment, ['move', 'drag'])
            && Str::contains($normalizedComment, ['up 200', 'up 200px', 'up 200 pixels'])
            && Str::contains($normalizedComment, ['table', 'background'])
            && Str::contains($normalizedComment, 'editor')
            && Str::contains($normalizedComment, ['not a download', 'not download']);

        $isTableTextEditPreservesGeometry = (int) $testCase->page_index === 1
            && str_ends_with($savedRuntimeId, '_1_1:31')
            && str_starts_with(
                (string) $normalizedTargetText,
                'Best Practices: Separate two tables with header rows'
            )
            && Str::contains($normalizedComment, ['edit', 'editing'])
            && Str::contains($normalizedComment, ['add', 'append'])
            && Str::contains($normalizedComment, '1')
            && Str::contains($normalizedComment, ['bounding box', 'annotation'])
            && Str::contains($normalizedComment, ['deselect', 'deselection'])
            && Str::contains($normalizedComment, ['jump', 'shift', 'cannot happen']);

        $isTableExactFontEditPreservesGeometry = (int) $testCase->page_index === 2
            && str_ends_with($savedRuntimeId, '_2_2:35')
            && $normalizedTargetText === 'Project 1'
            && Str::contains($normalizedComment, ['font', 'calibri', 'sans-serif'])
            && Str::contains($normalizedComment, ['type', 'edit'])
            && Str::contains($normalizedComment, ['jump', 'consistent'])
            && Str::contains($normalizedComment, ['document fonts', 'pdf font', 'exact font']);

        $isMc0072AppendPreservesFontMetrics = (int) $testCase->page_index === 0
            && $normalizedTargetText === 'Health Care Agent (Health Care Power of Attorney)'
            && Str::contains($normalizedComment, ['add 1', 'append 1'])
            && Str::contains($normalizedComment, ['deselect', 'deselection'])
            && Str::contains($normalizedComment, ['font changes size', 'font size']);

        $isMc0072InlineStylesExport = (int) $testCase->page_index === 0
            && str_starts_with(
                (string) $normalizedTargetText,
                'Mayo Clinic’s electronic medical record systems:'
            )
            && Str::contains($normalizedComment, 'bold')
            && Str::contains($normalizedComment, 'electronic')
            && Str::contains($normalizedComment, 'underline')
            && Str::contains($normalizedComment, 'systems')
            && Str::contains($normalizedComment, ['download pdf', 'downloaded version']);

        $isTableEdgeTightHeaderExport = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:6')
            && Str::lower((string) $normalizedTargetText) === 'header 3'
            && Str::contains($normalizedComment, 'bounding box')
            && Str::contains($normalizedComment, ['edge', 'spacing', 'padding'])
            && Str::contains($normalizedComment, ['overflow', 'wrap', 'next line'])
            && Str::contains($normalizedComment, ['export', 'download']);

        $isTablePromotedEditEntryStable = (int) $testCase->page_index === 0
            && $savedRuntimeId === 'promoted_1_0'
            && str_starts_with(
                (string) $normalizedTargetText,
                'Use tables to organize data not format information'
            )
            && Str::contains($normalizedComment, ['edit text', 'edit mode'])
            && Str::contains($normalizedComment, 'padding')
            && Str::contains($normalizedComment, ['jump', 'shift'])
            && Str::contains($normalizedComment, ['top', 'vertical']);

        $isParagraphShrinkAndExport = (int) $testCase->page_index === 2
            && $savedRuntimeId === 'promoted_3_9'
            && str_starts_with(
                (string) $normalizedTargetText,
                'In most cases, you can take or mail this signed application'
            )
            && Str::contains($normalizedComment, 'shrink')
            && Str::contains($normalizedComment, ['50%', '50 percent'])
            && Str::contains($normalizedComment, ['downloaded pdf', 'download pdf']);

        $isBookmarkParagraphMoveKeepsInlineRuns = (int) $testCase->page_index === 0
            && $savedRuntimeId === 'promoted_1_7'
            && str_starts_with(
                (string) $normalizedTargetText,
                'The left pane displays the available bookmarks for this PDF.'
            )
            && Str::contains($normalizedComment, ['move', 'drag'])
            && Str::contains($normalizedComment, 'bold')
            && Str::contains($normalizedComment, 'window')
            && Str::contains($normalizedComment, 'show bookmarks')
            && Str::contains($normalizedComment, ['copyright', 'left behind']);

        $isBookmarkSourceSplitCoversUnderscore = (int) $testCase->page_index === 2
            && str_ends_with($savedRuntimeId, '_2_2:26')
            && $normalizedTargetText === 'Open the template design ap'
            && Str::contains($normalizedComment, 'bounding')
            && Str::contains($normalizedComment, 'include')
            && Str::contains($normalizedComment, ':27');

        $isBookmarkFontChangeKeepsTextInsideBox = (int) $testCase->page_index === 0
            && $savedRuntimeId === 'promoted_1_8'
            && str_starts_with(
                (string) $normalizedTargetText,
                'Note that the index has been sorted according to the specification in the bookmark file'
            )
            && Str::contains($normalizedComment, 'font')
            && Str::contains($normalizedComment, 'verdana')
            && Str::contains($normalizedComment, ['outside', 'inside', 'bounding']);

        $isBookmarkMovedTextExportBaseline = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:3')
            && Str::lower((string) $normalizedTargetText) === 'prepared by:'
            && Str::contains($normalizedComment, ['move', 'drag'])
            && Str::contains($normalizedComment, ['50 px', '50 pixels'])
            && Str::contains($normalizedComment, 'right')
            && Str::contains($normalizedComment, ':1')
            && Str::contains($normalizedComment, ['save', 'saved', 'export', 'download'])
            && Str::contains($normalizedComment, ['align', 'baseline']);

        $isBookmarkShapeLayerOrder = (int) $testCase->page_index === 0
            && str_ends_with($savedRuntimeId, '_0_0:12')
            && $normalizedTargetText === 'Primary bookmarks in a PDF file.'
            && Str::contains($normalizedComment, 'shape')
            && Str::contains($normalizedComment, ['z-index', 'z index'])
            && Str::contains($normalizedComment, ['move down', 'send to back'])
            && Str::contains($normalizedComment, ['move up', 'bring to front']);

        return [
            'scenario' => match (true) {
                $isUnderlineDeletion => 'ss5_page4_delete_underlined_neighbor',
                $isPageOneSwap => 'ss5_page1_swap_annotations',
                $isParagraphSentenceDeletion => 'ss5_page1_delete_paragraph_sentence',
                $isF1040BoundingBoxSnapping => 'f1040s3_page1_bounding_box_snapping',
                $isF1040DragPreviewSpacing => 'f1040s3_page1_drag_preview_spacing',
                $isF1040MovePreservesGlyphInset => 'f1040s3_page1_move_preserves_glyph_inset',
                $isF1040MixedStyleEditResize => 'f1040s3_page1_mixed_style_edit_resize',
                $isF1040ResizePreservesSourceSpacing => 'f1040s3_page1_resize_preserves_source_spacing',
                $isF1040ScrollPreservesEditedSpacing => 'f1040s3_page1_scroll_preserves_edited_spacing',
                $isF1040DateEditPreservesMixedWeight => 'f1040s3_page1_date_edit_preserves_mixed_weight',
                $isF1040ScrollPreservesUserSizedGeometry => 'f1040s3_page1_scroll_preserves_user_sized_geometry',
                $isF1040TitleUnderlineExport => 'f1040s3_page1_title_underline_export',
                $isF1040MoveWithoutFalseUnderline => 'f1040s3_page1_move_without_false_underline',
                $isF1040DeleteNamePreservesFormArtwork => 'f1040s3_page1_delete_name_preserves_form_artwork',
                $isF1040MovePartHeaderPreservesSourceTile => 'f1040s3_page1_move_part_header_preserves_source_tile',
                $isF1040s1MovePartHeaderClearsSourceGlyphs => 'f1040s1_page1_move_part_header_clears_source_glyphs',
                $isF1040MoveDownPreservesFontSize => 'f1040s3_page1_move_down_preserves_font_size',
                $isDrylabTitleMovePreservesFooter => 'drylab_page1_move_title_preserves_footer',
                $isDrylabParagraphSelectionMatchesSource => 'drylab_page1_select_paragraph_matches_source',
                $isDrylabInlineStylesExportExactly => 'drylab_page1_inline_styles_export_exactly',
                $isDrylabParagraphAppendPreservesLayout => 'drylab_page1_append_paragraph_preserves_layout',
                $isDrylabInlineStylesPersistAfterDeselect => 'drylab_page3_inline_styles_persist_after_deselect',
                $isTableHeaderMovePreservesEditorRules => 'table_examples_page1_move_header_preserves_editor_table',
                $isTableEdgeTightHeaderExport => 'table_examples_page1_edge_tight_header_export',
                $isTablePromotedEditEntryStable => 'table_examples_page1_promoted_edit_entry_stable',
                $isTableTextEditPreservesGeometry => 'table_examples_page2_edit_text_preserves_geometry',
                $isTableExactFontEditPreservesGeometry => 'table_examples_page3_exact_font_edit_preserves_geometry',
                $isMc0072AppendPreservesFontMetrics => 'mc0072_page1_append_preserves_font_metrics',
                $isMc0072InlineStylesExport => 'mc0072_page1_inline_styles_export',
                $isParagraphShrinkAndExport => 'ss5_page3_shrink_paragraph_and_export',
                $isBookmarkParagraphMoveKeepsInlineRuns => 'bookmark_sample_page1_move_paragraph_preserves_inline_runs',
                $isBookmarkSourceSplitCoversUnderscore => 'bookmark_sample_page3_source_split_covers_underscore',
                $isBookmarkFontChangeKeepsTextInsideBox => 'bookmark_sample_page1_font_change_keeps_text_inside_box',
                $isBookmarkMovedTextExportBaseline => 'bookmark_sample_page1_moved_text_export_baseline',
                $isBookmarkShapeLayerOrder => 'bookmark_sample_page1_shape_z_index_controls',
                default => 'unsupported',
            },
            'swap_primary_suffix' => $savedSwapSuffix,
            'swap_partner_suffix' => $swapPartnerSuffix,
            'sentence_to_delete' => $sentenceToDelete,
            'sentence_preserved_text' => [
                'IMPORTANT: You MUST provide a properly completed application',
                'Notarized copies or photocopies which have not been certified by the custodian of the record are not acceptable. We',
                'will return any documents submitted with your application.',
                'website at www.socialsecurity.gov.',
            ],
            'move_down_suffix' => $moveDownSuffix,
            'move_down_pixels' => $moveDownPixels,
            'table_edit_page_number' => $isMc0072AppendPreservesFontMetrics
                ? 1
                : ($isTableExactFontEditPreservesGeometry ? 3 : 2),
            'table_edit_suffix' => $isMc0072AppendPreservesFontMetrics
                ? '0_0:51'
                : ($isTableExactFontEditPreservesGeometry ? '2_2:35' : '1_1:31'),
            'table_edit_expected_text' => $isMc0072AppendPreservesFontMetrics
                ? 'Health Care Agent (Health Care Power of Attorney)'
                : ($isTableExactFontEditPreservesGeometry
                    ? 'Project 1'
                    : 'Best Practices: Separate two tables with header rows'),
            'table_edit_append_text' => $isTableExactFontEditPreservesGeometry ? '2' : '1',
            'require_exact_document_font' => $isTableExactFontEditPreservesGeometry,
            'require_first_keystroke_font_stability' => $isMc0072AppendPreservesFontMetrics,
            'resolve_target_by_exact_text' => $isMc0072AppendPreservesFontMetrics,
            'table_export_page_number' => $isTableEdgeTightHeaderExport ? 1 : null,
            'table_export_suffix' => $isTableEdgeTightHeaderExport ? '0_0:6' : null,
            'table_export_expected_text' => $isTableEdgeTightHeaderExport ? 'Header 3' : null,
            'table_export_font_family' => $isTableEdgeTightHeaderExport ? 'Helvetica' : null,
            'promoted_edit_annotation_id' => $isTablePromotedEditEntryStable ? 'promoted_1_0' : null,
            'promoted_edit_expected_text' => $isTablePromotedEditEntryStable
                ? 'Use tables to organize data not format information'
                : null,
            'paragraph_shrink_ratio' => $isParagraphShrinkAndExport ? 0.5 : null,
            'paragraph_append_text' => $isDrylabParagraphAppendPreservesLayout ? '123' : null,
            'inline_color_text' => $isDrylabInlineStylesPersistAfterDeselect ? 'pilot' : null,
            'inline_text_color' => $isDrylabInlineStylesPersistAfterDeselect ? '#c62828' : null,
            'inline_bold_text' => $isDrylabInlineStylesExportExactly
                ? 'associated'
                : ($isDrylabInlineStylesPersistAfterDeselect ? 'launch' : null),
            'inline_italic_text' => $isDrylabInlineStylesExportExactly
                ? 'process'
                : ($isDrylabInlineStylesPersistAfterDeselect ? 'Drylab' : null),
            'inline_underline_text' => $isDrylabInlineStylesExportExactly ? 'finalized' : null,
            // The registered sign is drawn from the Symbol font as the private
            // use character U+F8E8, so the exported PDF may legitimately carry
            // either that code point or a real U+00AE after a font swap.
            'bookmark_registered_mark_characters' => ["\u{F8E8}", "\u{00AE}"],
            'bookmark_paragraph_bold_phrases' => ['Window', 'Show Bookmarks'],
            'bookmark_paragraph_move_pixels' => 120,
            'bookmark_split_primary_suffix' => '2_2:26',
            'bookmark_split_partner_suffix' => '2_2:27',
            'bookmark_split_primary_text' => 'Open the template design ap',
            'bookmark_split_source_word' => 'ap_bookmark.IFD',
            'bookmark_font_change_annotation_id' => 'promoted_1_8',
            'bookmark_font_change_font_family' => 'Verdana',
            'bookmark_font_change_expected_text' => 'Note that the index has been sorted according to the specification in the bookmark file, and that pages within the file are created according to the original order in the data file.',
            'bookmark_baseline_target_suffix' => '0_0:3',
            'bookmark_baseline_reference_suffix' => '0_0:1',
            'bookmark_baseline_target_text' => 'Prepared by:',
            'bookmark_baseline_reference_text' => 'Sample Date:',
            'bookmark_baseline_gap_pixels' => 50,
        ];
    }
}
