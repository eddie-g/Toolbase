<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BenchmarkPasswordHash extends Command
{
    protected $signature = 'auth:benchmark-hash {--target=100 : Milliseconds a single password check may take} {--samples=5}';

    protected $description = 'Time bcrypt at each cost on this CPU and say which BCRYPT_ROUNDS keeps a login under the target';

    public function handle(): int
    {
        $target = max(1, (int) $this->option('target'));
        $samples = max(1, (int) $this->option('samples'));
        $current = (int) config('hashing.bcrypt.rounds', 12);
        $rows = [];
        $best = null;

        foreach (range(8, 14) as $rounds) {
            $hash = password_hash('a representative passphrase', PASSWORD_BCRYPT, ['cost' => $rounds]);
            $timings = [];
            for ($i = 0; $i < $samples; $i++) {
                $started = hrtime(true);
                password_verify('a representative passphrase', $hash);
                $timings[] = (hrtime(true) - $started) / 1e6;
            }
            sort($timings);
            $median = $timings[intdiv(count($timings), 2)];
            if ($median <= $target) {
                $best = $rounds;
            }
            $rows[] = [$rounds.($rounds === $current ? '  (configured)' : ''), number_format($median, 1).' ms', number_format(1000 / $median, 1), $median <= $target ? 'yes' : 'no'];
        }

        $this->table(['BCRYPT_ROUNDS', 'one check (median)', 'logins per core-second', "under {$target} ms"], $rows);
        if ($best === null) {
            $this->warn("No cost from 8 up stays under {$target} ms on this CPU.");

            return self::FAILURE;
        }
        $this->info("Highest cost under {$target} ms here: {$best}. OWASP's floor for bcrypt is 10.");
        if ($best !== $current) {
            $this->line("Configured is {$current}. Changing BCRYPT_ROUNDS is safe at any time: passwords are rehashed at the next sign-in.");
        }
        $this->line('A login holds a php-fpm worker for about this long, so workers x logins-per-core-second bounds sign-ins per second.');

        return self::SUCCESS;
    }
}
