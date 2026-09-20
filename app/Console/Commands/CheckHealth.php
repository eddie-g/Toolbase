<?php

namespace App\Console\Commands;

use App\Observability\HealthChecks;
use Illuminate\Console\Command;

class CheckHealth extends Command
{
    protected $signature = 'app:health {--json : Print the report as JSON}';

    protected $description = 'Check MySQL, Redis, Horizon, Python and storage; exits 1 when one is down';

    public function handle(HealthChecks $checks): int
    {
        $report = $checks->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report['checks'] as $name => $check) {
            $line = sprintf('%-10s %s  %d ms%s', $name, $check['ok'] ? 'ok  ' : 'FAIL', $check['ms'], isset($check['message']) ? '  '.$check['message'] : '');
            $check['ok'] ? $this->info($line) : $this->error($line);
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
