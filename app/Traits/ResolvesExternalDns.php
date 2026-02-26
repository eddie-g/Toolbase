<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Provides HTTP client with pre-resolved DNS to bypass Docker Desktop's
 * broken internal DNS resolver (127.0.0.11).
 */
trait ResolvesExternalDns
{
    /**
     * Build an Http pending request that pre-resolves the hostname via
     * gethostbyname() and passes the IP to cURL via CURLOPT_RESOLVE.
     *
     * This avoids Docker Desktop's VPNKit DNS resolver which fails
     * after the first request per hostname.
     */
    protected function httpWithResolvedDns(string $url, array $headers = []): \Illuminate\Http\Client\PendingRequest
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);

        $curlOptions = [
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE  => true,
        ];

        // Pre-resolve hostname and pass to curl to bypass Docker's DNS
        if ($host) {
            $cacheKey = 'dns_resolve_' . $host;
            $ip = Cache::remember($cacheKey, 300, function () use ($host) {
                $resolved = gethostbyname($host);
                // gethostbyname returns the hostname unchanged if resolution fails
                return ($resolved !== $host) ? $resolved : null;
            });

            if ($ip) {
                $curlOptions[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$ip}"];
            }
        }

        return Http::withHeaders($headers)->withOptions(['curl' => $curlOptions]);
    }
}
