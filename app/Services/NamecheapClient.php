<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Namecheap Domain Availability Checker.
 *
 * Uses the namecheap.domains.check API command to batch-check up to 50 domains
 * per request. All TLDs for a single name are sent as one comma-separated
 * DomainList, so checking "flash" across 20 TLDs = 1 HTTP call.
 *
 * @see https://www.namecheap.com/support/api/methods/domains/check/
 */
class NamecheapClient
{
    /** Namecheap enforces max 50 domains per check request */
    private const MAX_DOMAINS_PER_REQUEST = 50;

    /** Cache TTL for domain availability results */
    private const CACHE_TTL_MINUTES = 30;

    /** Top 20 popular TLDs used when no TLDs are specified */
    public const POPULAR_TLDS = [
        'com', 'net', 'org', 'io', 'co', 'ai', 'app', 'dev',
        'xyz', 'tech', 'me', 'info', 'biz', 'us', 'online',
        'site', 'store', 'cloud', 'design', 'pro',
    ];

    private string $apiUser;
    private string $apiKey;
    private string $userName;
    private string $clientIp;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiUser  = config('services.namecheap.api_user');
        $this->apiKey   = config('services.namecheap.api_key');
        $this->userName = config('services.namecheap.username') ?: $this->apiUser;
        $this->clientIp = config('services.namecheap.client_ip', '127.0.0.1');
        $this->baseUrl  = config('services.namecheap.sandbox', false)
            ? 'https://api.sandbox.namecheap.com/xml.response'
            : 'https://api.namecheap.com/xml.response';
    }

    /**
     * Check availability for one or more base names across the given TLDs.
     *
     * Batches all combinations into chunks of 50 and fires the minimum number
     * of API requests. Results are cached per-domain for 1 hour.
     *
     * @param  string[]  $names  Base domain labels (e.g. ['flash', 'bolt'])
     * @param  string[]  $tlds   TLD list without dots (e.g. ['com', 'net'])
     * @return array{results: array, error: string|null}
     */
    public function checkAvailability(array $names, array $tlds = []): array
    {
        if (empty($tlds)) {
            $tlds = self::POPULAR_TLDS;
        }

        // Build the full list of FQDNs to check
        $allDomains = [];
        foreach ($names as $name) {
            $name = $this->sanitizeName($name);
            if ($name === '') continue;
            foreach ($tlds as $tld) {
                $tld = ltrim(strtolower(trim($tld)), '.');
                if ($tld === '') continue;
                $allDomains[] = "{$name}.{$tld}";
            }
        }

        if (empty($allDomains)) {
            return ['results' => [], 'error' => 'No valid domain names to check.'];
        }

        // Split into cached vs uncached
        $results = [];
        $uncached = [];

        foreach ($allDomains as $domain) {
            $cached = Cache::get("nc-domain:{$domain}");
            if ($cached !== null) {
                $results[] = $cached;
            } else {
                $uncached[] = $domain;
            }
        }

        if (empty($uncached)) {
            return ['results' => $results, 'error' => null];
        }

        // Batch uncached domains into chunks of 50 (Namecheap limit)
        $chunks = array_chunk($uncached, self::MAX_DOMAINS_PER_REQUEST);

        foreach ($chunks as $chunk) {
            try {
                $chunkResults = $this->apiCheck($chunk);
                foreach ($chunkResults as $result) {
                    Cache::put(
                        "nc-domain:{$result['domain']}",
                        $result,
                        now()->addMinutes(self::CACHE_TTL_MINUTES)
                    );
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                $errMsg = $e->getMessage();
                Log::warning('Namecheap batch error — retrying individually', [
                    'message' => $errMsg,
                    'chunk_size' => count($chunk),
                ]);

                // Retry each domain individually so one bad TLD doesn't kill the whole chunk
                foreach ($chunk as $domain) {
                    try {
                        $single = $this->apiCheck([$domain]);
                        foreach ($single as $result) {
                            Cache::put(
                                "nc-domain:{$result['domain']}",
                                $result,
                                now()->addMinutes(self::CACHE_TTL_MINUTES)
                            );
                            $results[] = $result;
                        }
                    } catch (\Throwable $e2) {
                        // Namecheap can't check this TLD — try GoDaddy as a fallback
                        Log::info('Namecheap unsupported TLD, trying GoDaddy', ['domain' => $domain, 'error' => $e2->getMessage()]);
                        $gdClient = app(GoDaddyClient::class);
                        if ($gdClient->isConfigured()) {
                            $gdResults = $gdClient->checkFqdns([$domain]);
                            foreach ($gdResults['results'] as $result) {
                                // Store under the Namecheap cache key so future Namecheap calls hit cache too
                                Cache::put("nc-domain:{$result['domain']}", $result, now()->addMinutes(self::CACHE_TTL_MINUTES));
                                $results[] = $result;
                            }
                        } else {
                            $tldPart    = str_contains($domain, '.') ? '.' . substr($domain, strpos($domain, '.') + 1) : '';
                            $placeholder = [
                                'domain'        => $domain,
                                'available'     => false,
                                'taken'         => false,
                                'for_sale'      => false,
                                'premium'       => false,
                                'premium_price' => null,
                                'tld'           => $tldPart,
                                'error'         => 'Unsupported TLD',
                            ];
                            Cache::put("nc-domain:{$domain}", $placeholder, now()->addMinutes(self::CACHE_TTL_MINUTES));
                            $results[] = $placeholder;
                        }
                    }
                }
            }
        }

        return [
            'results' => $results,
            'error'   => null,
        ];
    }

    /**
     * Fire one Namecheap domains.check API call for up to 50 domains.
     *
     * @param  string[]  $domains  FQDNs like ['flash.com', 'flash.net']
     * @return array  Parsed results
     */
    private function apiCheck(array $domains): array
    {
        $response = Http::timeout(15)
            ->get($this->baseUrl, [
                'ApiUser'    => $this->apiUser,
                'ApiKey'     => $this->apiKey,
                'UserName'   => $this->userName,
                'Command'    => 'namecheap.domains.check',
                'ClientIp'   => $this->clientIp,
                'DomainList' => implode(',', $domains),
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Namecheap API returned HTTP {$response->status()}"
            );
        }

        return $this->parseXmlResponse($response->body());
    }

    /**
     * Parse the Namecheap XML response into a normalized results array.
     */
    private function parseXmlResponse(string $xml): array
    {
        // Suppress XML warnings for malformed responses
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            throw new \RuntimeException('Failed to parse Namecheap XML response');
        }

        // Check for API-level errors
        $status = (string) ($doc['Status'] ?? '');
        if (strtoupper($status) === 'ERROR') {
            $errorMsg = 'Namecheap API error';
            foreach ($doc->Errors->Error ?? [] as $error) {
                $errorMsg = (string) $error;
                break;
            }
            throw new \RuntimeException($errorMsg);
        }

        $results = [];

        foreach ($doc->CommandResponse->DomainCheckResult ?? [] as $node) {
            $domain  = strtolower((string) ($node['Domain'] ?? ''));
            $available = strtolower((string) ($node['Available'] ?? '')) === 'true';
            $isPremium = strtolower((string) ($node['IsPremiumName'] ?? '')) === 'true';
            $premiumPrice = (float) ($node['PremiumRegistrationPrice'] ?? 0);

            if ($domain === '') continue;

            $tld = '.' . (str_contains($domain, '.') ? substr($domain, strpos($domain, '.') + 1) : '');

            $result = [
                'domain'        => $domain,
                'available'     => $available,
                'taken'         => !$available,
                'for_sale'      => $isPremium && $available,
                'premium'       => $isPremium,
                'premium_price' => ($isPremium && $premiumPrice > 0) ? $premiumPrice : null,
                'tld'           => $tld,
                'error'         => null,
            ];

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Check availability for a flat list of fully-qualified domain names.
     * Unlike checkAvailability(), this does NOT build a cross product —
     * it sends exactly the FQDNs you provide, batched in chunks of 50.
     *
     * @param  string[]  $fqdns  e.g. ['flash.com', 'nova.ai', 'bolt.net']
     * @return array{results: array, error: string|null}
     */
    public function checkFqdns(array $fqdns): array
    {
        $fqdns = array_values(array_filter(array_map('strtolower', $fqdns)));

        if (empty($fqdns)) {
            return ['results' => [], 'error' => null];
        }

        $results = [];
        $uncached = [];

        foreach ($fqdns as $domain) {
            $cached = Cache::get("nc-domain:{$domain}");
            if ($cached !== null) {
                $results[] = $cached;
            } else {
                $uncached[] = $domain;
            }
        }

        $chunks = array_chunk($uncached, self::MAX_DOMAINS_PER_REQUEST);

        foreach ($chunks as $chunk) {
            try {
                $chunkResults = $this->apiCheck($chunk);
                foreach ($chunkResults as $result) {
                    Cache::put(
                        "nc-domain:{$result['domain']}",
                        $result,
                        now()->addMinutes(self::CACHE_TTL_MINUTES)
                    );
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                Log::warning('Namecheap checkFqdns batch error — retrying individually', [
                    'message' => $e->getMessage(),
                    'chunk_size' => count($chunk),
                ]);

                foreach ($chunk as $domain) {
                    try {
                        $single = $this->apiCheck([$domain]);
                        foreach ($single as $result) {
                            Cache::put(
                                "nc-domain:{$result['domain']}",
                                $result,
                                now()->addMinutes(self::CACHE_TTL_MINUTES)
                            );
                            $results[] = $result;
                        }
                    } catch (\Throwable $e2) {
                        Log::info('Namecheap checkFqdns unsupported TLD, trying GoDaddy', ['domain' => $domain, 'error' => $e2->getMessage()]);
                        $gdClient = app(GoDaddyClient::class);
                        if ($gdClient->isConfigured()) {
                            $gdResults = $gdClient->checkFqdns([$domain]);
                            foreach ($gdResults['results'] as $result) {
                                Cache::put("nc-domain:{$result['domain']}", $result, now()->addMinutes(self::CACHE_TTL_MINUTES));
                                $results[] = $result;
                            }
                        } else {
                            $tldPart    = str_contains($domain, '.') ? '.' . substr($domain, strpos($domain, '.') + 1) : '';
                            $placeholder = [
                                'domain'        => $domain,
                                'available'     => false,
                                'taken'         => false,
                                'for_sale'      => false,
                                'premium'       => false,
                                'premium_price' => null,
                                'tld'           => $tldPart,
                                'error'         => 'Unsupported TLD',
                            ];
                            Cache::put("nc-domain:{$domain}", $placeholder, now()->addMinutes(self::CACHE_TTL_MINUTES));
                            $results[] = $placeholder;
                        }
                    }
                }
            }
        }

        return [
            'results' => $results,
            'error'   => null,
        ];
    }

    /**
     * Sanitize a domain base name (strip TLD, lowercase, remove invalid chars).
     */
    private function sanitizeName(string $name): string
    {
        $name = strtolower(trim($name));
        // Strip any TLD suffix if the user accidentally included one
        $name = preg_replace('/\.[a-z]{2,}$/i', '', $name);
        // Only allow valid domain label characters
        $name = preg_replace('/[^a-z0-9-]/', '', $name);
        $name = trim($name, '-');

        return $name;
    }
}
