<?php

namespace App\Services;

use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Document;
use App\Models\PdfExtractionFitz;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class PdfDocumentMergeService
{
    public function __construct(
        private readonly PdfDocumentPageOffsetRemapper $pageOffsetRemapper,
    ) {
    }

    /**
     * @param array<string, UploadedFile> $uploads
     * @param list<string> $order
     * @return array<string, mixed>
     */
    public function merge(
        Document $document,
        array $uploads,
        array $order,
        ?string $userEmail = null,
        ?string $sessionId = null,
    ): array {
        $lock = Cache::lock("document:{$document->id}:structure", 180);
        if (!$lock->get()) {
            throw new PdfDocumentMergeConflictException('Another page operation is already running. Please try again.');
        }

        $manifestPath = null;
        $candidatePath = null;
        $rollbackPath = null;
        $workingPath = null;
        $workingFileWasReplaced = false;

        try {
            $this->validateOrder($uploads, $order);
            if (!$document->path || !Storage::exists($document->path)) {
                throw new RuntimeException('The current PDF is missing.');
            }

            $workingPath = Storage::path($document->path);
            $totalBytes = (int) filesize($workingPath);
            foreach ($uploads as $upload) {
                if (!$upload instanceof UploadedFile || !$upload->isValid()) {
                    throw new RuntimeException('One of the selected PDF files is invalid.');
                }
                $totalBytes += (int) $upload->getSize();
            }
            $maxTotalBytes = max(1, (int) config('pdf_editor.merge.max_total_bytes', 104857600));
            if ($totalBytes > $maxTotalBytes) {
                throw new RuntimeException('The selected PDFs exceed the total merge size limit.');
            }

            Storage::makeDirectory('documents');
            $operationId = Str::uuid()->toString();
            $candidatePath = Storage::path("documents/temp_merge_{$operationId}.pdf");
            $rollbackPath = Storage::path("documents/temp_merge_rollback_{$operationId}.pdf");
            $manifestPath = Storage::path("documents/temp_merge_{$operationId}.json");

            $inputs = [];
            foreach ($order as $inputId) {
                $inputs[] = [
                    'id' => $inputId,
                    'path' => $inputId === 'current'
                        ? $workingPath
                        : $uploads[$inputId]->getRealPath(),
                ];
            }
            file_put_contents($manifestPath, json_encode([
                'inputs' => $inputs,
                'output' => $candidatePath,
                'max_pages' => max(1, (int) config('pdf_editor.merge.max_pages', 1000)),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $process = new Process([
                $this->resolvePythonBinary('fitz'),
                base_path('python/pdf-editor/merge_pdf_documents.py'),
                $manifestPath,
            ]);
            $process->setTimeout(max(10, (int) config('pdf_editor.merge.timeout_seconds', 120)));
            $process->run();

            $result = $this->decodeProcessResult($process->getOutput());
            if (!$process->isSuccessful() || !($result['success'] ?? false) || !is_file($candidatePath)) {
                $message = (string) ($result['error'] ?? 'The PDF files could not be merged.');
                foreach ($inputs as $input) {
                    $message = str_replace((string) $input['path'], 'selected PDF', $message);
                }
                throw new RuntimeException($message);
            }

            $currentOffset = (int) ($result['current_document_start_page'] ?? 0);
            if (!copy($workingPath, $rollbackPath)) {
                throw new RuntimeException('The current PDF could not be prepared for merging.');
            }

            try {
                DB::transaction(function () use (
                    $document,
                    $workingPath,
                    $candidatePath,
                    $currentOffset,
                    &$workingFileWasReplaced,
                ): void {
                    if (!@rename($candidatePath, $workingPath)) {
                        if (!copy($candidatePath, $workingPath)) {
                            throw new RuntimeException('The merged PDF could not be saved.');
                        }
                        @unlink($candidatePath);
                    }
                    $workingFileWasReplaced = true;

                    $this->pageOffsetRemapper->remap($document, $currentOffset);
                    PdfExtractionFitz::query()->where('document_id', $document->id)->delete();

                    $document->forceFill([
                        'size_bytes' => (int) filesize($workingPath),
                        'mime_type' => 'application/pdf',
                    ])->save();
                }, 3);
            } catch (Throwable $error) {
                if ($workingFileWasReplaced && is_file($rollbackPath)) {
                    copy($rollbackPath, $workingPath);
                }
                throw $error;
            }

            try {
                ProcessUploadedDocumentJob::dispatch(
                    (int) $document->id,
                    $userEmail,
                    $sessionId,
                );
            } catch (Throwable $error) {
                Log::warning('Merged PDF saved but extraction could not be queued', [
                    'document_id' => $document->id,
                    'error' => $error->getMessage(),
                ]);
            }

            return $result;
        } finally {
            foreach ([$manifestPath, $candidatePath, $rollbackPath] as $temporaryPath) {
                if (is_string($temporaryPath) && is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
            $lock->release();
        }
    }

    /** @param array<string, UploadedFile> $uploads @param list<string> $order */
    private function validateOrder(array $uploads, array $order): void
    {
        $uploadIds = array_keys($uploads);
        $expected = array_merge(['current'], $uploadIds);
        $actual = array_values($order);

        if (count($actual) !== count($expected)
            || count($actual) !== count(array_unique($actual))
            || count(array_intersect($expected, $actual)) !== count($expected)) {
            throw new RuntimeException('The PDF document order is invalid.');
        }

        foreach ($uploadIds as $uploadId) {
            if (!preg_match('/^upload-[a-z0-9-]{1,64}$/', (string) $uploadId)) {
                throw new RuntimeException('A PDF upload identifier is invalid.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function decodeProcessResult(string $output): array
    {
        $lines = array_reverse(array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: []))));
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function resolvePythonBinary(?string $requiredModule = null): string
    {
        $candidates = array_values(array_unique([
            base_path('.venv/bin/python'),
            base_path('venv/bin/python'),
            base_path('python/venv/bin/python'),
            '/usr/bin/python3',
            'python3',
        ]));

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/') && !is_executable($candidate)) {
                continue;
            }
            if ($requiredModule === null) {
                return $candidate;
            }
            $probe = new Process([$candidate, '-c', "import {$requiredModule}"]);
            $probe->setTimeout(10);
            $probe->run();
            if ($probe->isSuccessful()) {
                return $candidate;
            }
        }

        return 'python3';
    }
}
