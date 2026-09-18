<?php

namespace App\Console\Commands;

use App\Models\PdfExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanedTempFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:cleanup-temp
        {--dry-run : Show what would be deleted without deleting}
        {--stale-hours=6 : Age after which a leaked per-request working file is removed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up orphaned temp files for deleted documents, expired PDF exports and leaked working files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('DRY RUN MODE - No files will be deleted');
        }
        
        $this->info('Scanning for orphaned temp files...');
        
        // Get all valid document IDs
        $validIds = DB::table('documents')->pluck('id')->toArray();
        
        $deletedCount = 0;
        $totalSize = 0;
        
        // Clean up temp_edits_*.json files
        $this->info("\nChecking temp_edits_*.json files...");
        $editsFiles = glob(storage_path('app/temp_edits_*.json'));
        foreach ($editsFiles as $file) {
            if (preg_match('/temp_edits_(\d+)\.json$/', $file, $matches)) {
                $docId = (int) $matches[1];
                if (!in_array($docId, $validIds)) {
                    $size = filesize($file);
                    $totalSize += $size;
                    $deletedCount++;
                    $this->warn("  Orphaned: " . basename($file) . " (" . $this->formatBytes($size) . ")");
                    if (!$dryRun) {
                        @unlink($file);
                    }
                }
            }
        }
        
        // Clean up temp_extraction_*.json files
        $this->info("\nChecking temp_extraction_*.json files...");
        $extractionFiles = glob(storage_path('app/temp_extraction_*.json'));
        foreach ($extractionFiles as $file) {
            if (preg_match('/temp_extraction_(\d+)\.json$/', $file, $matches)) {
                $docId = (int) $matches[1];
                if (!in_array($docId, $validIds)) {
                    $size = filesize($file);
                    $totalSize += $size;
                    $deletedCount++;
                    $this->warn("  Orphaned: " . basename($file) . " (" . $this->formatBytes($size) . ")");
                    if (!$dryRun) {
                        @unlink($file);
                    }
                }
            }
        }
        
        // Clean up temp/clean_*.pdf files
        $this->info("\nChecking temp/clean_*.pdf files...");
        $cleanPdfPath = Storage::path('temp');
        if (is_dir($cleanPdfPath)) {
            $cleanFiles = glob($cleanPdfPath . '/clean_*.pdf');
            foreach ($cleanFiles as $file) {
                if (preg_match('/clean_(\d+)\.pdf$/', $file, $matches)) {
                    $docId = (int) $matches[1];
                    if (!in_array($docId, $validIds)) {
                        $size = filesize($file);
                        $totalSize += $size;
                        $deletedCount++;
                        $this->warn("  Orphaned: " . basename($file) . " (" . $this->formatBytes($size) . ")");
                        if (!$dryRun) {
                            @unlink($file);
                        }
                    }
                }
            }
        }
        
        // Clean up documents/backup_*.pdf files (check if original exists)
        $this->info("\nChecking documents/backup_*.pdf files...");
        $documentsPath = Storage::path('documents');
        if (is_dir($documentsPath)) {
            $backupFiles = glob($documentsPath . '/backup_*.pdf');
            foreach ($backupFiles as $file) {
                $basename = basename($file);
                // Extract the original filename from backup_filename.pdf
                $originalName = preg_replace('/^backup_/', '', $basename);
                $originalPath = $documentsPath . '/' . $originalName;
                
                // If the original file doesn't exist, this backup is orphaned
                if (!file_exists($originalPath)) {
                    $size = filesize($file);
                    $totalSize += $size;
                    $deletedCount++;
                    $this->warn("  Orphaned: " . $basename . " (" . $this->formatBytes($size) . ")");
                    if (!$dryRun) {
                        @unlink($file);
                    }
                }
            }
        }
        
        [$exportCount, $exportSize] = $this->cleanupPdfExports($dryRun);
        $deletedCount += $exportCount;
        $totalSize += $exportSize;

        [$staleCount, $staleSize] = $this->cleanupStaleWorkingFiles($dryRun, max(1, (int) $this->option('stale-hours')));
        $deletedCount += $staleCount;
        $totalSize += $staleSize;

        $this->newLine();
        
        if ($dryRun) {
            $this->info("DRY RUN COMPLETE");
            $this->info("Would delete $deletedCount orphaned files totaling " . $this->formatBytes($totalSize));
            $this->warn("\nRun without --dry-run to actually delete these files:");
            $this->line("  php artisan documents:cleanup-temp");
        } else {
            if ($deletedCount > 0) {
                $this->info("✓ Deleted $deletedCount orphaned files totaling " . $this->formatBytes($totalSize));
            } else {
                $this->info("✓ No orphaned files found");
            }
        }
        
        return 0;
    }
    
    /**
     * Queued Download results (documents/exports/{uuid}) are only kept until
     * their signed link expires. Also fails exports no worker ever picked up,
     * and removes export directories that have no row behind them.
     *
     * @return array{0:int,1:int} files removed, bytes freed
     */
    private function cleanupPdfExports(bool $dryRun): array
    {
        $this->info("\nChecking queued PDF exports...");
        $count = 0;
        $bytes = 0;

        $staleBefore = now()->subMinutes(max(5, (int) config('pdf_export.stale_after_minutes', 30)));
        $stale = PdfExport::query()
            ->whereIn('status', [PdfExport::STATUS_QUEUED, PdfExport::STATUS_PROCESSING])
            ->where('created_at', '<', $staleBefore);
        $staleTotal = (clone $stale)->count();
        if ($staleTotal > 0) {
            $this->warn("  Stalled exports: {$staleTotal}");
            if (!$dryRun) {
                $stale->update([
                    'status' => PdfExport::STATUS_FAILED,
                    'progress' => 100,
                    'error' => 'The export did not finish. Download the PDF again.',
                    'completed_at' => now(),
                    'expires_at' => now()->subSecond(),
                ]);
            }
        }

        $root = Storage::path('documents/exports');
        if (!is_dir($root)) {
            return [$count, $bytes];
        }

        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $uuid = basename($directory);
            $export = PdfExport::query()->where('uuid', $uuid)->first();
            $expired = $export
                ? ($export->expires_at !== null && $export->expires_at->isPast())
                : filemtime($directory) < now()->subHour()->getTimestamp();
            if (!$expired) {
                continue;
            }

            foreach (glob($directory . '/*') ?: [] as $file) {
                $count++;
                $bytes += (int) @filesize($file);
            }
            $this->warn('  Expired: documents/exports/' . $uuid);
            if (!$dryRun) {
                Storage::deleteDirectory('documents/exports/' . $uuid);
            }
        }

        // The rows are only a status record; a week is plenty for support.
        if (!$dryRun) {
            PdfExport::query()->where('created_at', '<', now()->subDays(7))->delete();
        }

        return [$count, $bytes];
    }

    /**
     * Per-request working files that a fatal error, a timeout or a killed
     * worker left behind. Only names the app itself generates are matched, and
     * only once they are older than any request or job could still be using.
     *
     * @return array{0:int,1:int} files removed, bytes freed
     */
    private function cleanupStaleWorkingFiles(bool $dryRun, int $staleHours): array
    {
        $this->info("\nChecking leaked working files older than {$staleHours} h...");
        $count = 0;
        $bytes = 0;
        $cutoff = now()->subHours($staleHours)->getTimestamp();
        $patterns = [
            storage_path('app/temp') => [
                '/^download_annotated_\d+_[0-9a-f-]{36}\.pdf$/',
                '/^download_ann_\d+_[0-9a-f.]+\.json$/',
            ],
            Storage::path('temp') => [
                '/^apply_annotations_original_\d+_[0-9a-f-]{36}\.pdf$/',
                '/^tmp[a-z0-9_]{8}\.pdf$/',
            ],
        ];

        foreach ($patterns as $directory => $regexes) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (scandir($directory) ?: [] as $name) {
                $file = $directory . '/' . $name;
                if (!is_file($file) || filemtime($file) >= $cutoff) {
                    continue;
                }
                foreach ($regexes as $regex) {
                    if (preg_match($regex, $name)) {
                        $size = (int) filesize($file);
                        $count++;
                        $bytes += $size;
                        $this->warn('  Stale: ' . $name . ' (' . $this->formatBytes($size) . ')');
                        if (!$dryRun) {
                            @unlink($file);
                        }
                        break;
                    }
                }
            }
        }

        return [$count, $bytes];
    }

    /**
     * Format bytes to human readable size
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
