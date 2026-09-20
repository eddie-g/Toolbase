<?php

namespace App\Jobs;

use App\Exceptions\PythonServiceBusyException;
use App\Http\Controllers\DocumentController;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders the thumbnail the documents page shows. The page used to do this
 * itself, forking Python for up to eight documents inside one page view; now
 * it queues one of these per document that has no preview yet and shows the
 * placeholder until it lands. Unique per document, so reloading the page does
 * not pile them up.
 */
class GenerateDocumentPreviewJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    public function __construct(public int $documentId)
    {
        $this->onConnection(config('pdf_editor.extraction.connection', 'redis'));
        $this->onQueue(config('pdf_editor.extraction.queue', 'pdf-extraction'));
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    public function handle(DocumentController $documents): void
    {
        $document = Document::withTrashed()->find($this->documentId);
        if (! $document || empty($document->path)) {
            return;
        }

        try {
            $documents->refreshDocumentPreview($document);
        } catch (PythonServiceBusyException) {
            $this->release(20);
        }
    }

    public function tags(): array
    {
        return ['document-preview', "document:{$this->documentId}"];
    }
}
