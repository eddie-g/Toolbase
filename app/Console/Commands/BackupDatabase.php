<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackups;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep-all : Do not remove old backups}';

    protected $description = 'Dump the database to the backup disk (config/backup.php) and remove backups past their retention';

    public function handle(DatabaseBackups $backups): int
    {
        try {
            $backup = $backups->create();
        } catch (\Throwable $e) {
            // Reported, so a failed nightly backup reaches the error tracker and the log.
            report($e);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $manifest = $backup['manifest'];
        $this->info(sprintf('%s  %s  %d tables  (disk: %s)', $backup['file'], Number::fileSize($manifest['size_bytes']), count($manifest['tables']), config('backup.disk')));

        if (! $this->option('keep-all')) {
            foreach ($backups->prune() as $removed) {
                $this->line('removed '.$removed);
            }
        }

        return self::SUCCESS;
    }
}
