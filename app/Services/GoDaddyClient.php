<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GoDaddy Domain Availability Client
 *
 * Used as a fallback for TLDs that Namecheap does not support (e.g. .istanbul, .nyc).
 * Calls POST /v1/domains/available with a JSON array of FQDNs.
 * Auth: Authorization: sso-key {KEY}:{SECRET}
 */
class GoDaddyClient
{
    private const CACHE_TTL_MINUTES = 60;
    private const MAX_DOMAINS_PER_REQUEST = 50;
    private const CACHE_PREFIX = 'gd-domain:';

    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;

    public function __construct()
    {
        $this->baseUrl   = rtrim((string) config('services.godaddy.base_url', 'https://api.godaddy.com'), '/');
        $this->apiKey    = (string) config('services.godaddy.api_key', '');
        $this->apiSecret = (string) config('services.godaddy.api_secret', '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    /**
     * Check availability of a list of FQDNs.
     * Returns the same result shape as NamecheapClient so results can be merged seamlessly.
     *
     * @param  string[]  $fqdns  e.g. ['pupjoy.istanbul', 'nova.nyc']
     * @return array{results: array, error: string|null}
     */
    public function checkFqdns(array $fqdns): array
    {
        $fqdns = array_values(array_filter(array_map('strtolower', $fqdns)));

        if (empty($fqdns)) {
            return ['results' => [], 'error' => null];
        }

        $results  = [];
        $uncached = [];

        foreach ($fqdns as $domain) {
            $cached = Cache::get(self::CACHE_PREFIX . $domain);
            if ($cached !== null) {
                $results[] = $cached;
            } else {
                $uncached[] = $domain;
            }
        }

        $chunks = array_chunk($uncached, self::MAX_DOMAINS_PER_REQUEST);
        $errors = [];

        foreach ($chunks as $chunk) {
            try {
                $chunkResults = $this->apiBulkCheck($chunk);
                foreach ($chunkResults as $result) {
                    Cache::put(self::CACHE_PREFIX . $result['domain'], $result, now()->addMinutes(self::CACHE_TTL_MINUTES));
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                Log::error('GoDaddy bulk check error', ['message' => $e->getMessage(), 'domains' => $chunk]);
                $errors[] = $e->getMessage();

                // Still return placeholders so domains aren't silently dropped
                foreach ($chunk as $domain) {
                    $tldPart    = str_contains($domain, '.') ? '.' . substr($domain, strpos($domain, '.') + 1) : '';
                    $placeholder = [
                        'domain'        => $domain,
                        'available'     => false,
                        'taken'         => false,
                        'for_sale'      => false,
                        'premium'       => false,
                        'premium_price' => null,
                        'tld'           => $tldPart,
                        'error'         => 'Check failed',
                    ];
                    $results[] = $placeholder;
                }
            }
        }

        return [
            'results' => $results,
            'error'   => !empty($errors) ? implode('; ', $errors) : null,
        ];
    }

    /**
     * Call GoDaddy POST /v1/domains/available for a batch of FQDNs.
     */
    private function apiBulkCheck(array $domains): array
    {
        $response = Http::timeout(15)
            ->withHeaders([
                'Authorization' => 'sso-key ' . $this->apiKey . ':' . $this->apiSecret,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ])
            ->post($this->baseUrl . '/v1/domains/available?checkType=FAST', $domains);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'GoDaddy API returned HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        $data = $response->json();

        // Response is an array of mixed success/error objects
        // Success: { available, domain, definitive, price?, currency?, period? }
        // Error:   { code, domain, message }
        if (!is_array($data)) {
            throw new \RuntimeException('GoDaddy API returned unexpected response format');
        }

        $results = [];
        foreach ($data as $item) {
            if (!is_array($item) || empty($item['domain'])) {
                continue;
            }

            $domain  = strtolower((string) $item['domain']);
            $tldPart = str_contains($domain, '.') ? '.' . substr($domain, strpos($domain, '.') + 1) : '';

            // Distinguish error vs success: error items have a 'code' key, not 'available'
            if (isset($item['code'])) {
                $results[] = [
                    'domain'        => $domain,
                    'available'     => false,
                    'taken'         => false,
                    'for_sale'      => false,
                    'premium'       => false,
                    'premium_price' => null,
                    'tld'           => $tldPart,
                    'error'         => 'Unsupported TLD',
                ];
                continue;
            }

            $available = (bool) ($item['available'] ?? false);
            // GoDaddy prices are in micros of the currency (divide by 1,000,000 for dollars)
            $rawPrice  = isset($item['price']) ? (float) $item['price'] / 1_000_000 : null;
            $isPremium = $rawPrice !== null && $rawPrice > 50; // heuristic: >$50 = premium

            $results[] = [
                'domain'        => $domain,
                'available'     => $available,
                'taken'         => !$available,
                'for_sale'      => $isPremium && $available,
                'premium'       => $isPremium,
                'premium_price' => ($isPremium && $rawPrice !== null) ? $rawPrice : null,
                'tld'           => $tldPart,
                'error'         => null,
            ];
        }

        return $results;
    }
}
