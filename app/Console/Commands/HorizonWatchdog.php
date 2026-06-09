<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class HorizonWatchdog extends Command
{
    protected $signature = 'ops:horizon-watchdog
        {--force-recover : Skip the strike grace period and recover immediately if unhealthy}';

    protected $description = 'Detect a dead/zombie Horizon master and automatically restart it, alerting on failure.';

    /**
     * How many consecutive unhealthy checks before we force a recovery. This
     * grace period avoids killing a perfectly healthy Horizon during the brief
     * window when it is legitimately restarting (e.g. after a deploy).
     */
    private const STRIKE_THRESHOLD = 2;

    private const STRIKE_KEY = 'ops:horizon-watchdog:strikes';

    public function handle(MasterSupervisorRepository $masters): int
    {
        // MasterSupervisorRepository::all() only returns masters whose heartbeat
        // pinged Redis within the last ~14 seconds. A zombie master that is still
        // alive as a process but has stopped pinging therefore drops out here —
        // exactly the failure mode supervisord cannot detect on its own.
        $active = $masters->all();

        if (! empty($active)) {
            // Healthy (running or intentionally paused). Clear any prior strikes.
            if ((int) Cache::get(self::STRIKE_KEY, 0) > 0) {
                Cache::forget(self::STRIKE_KEY);
            }

            $this->info('Horizon is healthy ('.count($active).' master(s) heartbeating).');

            return self::SUCCESS;
        }

        $strikes = (int) Cache::get(self::STRIKE_KEY, 0) + 1;
        Cache::put(self::STRIKE_KEY, $strikes, now()->addMinutes(10));

        Log::critical('[HorizonWatchdog] No active Horizon master heartbeat detected.', [
            'strikes' => $strikes,
            'threshold' => self::STRIKE_THRESHOLD,
            'host' => gethostname(),
        ]);

        $this->error("Horizon appears DOWN (strike {$strikes}/".self::STRIKE_THRESHOLD.').');

        if (! $this->option('force-recover') && $strikes < self::STRIKE_THRESHOLD) {
            // Could be a transient restart window — wait for the next tick.
            return self::SUCCESS;
        }

        $this->notifyOperator($strikes);
        $this->recover();
        Cache::forget(self::STRIKE_KEY);

        return self::SUCCESS;
    }

    /**
     * Notify the operator that Horizon went down and is being recovered.
     */
    private function notifyOperator(int $strikes): void
    {
        Log::critical('[HorizonWatchdog] Horizon is down — attempting automatic recovery.', [
            'strikes' => $strikes,
            'host' => gethostname(),
        ]);

        $email = env('HORIZON_ALERT_EMAIL');
        if (! $email) {
            return;
        }

        try {
            Mail::raw(
                "Horizon had no active master heartbeat after {$strikes} consecutive checks on "
                    .gethostname().". The watchdog is restarting it automatically.",
                fn ($message) => $message->to($email)
                    ->subject('[NETKIT] Horizon worker down — auto-recovering')
            );
        } catch (\Throwable $e) {
            Log::warning('[HorizonWatchdog] Failed to send alert email.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Restart Horizon. We first signal a graceful terminate, then force-kill any
     * lingering master/supervisor/worker processes so the container's process
     * supervisor (supervisord) relaunches a fresh master. A zombie master that
     * stopped pinging Redis will not respond to horizon:terminate on its own.
     */
    private function recover(): void
    {
        try {
            Artisan::call('horizon:terminate');
        } catch (\Throwable $e) {
            Log::warning('[HorizonWatchdog] horizon:terminate failed.', [
                'error' => $e->getMessage(),
            ]);
        }

        // The pattern matches the master ("artisan horizon"), supervisors
        // ("artisan horizon:supervisor ...") and workers ("artisan horizon:work ...").
        // The "[a]rtisan" bracket idiom is deliberate: pkill spawns a shell whose
        // own command line contains this pattern, so a literal "artisan horizon"
        // would make pkill SIGTERM its own wrapper shell. "[a]rtisan horizon"
        // matches the real Horizon processes but NOT the pattern string itself,
        // and never matches this watchdog ("artisan ops:horizon-watchdog") or the
        // scheduler ("artisan schedule:work").
        try {
            $result = Process::run('pkill -f "[a]rtisan horizon"');

            Log::critical('[HorizonWatchdog] Recovery executed: lingering Horizon processes killed; supervisord will relaunch a fresh master.', [
                'pkill_exit_code' => $result->exitCode(),
            ]);
        } catch (\Throwable $e) {
            Log::critical('[HorizonWatchdog] Recovery kill step raised an exception (continuing; supervisord should still relaunch Horizon).', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->warn('Recovery executed. supervisord will relaunch Horizon.');
    }
}
