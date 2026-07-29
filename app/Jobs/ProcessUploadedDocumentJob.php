<?php

namespace App\Jobs;

use App\Http\Controllers\DocumentController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessUploadedDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(
        public int $documentId,
        public ?string $userEmail = null,
        public ?string $sessionId = null,
    ) {
        $this->onQueue('default');
    }

    public static function processingCacheKey(int $documentId): string
    {
        return "document:{$documentId}:upload-processing";
    }

    public function handle(DocumentController $documentController): void
    {
        $documentController->processUploadedDocument(
            $this->documentId,
            $this->userEmail,
            $this->sessionId,
        );

        Cache::forget(self::processingCacheKey($this->documentId));
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::forget(self::processingCacheKey($this->documentId));
    }
}
