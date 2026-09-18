<?php

namespace App\Console\Commands;

use App\Support\ProductionConfig;
use Illuminate\Console\Command;

class CheckProductionConfig extends Command
{
    protected $signature = 'app:check-config';

    protected $description = 'Check the settings and secrets production needs; exits 1 and lists what to fix';

    public function handle(): int
    {
        $problems = ProductionConfig::problems();
        if ($problems === []) {
            $this->info('Production configuration is valid ('.(app()->configurationIsCached() ? 'cached' : 'not cached').').');

            return self::SUCCESS;
        }

        $this->error('Production configuration is not valid:');
        foreach ($problems as $problem) {
            $this->line(' - '.$problem);
        }

        return self::FAILURE;
    }
}
