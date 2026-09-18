<?php

namespace App\Services;

use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Where an uploaded document is in the extraction pipeline, in
 * documents.processing_status: queued -> extracting -> ready | failed. The job
 * writes it, the editor polls it. Written with the query builder so
 * documents.updated_at (the order of the documents list) is not touched.
 */
class DocumentProcessing
{
    public const QUEUED = 'queued';

    public const EXTRACTING = 'extracting';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** What the user is told for each failure code. Codes are stable; the editor and the logs key on them. */
    private const MESSAGES = [
        'queue_unavailable' => 'The document could not be queued for processing.',
        'never_started' => 'Processing did not start. The service may be busy.',
        'stalled' => 'Processing stopped before it finished.',
        'timeout' => 'This PDF took too long to process.',
        'extraction_timeout' => 'This PDF took too long to process.',
        'extraction_failed' => 'The text of this PDF could not be read.',
        'file_missing' => 'The uploaded file could not be found.',
        'busy' => 'The PDF service is busy right now.',
        'worker_failed' => 'Processing failed unexpectedly.',
    ];

    /**
     * Marks the document queued and dispatches the job. One job per document
     * can be queued or running at a time (the job is unique on the document
     * id), so a refresh, a double submit or a retry cannot duplicate the work.
     * Returns false, with the document marked failed, if the queue refused it.
     */
    public function queue(Document $document, ?string $userEmail = null, ?string $sessionId = null): bool
    {
        $this->write($document->id, [
            'processing_status' => self::QUEUED,
            'processing_error' => null,
            'processing_queued_at' => now(),
            'processing_started_at' => null,
            'processing_finished_at' => null,
        ]);

        try {
            ProcessUploadedDocumentJob::dispatch($document->id, $userEmail, $sessionId);
        } catch (\Throwable $exception) {
            $this->markFailed($document->id, 'queue_unavailable');
            Log::error('Upload processing could not be queued', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    public function markExtracting(int $documentId, int $attempt): void
    {
        $this->write($documentId, [
            'processing_status' => self::EXTRACTING,
            'processing_error' => null,
            'processing_attempts' => $attempt,
            'processing_started_at' => now(),
            'processing_finished_at' => null,
        ]);
    }

    public function markQueuedAgain(int $documentId): void
    {
        $this->write($documentId, [
            'processing_status' => self::QUEUED,
            'processing_queued_at' => now(),
            'processing_started_at' => null,
        ]);
    }

    public function markReady(int $documentId): void
    {
        $this->write($documentId, [
            'processing_status' => self::READY,
            'processing_error' => null,
            'processing_finished_at' => now(),
        ]);
    }

    public function markFailed(int $documentId, string $code): void
    {
        $this->write($documentId, [
            'processing_status' => self::FAILED,
            'processing_error' => $code,
            'processing_finished_at' => now(),
        ]);
    }

    /**
     * The status as the editor should see it. A document that has sat in
     * "queued" or "extracting" longer than the pipeline allows is reported as
     * failed: the job was lost or its worker died without saying so.
     *
     * @return array{status:string, pending:bool, can_retry:bool, error_code:?string, message:?string, waited_seconds:?int}
     */
    public function status(Document $document): array
    {
        $row = DB::table('documents')->where('id', $document->id)->first([
            'processing_status', 'processing_error', 'processing_queued_at', 'processing_started_at',
        ]);
        $status = $row?->processing_status ?: self::READY;   // documents from before the column
        $error = $row?->processing_error;
        $waited = null;

        if ($status === self::QUEUED && $row->processing_queued_at) {
            $waited = (int) Carbon::parse($row->processing_queued_at)->diffInSeconds(now());
            if ($waited > max(60, (int) config('pdf_editor.extraction.stale_queued_seconds', 600))) {
                [$status, $error] = [self::FAILED, 'never_started'];
            }
        } elseif ($status === self::EXTRACTING && $row->processing_started_at) {
            $waited = (int) Carbon::parse($row->processing_started_at)->diffInSeconds(now());
            if ($waited > max(30, (int) config('pdf_editor.extraction.job_timeout', 300)) + 60) {
                [$status, $error] = [self::FAILED, 'stalled'];
            }
        }

        $failed = $status === self::FAILED;

        return [
            'status' => $status,
            'pending' => in_array($status, [self::QUEUED, self::EXTRACTING], true),
            'can_retry' => $failed,
            'error_code' => $failed ? ($error ?: 'worker_failed') : null,
            'message' => $failed ? (self::MESSAGES[$error] ?? self::MESSAGES['worker_failed']) : null,
            'waited_seconds' => $waited,
        ];
    }

    private function write(int $documentId, array $values): void
    {
        DB::table('documents')->where('id', $documentId)->update($values);
    }
}
