<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RestoreDrill extends Command
{
    protected $signature = 'db:restore-drill';

    protected $description = 'Restore the newest backup into a scratch database, check it against its manifest, and drop it again';

    public function handle(DatabaseBackups $backups): int
    {
        try {
            $result = $backups->drill();
        } catch (\Throwable $e) {
            report($e);
            $this->error('Restore drill failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('%s restored in %s s: %d tables', $result['file'], $result['seconds'], $result['tables']));
        $this->table(['table', 'rows at backup', 'rows restored'], array_map(
            static fn (string $table, array $counts) => [$table, $counts['backup'], $counts['restored']],
            array_keys($result['row_counts']),
            $result['row_counts'],
        ));

        if ($result['problems'] !== []) {
            foreach ($result['problems'] as $problem) {
                $this->error($problem);
            }
            Log::error('Restore drill found problems', ['file' => $result['file'], 'problems' => $result['problems']]);

            return self::FAILURE;
        }

        Log::info('Restore drill passed', ['file' => $result['file'], 'seconds' => $result['seconds']]);
        $this->info('The backup restores.');

        return self::SUCCESS;
    }
}
