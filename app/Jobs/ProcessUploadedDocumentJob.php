<?php

namespace App\Jobs;

use App\Http\Controllers\DocumentController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(DocumentController $documentController): void
    {
        $documentController->processUploadedDocument(
            $this->documentId,
            $this->userEmail,
            $this->sessionId,
        );
    }
}