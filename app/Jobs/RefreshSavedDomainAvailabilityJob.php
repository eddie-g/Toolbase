<?php

namespace App\Jobs;

use App\Models\SavedDomain;
use App\Services\NamecheapClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RefreshSavedDomainAvailabilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    /**
     * @param int|null $userId  Null = refresh ALL users' saved domains
     */
    public function __construct(public readonly ?int $userId = null)
    {
        $this->onQueue('default');
    }

    public function handle(NamecheapClient $namecheap): void
    {
        $query = SavedDomain::query();

        if ($this->userId !== null) {
            $query->where('user_id', $this->userId);
        }

        $domains = $query->pluck('domain')->all();

        if (empty($domains)) {
            return;
        }

        // Force-bust the Namecheap cache so we get fresh results
        foreach ($domains as $domain) {
            Cache::forget("nc-domain:{$domain}");
        }

        $useNamecheap = config('services.domain_lookup') === 'namecheap';

        if ($useNamecheap) {
            $this->refreshViaNamecheap($namecheap, $domains);
        } else {
            $this->refreshViaWhois($domains);
        }
    }

    private function refreshViaNamecheap(NamecheapClient $namecheap, array $domains): void
    {
        $response = $namecheap->checkFqdns($domains);

        if (!empty($response['error'])) {
            Log::warning('RefreshSavedDomainAvailabilityJob: Namecheap error', [
                'error' => $response['error'],
            ]);
        }

        $byDomain = collect($response['results'])->keyBy('domain');

        foreach ($domains as $fqdn) {
            $result = $byDomain->get($fqdn);
            if (!$result) continue;

            SavedDomain::where('domain', $fqdn)
                ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
                ->update([
                    'is_available'  => $result['available'] ?? null,
                    'is_premium'    => $result['premium'] ?? false,
                    'premium_price' => $result['premium_price'] ?? null,
                    'checked_at'    => now(),
                ]);
        }
    }

    private function refreshViaWhois(array $domains): void
    {
        $scriptPath = base_path('python/domain-search/check_domain_availability.py');
        $uniqueNames = [];
        $uniqueTlds  = [];

        foreach ($domains as $fqdn) {
            $parts      = explode('.', $fqdn, 2);
            $uniqueNames[$parts[0]] = true;
            $uniqueTlds[$parts[1] ?? ''] = true;
        }

        $names = array_keys(array_filter($uniqueNames));
        $tlds  = array_keys(array_filter($uniqueTlds));

        if (empty($names) || empty($tlds)) {
            return;
        }

        $args   = ['python3', $scriptPath, '-t', ...$tlds, '--skip-http-check', '--', ...$names];
        $result = Process::timeout(90)->run($args);

        if (!$result->successful()) {
            Log::warning('RefreshSavedDomainAvailabilityJob: WHOIS check failed', [
                'error' => $result->errorOutput(),
            ]);
            return;
        }

        $parsed = json_decode($result->output(), true);
        if (!is_array($parsed)) return;

        $byDomain = collect($parsed)->keyBy(fn ($r) => strtolower($r['domain'] ?? ''));

        foreach ($domains as $fqdn) {
            $result = $byDomain->get($fqdn);
            if (!$result) continue;

            SavedDomain::where('domain', $fqdn)
                ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
                ->update([
                    'is_available' => $result['available'] ?? null,
                    'is_premium'   => false,
                    'checked_at'   => now(),
                ]);
        }
    }
}
