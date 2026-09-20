<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentRemoval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * The retention policy (config pdf_editor.retention), applied:
 *
 *  - a document that has been in the trash for trash_days is deleted for good;
 *  - rows that belong to a document that no longer exists are removed;
 *  - live-save page renders and admin stamp previews past their age are removed.
 *
 * Guests' documents: documents:prune-guests. Working files and finished
 * exports: documents:cleanup-temp (hourly).
 */
class PruneDocuments extends Command
{
    /** Tables with a document_id whose rows mean nothing once the document is gone. */
    private const DEPENDENT_TABLES = [
        'pdf_extractions_fitz', 'pdf_extraction_spans', 'pdf_extraction_blocks', 'pdf_extraction_pages',
        'pdf_state', 'pdf_groups', 'pdf_acro_form', 'pdf_exports', 'guest_documents',
    ];

    protected $signature = 'documents:prune {--dry-run : Say what would be removed without removing it}';

    protected $description = 'Apply the retention policy: old trash, rows and files left behind by deleted documents, old previews';

    public function handle(DocumentRemoval $removal): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $verb = $dryRun ? 'would remove' : 'removed';

        [$documents, $bytes] = $this->pruneTrash($removal, $dryRun);
        $this->line(sprintf('Trash older than %d days: %s %d documents (%s)', $this->trashDays(), $verb, $documents, Number::fileSize($bytes)));

        foreach ($this->pruneOrphanedRows($dryRun) as $table => $rows) {
            $this->line(sprintf('Rows of deleted documents in %s: %s %d', $table, $verb, $rows));
        }

        [$files, $bytes] = $this->pruneLiveSavePreviews($dryRun);
        $this->line(sprintf('Live-save renders: %s %d files (%s)', $verb, $files, Number::fileSize($bytes)));

        [$files, $bytes] = $this->pruneDebugPreviews($dryRun);
        $this->line(sprintf('Stamp previews on the public disk: %s %d files (%s)', $verb, $files, Number::fileSize($bytes)));

        return self::SUCCESS;
    }

    private function trashDays(): int
    {
        return max(1, (int) config('pdf_editor.retention.trash_days', 30));
    }

    /** @return array{0: int, 1: int} */
    private function pruneTrash(DocumentRemoval $removal, bool $dryRun): array
    {
        $count = 0;
        $bytes = 0;
        Document::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($this->trashDays()))
            ->orderBy('id')
            ->chunkById(100, function ($documents) use ($removal, $dryRun, &$count, &$bytes) {
                foreach ($documents as $document) {
                    $count++;
                    $bytes += $dryRun ? (int) $document->size_bytes : $removal->purge($document);
                }
            });

        return [$count, $bytes];
    }

    /** @return array<string, int> */
    private function pruneOrphanedRows(bool $dryRun): array
    {
        $removed = [];
        foreach (self::DEPENDENT_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'document_id')) {
                continue;
            }
            // Trashed documents still have a row: only what points at nothing counts.
            $orphans = fn () => DB::table($table)
                ->whereNotNull('document_id')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('documents')->whereColumn('documents.id', "{$table}.document_id"));

            $rows = 0;
            if ($dryRun) {
                $rows = $orphans()->count();
            } else {
                // In pieces: pdf_extraction_spans has hundreds of thousands of rows and one DELETE would lock them all.
                do {
                    $deleted = $orphans()->limit(5000)->delete();
                    $rows += $deleted;
                } while ($deleted === 5000);
            }
            if ($rows > 0) {
                $removed[$table] = $rows;
            }
        }

        return $removed;
    }

    /** @return array{0: int, 1: int} */
    private function pruneLiveSavePreviews(bool $dryRun): array
    {
        $root = storage_path('app/live-save-previews');
        if (! is_dir($root)) {
            return [0, 0];
        }
        $cutoff = now()->subDays(max(1, (int) config('pdf_editor.retention.live_save_preview_days', 7)))->getTimestamp();
        $count = 0;
        $bytes = 0;

        foreach (File::directories($root) as $directory) {
            $documentGone = ctype_digit(basename($directory)) && ! Document::withTrashed()->whereKey((int) basename($directory))->exists();
            foreach (File::allFiles($directory) as $file) {
                if ($documentGone || $file->getMTime() < $cutoff) {
                    $count++;
                    $bytes += $file->getSize();
                    $dryRun || File::delete($file->getPathname());
                }
            }
            if (! $dryRun && File::allFiles($directory) === []) {
                File::deleteDirectory($directory);
            }
        }

        return [$count, $bytes];
    }

    /** @return array{0: int, 1: int} */
    private function pruneDebugPreviews(bool $dryRun): array
    {
        $disk = Storage::disk('public');
        $cutoff = now()->subHours(max(1, (int) config('pdf_editor.retention.debug_preview_hours', 24)))->getTimestamp();
        $count = 0;
        $bytes = 0;

        foreach ($disk->files('debug/pdf-state-stamps') as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $count++;
                $bytes += (int) $disk->size($path);
                $dryRun || $disk->delete($path);
            }
        }

        return [$count, $bytes];
    }
}
