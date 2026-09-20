<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dumps of the MySQL database on a disk (config/backup.php), and the drill
 * that proves the newest one restores. The password never appears on a
 * command line: the client tools read it from a 0600 options file.
 */
class DatabaseBackups
{
    /** @return array{file: string, manifest: array<string, mixed>} */
    public function create(): array
    {
        $connection = $this->connection();
        $name = 'db-'.now()->utc()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $workDir = $this->workDir();
        $dumpPath = "{$workDir}/{$name}.sql.gz";
        $options = $this->writeClientOptions($workDir, $connection);

        try {
            // Counted first: rows are only added while the dump runs, so the
            // restored copy should hold at least roughly these.
            $counts = $this->counts(DB::connection(), (array) config('backup.verify_tables'));
            $tables = $this->tables(DB::connection(), $connection['database']);

            $gtid = $this->supports('mysqldump', '--set-gtid-purged') ? '--set-gtid-purged=OFF ' : '';
            $this->shell(sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces %s%s | gzip -6 > %s',
                escapeshellarg((string) config('backup.binaries.mysqldump')),
                escapeshellarg($options),
                $gtid,
                escapeshellarg($connection['database']),
                escapeshellarg($dumpPath),
            ));

            $size = (int) filesize($dumpPath);
            if ($size < 200) {
                throw new RuntimeException('The dump is empty.');
            }

            $manifest = [
                'file' => "{$name}.sql.gz",
                'created_at' => now()->utc()->toIso8601String(),
                'database' => $connection['database'],
                'release' => config('observability.release'),
                'size_bytes' => $size,
                'sha256' => hash_file('sha256', $dumpPath),
                'tables' => $tables,
                'row_counts' => $counts,
            ];

            $stream = fopen($dumpPath, 'rb');
            try {
                $this->disk()->writeStream($this->path("{$name}.sql.gz"), $stream);
            } finally {
                is_resource($stream) && fclose($stream);
            }
            // The manifest goes last: a dump without one is an upload that did not finish.
            $this->disk()->put($this->path("{$name}.json"), (string) json_encode($manifest, JSON_PRETTY_PRINT));

            return ['file' => $this->path("{$name}.sql.gz"), 'manifest' => $manifest];
        } finally {
            File::delete([$dumpPath, $options]);
        }
    }

    /** @return string[] the files removed */
    public function prune(): array
    {
        $backups = $this->all();
        $cutoff = now()->utc()->subDays(max(1, (int) config('backup.keep_days')));
        $removed = [];

        foreach (array_slice($backups, max(0, (int) config('backup.keep_minimum'))) as $backup) {
            if ($backup['created_at']->lessThan($cutoff)) {
                $this->disk()->delete([$this->path($backup['manifest']['file']), $backup['manifest_path']]);
                $removed[] = $backup['manifest']['file'];
            }
        }

        return $removed;
    }

    /**
     * Completed backups, newest first.
     *
     * @return array<int, array{manifest: array<string, mixed>, manifest_path: string, created_at: CarbonImmutable}>
     */
    public function all(): array
    {
        $backups = [];
        foreach ($this->disk()->files((string) config('backup.path')) as $path) {
            if (! str_ends_with($path, '.json')) {
                continue;
            }
            $manifest = json_decode((string) $this->disk()->get($path), true);
            if (is_array($manifest) && isset($manifest['file'], $manifest['created_at']) && $this->disk()->exists($this->path($manifest['file']))) {
                $backups[] = ['manifest' => $manifest, 'manifest_path' => $path, 'created_at' => CarbonImmutable::parse($manifest['created_at'])];
            }
        }
        usort($backups, static fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }

    /**
     * Restores the newest backup into the scratch database, compares it with
     * its manifest, and drops the scratch database again.
     *
     * @return array{file: string, seconds: float, tables: int, row_counts: array<string, array{backup: int, restored: int}>, problems: string[]}
     */
    public function drill(): array
    {
        $newest = $this->all()[0] ?? null;
        if ($newest === null) {
            throw new RuntimeException('There is no backup to restore.');
        }
        $manifest = $newest['manifest'];
        $connection = $this->connection();
        $scratch = (string) (config('backup.drill_database') ?: $connection['database'].'_restore_drill');
        if (! str_ends_with($scratch, '_restore_drill') || $scratch === $connection['database'] || preg_match('/^[A-Za-z0-9_]+$/', $scratch) !== 1) {
            throw new RuntimeException('BACKUP_DRILL_DATABASE must end in "_restore_drill": the drill drops that database.');
        }

        $workDir = $this->workDir();
        $dumpPath = $workDir.'/'.basename((string) $manifest['file']);
        $options = $this->writeClientOptions($workDir, $connection);
        $started = microtime(true);

        try {
            $stream = $this->disk()->readStream($this->path($manifest['file']));
            file_put_contents($dumpPath, $stream);
            is_resource($stream) && fclose($stream);

            $problems = [];
            if (hash_file('sha256', $dumpPath) !== ($manifest['sha256'] ?? null)) {
                $problems[] = 'The file does not match the checksum in its manifest.';
            }

            DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
            DB::statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->shell(sprintf(
                'gunzip -c %s | %s --defaults-extra-file=%s %s',
                escapeshellarg($dumpPath),
                escapeshellarg((string) config('backup.binaries.mysql')),
                escapeshellarg($options),
                escapeshellarg($scratch),
            ));

            config(['database.connections.restore_drill' => array_merge((array) config('database.connections.'.config('database.default')), ['database' => $scratch])]);
            DB::purge('restore_drill');
            $restored = DB::connection('restore_drill');

            $missing = array_values(array_diff((array) ($manifest['tables'] ?? []), $this->tables($restored, $scratch)));
            if ($missing !== []) {
                $problems[] = 'Tables missing after the restore: '.implode(', ', $missing);
            }

            $rowCounts = [];
            $verify = array_values(array_diff(array_keys((array) ($manifest['row_counts'] ?? [])), $missing));
            foreach ($this->counts($restored, $verify) as $table => $count) {
                $expected = (int) $manifest['row_counts'][$table];
                $rowCounts[$table] = ['backup' => $expected, 'restored' => $count];
                // Rows written or removed while the dump ran explain a small difference, not a large one.
                if (($expected > 0 && $count === 0) || abs($count - $expected) > max(50, (int) ceil($expected * 0.1))) {
                    $problems[] = "{$table}: {$expected} rows when the backup was taken, {$count} after the restore.";
                }
            }

            return [
                'file' => (string) $manifest['file'],
                'seconds' => round(microtime(true) - $started, 1),
                'tables' => count((array) ($manifest['tables'] ?? [])) - count($missing),
                'row_counts' => $rowCounts,
                'problems' => $problems,
            ];
        } finally {
            DB::purge('restore_drill');
            DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
            File::delete([$dumpPath, $options]);
        }
    }

    /** Null when backups are on and recent enough (or off); otherwise what is wrong. */
    public function staleness(): ?string
    {
        if (! config('backup.enabled')) {
            return null;
        }
        $newest = $this->all()[0] ?? null;
        if ($newest === null) {
            return 'backups are on but there is none yet';
        }
        $hours = (int) $newest['created_at']->diffInHours(now()->utc());

        return $hours > (int) config('backup.max_age_hours') ? "the newest backup is {$hours} hours old" : null;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('backup.disk'));
    }

    private function path(string $file): string
    {
        return trim((string) config('backup.path'), '/').'/'.basename($file);
    }

    /** @return array{host: string, port: string, database: string, username: string, password: string, ssl_ca: ?string} */
    private function connection(): array
    {
        $config = (array) config('database.connections.'.config('database.default'));
        if (($config['driver'] ?? null) !== 'mysql' && ($config['driver'] ?? null) !== 'mariadb') {
            throw new RuntimeException('Backups are for MySQL; the default connection is '.($config['driver'] ?? 'unknown').'.');
        }

        return [
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (string) ($config['port'] ?? '3306'),
            'database' => (string) $config['database'],
            'username' => (string) $config['username'],
            'password' => (string) ($config['password'] ?? ''),
            'ssl_ca' => ($config['options'][\PDO::MYSQL_ATTR_SSL_CA] ?? null) ?: null,
        ];
    }

    private function workDir(): string
    {
        // The container's own temporary space: never the shared documents volume.
        $dir = rtrim(sys_get_temp_dir(), '/').'/netkit-backups';
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    private function writeClientOptions(string $dir, array $connection): string
    {
        $quote = static fn (string $value) => '"'.addcslashes($value, "\\\"").'"';
        $lines = ['[client]', 'host='.$quote($connection['host']), 'port='.$connection['port'], 'user='.$quote($connection['username']), 'password='.$quote($connection['password'])];
        if ($connection['ssl_ca']) {
            $lines[] = 'ssl-ca='.$quote($connection['ssl_ca']);
        }
        $path = $dir.'/client-'.Str::random(12).'.cnf';
        touch($path);
        chmod($path, 0600);
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    private function supports(string $binary, string $option): bool
    {
        $help = new Process([(string) config("backup.binaries.{$binary}"), '--help']);
        $help->run();

        return str_contains($help->getOutput(), ltrim($option, '-'));
    }

    private function shell(string $command): void
    {
        $process = Process::fromShellCommandline('bash -c '.escapeshellarg('set -o pipefail; '.$command), null, null, null, (int) config('backup.timeout_seconds'));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('The database client failed: '.Str::limit(trim($process->getErrorOutput()), 400));
        }
    }

    /** @return string[] */
    private function tables(\Illuminate\Database\Connection $connection, string $database): array
    {
        return array_map(
            static fn ($row) => (string) $row->name,
            $connection->select('select table_name as name from information_schema.tables where table_schema = ? and table_type = ? order by table_name', [$database, 'BASE TABLE'])
        );
    }

    /** @return array<string, int> */
    private function counts(\Illuminate\Database\Connection $connection, array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            if ($connection->getSchemaBuilder()->hasTable($table)) {
                $counts[$table] = (int) $connection->table($table)->count();
            }
        }

        return $counts;
    }
}
