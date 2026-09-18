<?php

namespace App\Jobs;

use App\Exceptions\PythonServiceBusyException;
use App\Http\Controllers\DocumentController;
use App\Services\DocumentProcessing;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;

/**
 * Extracts an uploaded PDF's text into editable paragraphs. The editor waits
 * on documents.processing_status, so every way out of this job writes it.
 *
 * One job per document at a time: unique while queued or running, and not
 * overlapping with a copy that slipped through (an expired lock, a manual
 * dispatch). A PDF that fails or times out is not retried, since the same
 * input ends the same way; only a busy Python pool or an unexpected crash is.
 */
class ProcessUploadedDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    // The default matters: a job serialized before this property existed is
    // unserialized without the constructor running.
    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(
        public int $documentId,
        public ?string $userEmail = null,
        public ?string $sessionId = null,
    ) {
        $this->timeout = max(30, (int) config('pdf_editor.extraction.job_timeout', 300));
        $this->onConnection(config('pdf_editor.extraction.connection', 'redis'));
        $this->onQueue(config('pdf_editor.extraction.queue', 'pdf-extraction'));
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->documentId))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(DocumentController $documentController, DocumentProcessing $processing): void
    {
        $processing->markExtracting($this->documentId, $this->attempts());

        try {
            $result = $documentController->processUploadedDocument(
                $this->documentId,
                $this->userEmail,
                $this->sessionId,
            );
        } catch (PythonServiceBusyException $exception) {
            if ($this->attempts() < $this->tries) {
                $processing->markQueuedAgain($this->documentId);
                $this->release(15);

                return;
            }
            $this->finishFailed($processing, 'busy');

            return;
        }

        if ($result['success'] ?? false) {
            $processing->markReady($this->documentId);

            return;
        }

        $this->finishFailed($processing, (string) ($result['code'] ?? 'extraction_failed'));
    }

    /** The job ran past its budget, its worker died, or the last attempt threw. */
    public function failed(?\Throwable $exception): void
    {
        $this->finishFailed(
            app(DocumentProcessing::class),
            $exception instanceof TimeoutExceededException ? 'timeout' : 'worker_failed',
            $exception
        );
    }

    public function tags(): array
    {
        return ['pdf-extraction', "document:{$this->documentId}"];
    }

    private function finishFailed(DocumentProcessing $processing, string $code, ?\Throwable $exception = null): void
    {
        $processing->markFailed($this->documentId, $code);
        Log::error('Upload processing failed', [
            'document_id' => $this->documentId,
            'code' => $code,
            'attempt' => $this->attempts(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
