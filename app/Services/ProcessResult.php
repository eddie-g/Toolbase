<?php

namespace App\Services;

/**
 * What a PythonRunner call produced. `output` is stdout and stderr in the
 * order the process wrote them (the callers historically ran with 2>&1).
 */
final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
        public readonly float $seconds,
        public readonly string $command,
    ) {
    }

    public function ok(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    /** Output split the way exec() fills its $output array. */
    public function lines(): array
    {
        $text = rtrim($this->output, "\r\n");

        return $text === '' ? [] : explode("\n", str_replace("\r\n", "\n", $text));
    }

    /** The last 2 KB of stderr, for logs; never the whole stream. */
    public function stderrTail(int $bytes = 2048): string
    {
        return mb_strlen($this->stderr) > $bytes ? '…'.mb_substr($this->stderr, -$bytes) : $this->stderr;
    }
}
