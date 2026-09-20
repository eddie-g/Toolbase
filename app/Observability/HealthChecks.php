<?php

namespace App\Observability;

use App\Services\PythonRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * What the app needs to be of any use: MySQL, Redis, a running Horizon, a
 * Python that can import PyMuPDF, a disk it can write to, and (when backups
 * are on) a backup from the last day. /up only proves
 * PHP boots; this is for monitoring (GET /health/deep, `artisan app:health`).
 */
class HealthChecks
{
    public function __construct(private PythonRunner $python) {}

    /**
     * @return array{ok: bool, release: string|null, checks: array<string, array{ok: bool, ms: int, message?: string}>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->measure(fn () => $this->database()),
            'redis' => $this->measure(fn () => $this->redis()),
            'horizon' => $this->measure(fn () => $this->horizon()),
            'python' => $this->measure(fn () => $this->pythonImports()),
            'storage' => $this->measure(fn () => $this->storage()),
            'backups' => $this->measure(fn () => app(\App\Services\DatabaseBackups::class)->staleness()),
        ];

        return [
            'ok' => ! in_array(false, array_column($checks, 'ok'), true),
            'release' => config('observability.release'),
            'checks' => $checks,
        ];
    }

    /** @return array{ok: bool, ms: int, message?: string} */
    private function measure(callable $check): array
    {
        $started = microtime(true);
        try {
            $problem = $check();
        } catch (\Throwable $e) {
            // The class says what kind of failure; the message can hold a DSN.
            $problem = class_basename($e);
            report($e);
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        $limit = (int) config('observability.health.timeout_seconds', 5) * 1000;
        if ($problem === null && $ms > $limit) {
            $problem = "answered, but took longer than {$limit} ms";
        }

        return $problem === null ? ['ok' => true, 'ms' => $ms] : ['ok' => false, 'ms' => $ms, 'message' => $problem];
    }

    private function database(): ?string
    {
        return DB::connection()->selectOne('select 1 as ok')?->ok == 1 ? null : 'unexpected answer';
    }

    private function redis(): ?string
    {
        // Sessions, cache, queues and rate limits each have their own connection.
        foreach (array_keys(array_filter((array) config('database.redis'), 'is_array')) as $connection) {
            if (in_array($connection, ['options', 'clusters'], true)) {
                continue;
            }
            $key = 'health:'.Str::random(8);
            $redis = Redis::connection($connection);
            $redis->setex($key, 10, '1');
            if ((string) $redis->get($key) !== '1') {
                return "connection {$connection} did not return what was written";
            }
            $redis->del($key);
        }

        return null;
    }

    private function horizon(): ?string
    {
        $masters = app(MasterSupervisorRepository::class)->all();
        if ($masters === []) {
            return 'no Horizon process is running';
        }
        foreach ($masters as $master) {
            if (($master->status ?? null) === 'paused') {
                return 'Horizon is paused';
            }
        }

        return null;
    }

    private function pythonImports(): ?string
    {
        $seconds = (int) config('observability.health.python_cache_seconds', 60);

        // Only a success is remembered: a failure is checked again at once.
        if (Cache::get('health:python') === 'ok') {
            return null;
        }
        $result = $this->python->run(
            [$this->python->interpreter('fitz'), '-c', 'import fitz; print(fitz.__doc__ is not None)'],
            // No slot: a probe must not fail, or wait, because the editor is busy.
            ['timeout' => (int) config('observability.health.timeout_seconds', 5), 'slot' => false],
        );
        if (! $result->ok()) {
            return 'python could not import PyMuPDF';
        }
        Cache::put('health:python', 'ok', $seconds);

        return null;
    }

    private function storage(): ?string
    {
        $path = 'health/'.Str::random(12).'.txt';
        $disk = Storage::disk(config('filesystems.default'));
        try {
            $disk->put($path, 'ok');

            return $disk->get($path) === 'ok' ? null : 'a file written to storage could not be read back';
        } finally {
            $disk->delete($path);
        }
    }
}
