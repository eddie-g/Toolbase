<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\OverlayEditorTest;
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

        return response()->json([
            'success' => true,
            'document_id' => $document->id,
            'edit_url' => route('documents.edit', $document),
        ]);
    }

    public function artifact(string $filename)
    {
        $safeFilename = basename($filename);
        $path = storage_path('app/overlay_regression_artifacts/' . $safeFilename);

        if (!File::exists($path)) {
            abort(404);
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
}
