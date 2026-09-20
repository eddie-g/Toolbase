<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentPreviews;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateDocumentPreviews extends Command
{
    protected $signature = 'documents:migrate-previews
        {--dry-run : Count what would be moved without changing anything}
        {--chunk=200 : Rows per batch}';

    protected $description = 'Move document previews from base64 in documents.preview_image to files under previews/';

    public function handle(DocumentPreviews $previews): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $pending = DB::table('documents')->whereNotNull('preview_image')->where('preview_image', '!=', '');
        $total = (clone $pending)->count();
        $bytes = (int) (clone $pending)->sum(DB::raw('length(preview_image)'));
        $this->info(sprintf('%d previews still in the row, %.1f MB of base64.', $total, $bytes / 1048576));
        if ($dryRun || $total === 0) {
            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;
        // Each moved row leaves the set, so every pass reads the first chunk again.
        do {
            $rows = (clone $pending)->orderBy('id')->limit(max(1, (int) $this->option('chunk')))->get(['id', 'preview_image', 'preview_image_mime_type']);
            foreach ($rows as $row) {
                $bytes = base64_decode((string) $row->preview_image, true);
                $document = Document::withTrashed()->select(['id', 'preview_path', 'preview_image_mime_type', 'preview_image_updated_at'])->find($row->id);
                if ($bytes === false || $bytes === '' || ! $document) {
                    // Not an image after all: drop it rather than loop on it.
                    DB::table('documents')->where('id', $row->id)->update(['preview_image' => null]);
                    $skipped++;

                    continue;
                }
                $previews->moveLegacyToFile($document, $bytes, $row->preview_image_mime_type ?: 'image/jpeg');
                $moved++;
            }
            $this->line("  moved {$moved}, skipped {$skipped}");
        } while ($rows->isNotEmpty());

        $this->info("Done: {$moved} previews are files now, {$skipped} unreadable entries were cleared.");

        return self::SUCCESS;
    }
}
