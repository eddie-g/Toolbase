<?php

namespace App\Console\Commands;

use App\Models\PdfState;
use App\Services\PdfAnnotationAssetService;
use Illuminate\Console\Command;

class MigratePdfAnnotationBlobs extends Command
{
    protected $signature = 'documents:migrate-annotation-blobs
        {--document-id= : Only migrate a single document}
        {--chunk=100 : Number of rows to process per chunk}
        {--dry-run : Report changes without writing them}';

    protected $description = 'Move image/signature annotation blobs out of pdf_state.annotation_data and replace them with stored file paths';

    public function handle(PdfAnnotationAssetService $annotationAssets): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $documentId = (int) ($this->option('document-id') ?: 0);
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - rows will be scanned but not updated.');
        }

        $query = PdfState::query()
            ->select(['id', 'document_id', 'annotation_data'])
            ->with(['document:id'])
            ->orderBy('id');

        if ($documentId > 0) {
            $query->where('document_id', $documentId);
        }

        $scanned = 0;
        $updated = 0;
        $skipped = 0;
        $blobRows = 0;

        $query->chunkById($chunkSize, function ($records) use ($annotationAssets, $dryRun, &$scanned, &$updated, &$skipped, &$blobRows) {
            foreach ($records as $record) {
                $scanned++;

                if (!$record->document || !is_array($record->annotation_data)) {
                    $skipped++;
                    continue;
                }

                $annotation = $record->annotation_data;
                $hadBlobPayload = $annotationAssets->hasBlobPayload($annotation);
                if ($hadBlobPayload) {
                    $blobRows++;
                }

                $normalized = $annotationAssets->normalizeForPersistence($record->document, $annotation);
                if ($normalized === $annotation) {
                    continue;
                }

                $updated++;

                if ($dryRun) {
                    continue;
                }

                $record->annotation_data = $normalized;
                $record->save();
            }
        });

        $this->info("Scanned {$scanned} pdf_state rows.");
        $this->line("Rows containing blob payloads: {$blobRows}");
        $this->line($dryRun ? "Rows that would be updated: {$updated}" : "Rows updated: {$updated}");

        if ($skipped > 0) {
            $this->line("Rows skipped: {$skipped}");
        }

        return self::SUCCESS;
    }
}