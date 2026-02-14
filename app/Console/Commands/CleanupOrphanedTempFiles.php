<?php

namespace App\Console\Commands;

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
    protected $signature = 'documents:cleanup-temp {--dry-run : Show what would be deleted without deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up orphaned temp files for deleted documents';

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
