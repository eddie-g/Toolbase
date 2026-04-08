<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\OverlayEditorTest;
use App\Models\PdfExtractionPage;
use App\Models\PdfExtractionSpan;
use App\Models\PdfState;
use Illuminate\Http\Request;
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
        ]);

        $testKey = $request->input('test_key');
        $runId = $request->input('run_id');
        $nodeScript = base_path('tests/OverlayEditor/Pdf/run_pdf_tests.cjs');
        $baseUrl = $this->resolveScriptBaseUrl($request);

        $command = sprintf(
            'BASE_URL=%s timeout 240 node %s --single-test %s 2>&1',
            escapeshellarg($baseUrl),
            escapeshellarg($nodeScript),
            escapeshellarg($testKey)
        );

        $output = shell_exec($command);
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
        $result['test_key'] = $testKey;
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
                $reportPayload['test_key'] = $testKey;
            }

            $report = OverlayEditorTest::create($this->filterPersistableReportPayload($reportPayload));
            $reportId = $report->id;
            $createdAt = $report->created_at?->toIso8601String();
        } catch (\Throwable $error) {
            Log::warning('Failed to persist PDF test result', [
                'test_key' => $testKey,
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

    public function compareWrittenVsOriginal(Request $request, Document $document)
    {
        $python = $this->resolvePythonBinary();
        $script = base_path('python/test_helpers/compare_written_vs_original.py');

        // The original/reference PDF (has the real text in it)
        $originalPath = Storage::path($document->path);
        if (!file_exists($originalPath)) {
            return response()->json([
                'success' => false,
                'message' => 'PDF file not found for this document.',
            ], 422);
        }

        // Optional clean/redacted base (use if it exists on disk)
        $cleanPath = null;
        if ($document->original_backup_path) {
            $candidate = Storage::path($document->original_backup_path);
            if (file_exists($candidate)) {
                $cleanPath = $candidate;
            }
        }

        // If a specific state_id is requested, use only that annotation;
        // otherwise fall back to all annotations sorted by page/id (first one used).
        $stateId = $request->input('state_id');
        if ($stateId) {
            $state = PdfState::where('document_id', $document->id)->find((int) $stateId);
            if (!$state || empty($state->annotation_data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Annotation state not found.',
                ], 422);
            }
            $annotations    = [$state->annotation_data];
            if ($state->pdf_extraction_fitz_id) {
                $annotations[0] = $this->enrichAnnotationFromDb($annotations[0], $state->pdf_extraction_fitz_id);
            }
            $totalCount     = 1;
        } else {
            $states = PdfState::where('document_id', $document->id)
                ->orderBy('page_number')
                ->orderBy('id')
                ->get();
            $annotations = $states->map(function (PdfState $state) {
                $ann = is_array($state->annotation_data) ? $state->annotation_data : [];
                if (empty($ann)) {
                    return $ann;
                }
                if ($state->pdf_extraction_fitz_id) {
                    $ann = $this->enrichAnnotationFromDb($ann, $state->pdf_extraction_fitz_id);
                }
                return $ann;
            })->filter(fn ($a) => !empty($a))->values()->all();
            $totalCount = count($annotations);
        }

        if (empty($annotations)) {
            return response()->json([
                'success' => false,
                'message' => 'No annotations found for this document.',
            ], 422);
        }

        // Write annotations to a temp JSON file
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Inject runtime fields the Python writer needs for pixel-perfect reconstruction:
        //   __sourcePdfPath  → enables replay_promoted_source_block (show_pdf_page from original)
        //   __documentId     → enables embedded font lookup (avoids font substitution drift)
        foreach ($annotations as &$annotation) {
            $annotation['__sourcePdfPath'] = $originalPath;
            $annotation['__documentId']    = $document->id;
        }
        unset($annotation);

        $annotationsFile = $tempDir . '/cmp_orig_' . $document->id . '_' . uniqid() . '.json';

        if (@file_put_contents($annotationsFile, json_encode($annotations, JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
            return response()->json(['success' => false, 'message' => 'Failed to write annotations temp file.'], 500);
        }

        $dpi          = min(max((int) $request->input('dpi', 150), 72), 300);
        $cropPadding  = min(max((float) $request->input('crop_padding', 50), 0), 200);

        $command = sprintf(
            '%s %s --original-pdf %s --annotations-json %s --dpi %d --crop-padding %s%s 2>&1',
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($originalPath),
            escapeshellarg($annotationsFile),
            $dpi,
            escapeshellarg((string) $cropPadding),
            $cleanPath ? ' --clean-pdf ' . escapeshellarg($cleanPath) : ''
        );

        $rawOutput  = shell_exec($command);
        @unlink($annotationsFile);

        if (!$rawOutput) {
            return response()->json(['success' => false, 'message' => 'Compare script produced no output.'], 500);
        }

        $result = json_decode($rawOutput, true);
        if (!is_array($result)) {
            return response()->json([
                'success' => false,
                'message' => 'Compare script output was not valid JSON.',
                'raw'     => substr($rawOutput, 0, 2000),
            ], 500);
        }

        // Compute alignment pass/fail from the crop pixel diff.
        // Threshold is lower when a clean base PDF was available (no background noise),
        // and more lenient when rendering against the raw original (base_used="blank").
        $cropDiffPct = $result['crop']['pixel_diff_pct'] ?? null;
        $baseUsed    = $result['base_used'] ?? 'blank';
        $threshold   = $baseUsed === 'clean_pdf' ? 5.0 : 20.0;
        $alignment   = null;
        if (is_numeric($cropDiffPct)) {
            $alignment = ((float) $cropDiffPct <= $threshold) ? 'pass' : 'fail';
        }

        // Persist alignment on the specific state record when one was targeted.
        if ($stateId && $alignment !== null && isset($state)) {
            $state->alignment = $alignment;
            $state->save();
        }

        return response()->json(array_merge($result, [
            'document_id'       => $document->id,
            'total_annotations' => $totalCount,
            'alignment'         => $alignment,
            'alignment_threshold' => $threshold,
        ]));
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

    private function enrichAnnotationFromDb(array $annotation, int $fitzId): array
    {
        $pageIndex = (int) ($annotation['pageIndex'] ?? 0);
        $dbPageNum = $pageIndex + 1;

        // Refresh sourceSpans with canonical span data from the live extraction table.
        $sourceSpans = $annotation['sourceSpans'] ?? [];
        if (is_array($sourceSpans) && !empty($sourceSpans)) {
            $spanQuery = PdfExtractionSpan::where('pdf_extraction_fitz_id', $fitzId)
                ->where('page_number', $dbPageNum);

            $blockNum = (int) ($annotation['promotedSourceBlockNum'] ?? 0);
            if ($blockNum > 0) {
                $spanQuery->where('block_num', $blockNum);
            }

            $candidateSpans = $spanQuery->get();
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

        // Add page geometry from the live page table (width, height, drawn boxes, widgets).
        $page = PdfExtractionPage::where('pdf_extraction_fitz_id', $fitzId)
            ->where('page_number', $dbPageNum)
            ->first();

        if ($page) {
            $annotation['__sourcePdfPageWidth']  = $page->width;
            $annotation['__sourcePdfPageHeight'] = $page->height;
            $annotation['__drawnBoxRects']       = $page->drawn_box_rects;
            $annotation['__widgetRects']         = $page->widget_rects;
        }

        return $annotation;
    }

    public function compareFirstAnnotation(Request $request, Document $document)
    {
        $python = $this->resolvePythonBinary();
        $script = base_path('python/test_helpers/compare_first_annotation.py');

        // Resolve the clean/base PDF (original backup, no annotations)
        $cleanPath = $document->original_backup_path
            ? Storage::path($document->original_backup_path)
            : null;

        if (!$cleanPath || !file_exists($cleanPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Original backup PDF not found for this document. '
                           . 'Save the document once to generate a backup.',
            ], 422);
        }

        // Gather all annotations for this document, sorted by page then state order
        $states = PdfState::where('document_id', $document->id)
            ->orderBy('page_number')
            ->orderBy('id')
            ->get();

        $annotations = $states->map(function (PdfState $state) {
            return is_array($state->annotation_data) ? $state->annotation_data : [];
        })->filter(fn ($a) => !empty($a))->values()->all();

        if (empty($annotations)) {
            return response()->json([
                'success' => false,
                'message' => 'No annotations found for this document.',
            ], 422);
        }

        // Write annotations to a temp JSON file
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $annotationsFile = $tempDir . '/compare_ann_' . $document->id . '_' . uniqid() . '.json';

        if (@file_put_contents($annotationsFile, json_encode($annotations, JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
            return response()->json(['success' => false, 'message' => 'Failed to write annotations temp file.'], 500);
        }

        $dpi     = min(max((int) $request->input('dpi', 150), 72), 300);
        $command = sprintf(
            '%s %s --clean-pdf %s --annotations-json %s --dpi %d 2>&1',
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($cleanPath),
            escapeshellarg($annotationsFile),
            $dpi
        );

        $rawOutput  = shell_exec($command);
        @unlink($annotationsFile);

        if (!$rawOutput) {
            return response()->json(['success' => false, 'message' => 'Compare script produced no output.'], 500);
        }

        $result = json_decode($rawOutput, true);
        if (!is_array($result)) {
            return response()->json([
                'success' => false,
                'message' => 'Compare script output was not valid JSON.',
                'raw'     => substr($rawOutput, 0, 2000),
            ], 500);
        }

        return response()->json(array_merge($result, [
            'document_id'        => $document->id,
            'total_annotations'  => count($annotations),
        ]));
    }

    public function documentInfo(Request $request, Document $document)
    {
        $states = PdfState::where('document_id', $document->id)->orderBy('id')->get();

        // Deduplicate by annotation `id` field, keeping the highest db_id (most recent save)
        $seen = [];
        $annotations = $states->map(function (PdfState $state) use (&$seen) {
            $data = is_array($state->annotation_data) ? $state->annotation_data : [];
            if (!empty($data) && $state->pdf_extraction_fitz_id) {
                $data = $this->enrichAnnotationFromDb($data, $state->pdf_extraction_fitz_id);
            }
            $annId = $data['id'] ?? null;
            if ($annId !== null) {
                $seen[$annId] = array_merge([
                    'db_id'          => $state->id,
                    'db_page_number' => $state->page_number,
                    'db_state'       => $state->state,
                    'db_updated_at'  => optional($state->updated_at)->toIso8601String(),
                ], $data);
                return null; // placeholder, replaced below
            }
            return array_merge([
                'db_id'          => $state->id,
                'db_page_number' => $state->page_number,
                'db_state'       => $state->state,
                'db_updated_at'  => optional($state->updated_at)->toIso8601String(),
            ], $data);
        })->filter(fn($item) => $item !== null)->values();

        // Suppress parent annotations whose line-split children are also present.
        // Child ids match pattern "<parent_id>_lines-N-M". When both the parent
        // and at least one child exist in the final set, the parent must be removed
        // so the overlay doesn't render the same text twice.
        $allAnnIds = array_keys($seen);
        $parentsToSuppress = [];
        foreach ($allAnnIds as $annId) {
            if (preg_match('/^(.+?)_lines-\d+-\d+$/', $annId, $m)) {
                $parentId = $m[1];
                if (isset($seen[$parentId])) {
                    $parentsToSuppress[$parentId] = true;
                }
            }
        }

        // Also suppress simple _lines-N-M variants when modifier-qualified siblings exist
        // for the same base (e.g. A_inline-bullet-lines-* is a more-specific split than
        // A_lines-N-M; writing both would double-render the same text).
        $baseToSimpleLines = [];
        foreach ($allAnnIds as $annId) {
            if (preg_match('/^(.+?)_lines-\d+-\d+$/', $annId, $m)) {
                $baseToSimpleLines[$m[1]][] = $annId;
            }
        }
        foreach ($baseToSimpleLines as $base => $simpleVariants) {
            $prefix = $base . '_';
            $found = false;
            foreach ($allAnnIds as $annId) {
                if (str_starts_with($annId, $prefix)
                    && str_contains($annId, '-lines-')
                    && !preg_match('/^(.+?)_lines-\d+-\d+$/', $annId)) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                foreach ($simpleVariants as $v) {
                    $parentsToSuppress[$v] = true;
                }
            }
        }

        // Rule 3: Suppress A when any A_<suffix> child exists where the suffix contains '-line'.
        // Catches parents whose children use _label-line-*, _leader-line-*,
        // _inline-bullet-lines-* etc. (Rules 1 & 2 only fire on plain _lines-N-M children).
        foreach ($allAnnIds as $annId) {
            if (isset($parentsToSuppress[$annId])) {
                continue;
            }
            $prefix = $annId . '_';
            foreach ($allAnnIds as $otherId) {
                if (str_starts_with($otherId, $prefix)
                    && str_contains(substr($otherId, strlen($annId)), '-line')) {
                    $parentsToSuppress[$annId] = true;
                    break;
                }
            }
        }

        // Rule 4: Suppress decorative dot-leader separator annotations.
        // These are visual spacers extracted from the original PDF form structure
        // (e.g. '. . . . .' between form sections) that must not be rendered as
        // visible text in the reconstruction overlay.
        foreach ($allAnnIds as $annId) {
            if (str_contains($annId, 'leader-for-')) {
                $parentsToSuppress[$annId] = true;
            }
        }

        foreach (array_keys($parentsToSuppress) as $parentId) {
            unset($seen[$parentId]);
        }

        // Merge deduplicated keyed annotations
        $annotations = $annotations->merge(array_values($seen))->values();

        // Load embedded font metadata if available (same JSON produced by extract_pdf_pymupdf)
        $embeddedFonts = null;
        $embeddedFontsPath = storage_path("app/temp/embedded_fonts_{$document->id}.json");
        if (file_exists($embeddedFontsPath)) {
            $embeddedFonts = json_decode(file_get_contents($embeddedFontsPath), true) ?: null;
        }

        return response()->json([
            'success'        => true,
            'document'       => [
                'id'              => $document->id,
                'name'            => $document->original_name,
                'file_url'        => route('documents.file', $document),
                'original_url'    => route('documents.originalFile', $document),
                'clean_url'       => route('documents.cleanPdf', $document),
            ],
            'annotations'    => $annotations,
            'count'          => $annotations->count(),
            'embedded_fonts' => $embeddedFonts,
        ]);
    }
}
