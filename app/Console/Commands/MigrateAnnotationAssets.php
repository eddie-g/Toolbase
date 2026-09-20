<?php

namespace App\Console\Commands;

use App\Services\PdfAnnotationAssetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateAnnotationAssets extends Command
{
    protected $signature = 'documents:migrate-annotation-assets {--dry-run : List what would move without moving it}';

    protected $description = 'Move images and signatures placed on documents from the public disk, where a URL was enough to read them, to the private disk';

    public function handle(): int
    {
        $from = Storage::disk(PdfAnnotationAssetService::LEGACY_DISK);
        $to = Storage::disk(PdfAnnotationAssetService::DISK);
        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $bytes = 0;
        $failed = 0;

        foreach ($from->allFiles(PdfAnnotationAssetService::BASE_DIR) as $path) {
            $size = (int) $from->size($path);
            if ($dryRun) {
                $moved++;
                $bytes += $size;

                continue;
            }

            // Copy, check, then delete: an interrupted run loses nothing and can be run again.
            $stream = $from->readStream($path);
            $written = $stream !== null && $to->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);
            if (! $written || (int) $to->size($path) !== $size) {
                $failed++;
                $this->error("could not move {$path}");

                continue;
            }
            $from->delete($path);
            $moved++;
            $bytes += $size;
        }

        if (! $dryRun) {
            // Leave no empty shell behind under public/storage.
            foreach (array_reverse($from->allDirectories(PdfAnnotationAssetService::BASE_DIR)) as $directory) {
                if ($from->allFiles($directory) === []) {
                    $from->deleteDirectory($directory);
                }
            }
        }

        $this->info(sprintf('%s %d files (%.1f MB)%s', $dryRun ? 'Would move' : 'Moved', $moved, $bytes / 1048576, $failed ? ", {$failed} failed" : ''));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
