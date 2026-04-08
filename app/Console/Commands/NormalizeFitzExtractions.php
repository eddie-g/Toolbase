<?php

namespace App\Console\Commands;

use App\Models\PdfExtractionFitz;
use App\Services\PdfFitzExtractionNormalizer;
use Illuminate\Console\Command;

class NormalizeFitzExtractions extends Command
{
    protected $signature = 'pdf:normalize-fitz-extractions
        {--document= : Normalize snapshots for a single document ID}
        {--snapshot= : Normalize a single pdf_extractions_fitz row ID}
        {--limit=0 : Limit how many snapshots to process}';

    protected $description = 'Normalize stored Fitz extraction snapshots into page/block/span tables';

    public function handle(PdfFitzExtractionNormalizer $normalizer): int
    {
        $query = PdfExtractionFitz::query()->orderBy('id');

        $snapshotId = $this->option('snapshot');
        if (is_numeric($snapshotId) && (int) $snapshotId > 0) {
            $query->where('id', (int) $snapshotId);
        }

        $documentId = $this->option('document');
        if (is_numeric($documentId) && (int) $documentId > 0) {
            $query->where('document_id', (int) $documentId);
        }

        $limit = (int) ($this->option('limit') ?? 0);
        if ($limit > 0) {
            $query->limit($limit);
        }

        $snapshots = $query->get();
        if ($snapshots->isEmpty()) {
            $this->info('No Fitz extraction snapshots matched the given filters.');
            return self::SUCCESS;
        }

        $processed = 0;
        foreach ($snapshots as $snapshot) {
            $normalizedSnapshotId = $normalizer->syncSnapshot($snapshot);
            if ($normalizedSnapshotId === null) {
                $this->warn("Skipped snapshot {$snapshot->id}: missing or invalid extraction data.");
                continue;
            }

            $processed++;
            $this->line("Normalized snapshot {$normalizedSnapshotId} for document {$snapshot->document_id}.");
        }

        $this->info("Normalized {$processed} snapshot(s).");

        return self::SUCCESS;
    }
}
