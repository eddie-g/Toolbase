<?php

namespace App\Services;

use App\Exceptions\PythonServiceBusyException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The one way the app runs Python (and the odd pdfinfo). Every process gets
 * a hard timeout matched to its script, an optional idle timeout and memory
 * cap, and has to hold one of a fixed number of slots, so a handful of slow
 * PDFs cannot pin every PHP worker. exec()/shellExec() keep the signatures
 * of the PHP functions they replaced so call sites stay readable.
 *
 * Bound as a singleton; the interpreter lookup is memoised per process.
 */
class PythonRunner
{
    public const TIMEOUT_EXIT_CODE = 124;

    /** @var array<string, string> */
    private array $interpreters = [];

    /**
     * The interpreter that can import the given modules, in this order:
     * PYTHON_BINARY, the project virtualenvs, the system python.
     *
     * @param string|string[]|null $requiredModules
     */
    public function interpreter(string|array|null $requiredModules = null): string
    {
        $modules = array_values(array_filter(
            is_array($requiredModules) ? $requiredModules : [$requiredModules],
            static fn ($module) => is_string($module) && preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $module)
        ));
        $key = implode('|', $modules);
        if (isset($this->interpreters[$key])) {
            return $this->interpreters[$key];
        }

        $candidates = array_values(array_unique(array_filter([
            config('python.binary'),
            base_path('.venv/bin/python'),
            base_path('venv/bin/python'),
            base_path('.venv/Scripts/python.exe'),
            base_path('venv/Scripts/python.exe'),
            base_path('python/venv/bin/python'),
            base_path('python/venv/Scripts/python.exe'),
            '/usr/bin/python3',
            'python3',
        ])));

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/') && ! is_executable($candidate)) {
                continue;
            }
            if ($modules === []) {
                return $this->interpreters[$key] = $candidate;
            }

            $probe = new Process([$candidate, '-c', implode('; ', array_map(static fn ($m) => "import {$m}", $modules))]);
            $probe->setTimeout(15);
            try {
                $probe->run();
            } catch (ProcessTimedOutException) {
                continue;
            }
            if ($probe->isSuccessful()) {
                return $this->interpreters[$key] = $candidate;
            }
        }

        return $this->interpreters[$key] = 'python3';
    }

    /**
     * Run an argv-style command. Options: timeout, idle_timeout, cwd, env,
     * input, memory_limit_mb, slot (false skips the concurrency cap), label.
     *
     * @param string[] $argv
     */
    public function run(array $argv, array $options = []): ProcessResult
    {
        return $this->shell(implode(' ', array_map('escapeshellarg', $argv)), $options);
    }

    /** Run a shell command line (as the exec()-era call sites build them). */
    public function shell(string $command, array $options = []): ProcessResult
    {
        $timeout = (int) ($options['timeout'] ?? $this->timeoutFor($command));
        $lock = ($options['slot'] ?? true) ? $this->acquireSlot($timeout) : null;

        $commandLine = $command;
        $memoryMb = (int) ($options['memory_limit_mb'] ?? config('python.memory_limit_mb', 0));
        if ($memoryMb > 0 && PHP_OS_FAMILY === 'Linux') {
            $commandLine = sprintf('ulimit -v %d 2>/dev/null; %s', $memoryMb * 1024, $command);
        }

        $process = Process::fromShellCommandline($commandLine, $options['cwd'] ?? null, $options['env'] ?? null, $options['input'] ?? null, $timeout);
        $idle = (int) ($options['idle_timeout'] ?? config('python.idle_timeout', 0));
        if ($idle > 0) {
            $process->setIdleTimeout($idle);
        }

        $started = microtime(true);
        $timedOut = false;
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            $timedOut = true;
        } finally {
            $lock?->release();
        }
        $seconds = microtime(true) - $started;

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $output = $stdout;
        if ($timedOut) {
            $output .= ($output === '' ? '' : "\n").sprintf('[python-runner] timed out after %ds', $timeout);
        }
        // exec()-era commands end in 2>&1, so stderr already sits in stdout;
        // commands without it get stderr appended so callers still see it.
        if (! $timedOut && $stderr !== '' && ! str_contains($command, '2>&1')) {
            $output .= ($output === '' ? '' : "\n").$stderr;
        }

        $result = new ProcessResult(
            exitCode: $timedOut ? self::TIMEOUT_EXIT_CODE : (int) ($process->getExitCode() ?? 1),
            output: $output,
            stdout: $stdout,
            stderr: $stderr,
            timedOut: $timedOut,
            seconds: $seconds,
            command: $command,
        );

        if (! $result->ok()) {
            Log::warning('Python process failed', [
                'label' => $options['label'] ?? $this->scriptName($command),
                'exit_code' => $result->exitCode,
                'timed_out' => $timedOut,
                'seconds' => round($seconds, 2),
                'stderr' => $result->stderrTail(),
            ]);
        }

        return $result;
    }

    /**
     * Drop-in for PHP's exec(): fills $output with the lines, sets
     * $resultCode, returns the last line (false when there was none).
     */
    public function exec(string $command, ?array &$output = null, ?int &$resultCode = null, array $options = []): string|false
    {
        $result = $this->shell($command, $options);
        $lines = $result->lines();
        $output = array_merge(is_array($output) ? $output : [], $lines);
        $resultCode = $result->exitCode;

        return $lines === [] ? false : (string) end($lines);
    }

    /** Drop-in for PHP's shell_exec(): the combined output, or null when empty. */
    public function shellExec(string $command, array $options = []): ?string
    {
        $result = $this->shell($command, $options);

        return $result->output === '' ? null : $result->output;
    }

    /** The configured timeout for the script named in a command line. */
    public function timeoutFor(string $command): int
    {
        $timeouts = (array) config('python.timeouts', []);
        foreach ($timeouts as $needle => $seconds) {
            if ($needle !== 'default' && str_contains($command, (string) $needle)) {
                return max(1, (int) $seconds);
            }
        }

        return max(1, (int) ($timeouts['default'] ?? 120));
    }

    /**
     * One of max_concurrent slots, or PythonServiceBusyException. Slots are
     * cache locks that expire on their own, so a crashed worker frees its
     * slot without help.
     */
    private function acquireSlot(int $timeout): ?Lock
    {
        $max = max(1, (int) config('python.max_concurrent', 4));
        $wait = max(0.0, (float) config('python.slot_wait_seconds', 8));
        $store = Cache::store(config('python.lock_store') ?: null);
        $deadline = microtime(true) + $wait;

        do {
            for ($slot = 0; $slot < $max; $slot++) {
                $lock = $store->lock("python-runner:slot:{$slot}", $timeout + 60);
                if ($lock->get()) {
                    return $lock;
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(100_000);
        } while (true);

        Log::warning('Python runner busy', ['max_concurrent' => $max, 'waited_seconds' => $wait]);

        throw new PythonServiceBusyException();
    }

    private function scriptName(string $command): string
    {
        return preg_match('/([A-Za-z0-9_\-]+\.py)\b/', $command, $m) ? $m[1] : mb_strimwidth($command, 0, 60, '…');
    }
}
