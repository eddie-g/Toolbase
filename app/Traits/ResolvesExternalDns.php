<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Provides HTTP client with pre-resolved DNS to bypass Docker Desktop's
 * broken internal DNS resolver (127.0.0.11).
 */
trait ResolvesExternalDns
{
    /**
     * Resolve host to IPv4 with a hard timeout.
     *
     * Uses `getent ahostsv4` to avoid blocking indefinitely inside libc resolver
     * calls (which can happen in containerized DNS edge cases).
     */
    protected function resolveHostIp(string $host): ?string
    {
        try {
            $result = Process::timeout(2)->run(['getent', 'ahostsv4', $host]);
            if (!$result->successful()) {
                return null;
            }

            foreach (preg_split('/\r\n|\r|\n/', trim($result->output())) as $line) {
                if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\s+/', $line, $m)) {
                    return $m[1];
                }
            }
        } catch (\Throwable $e) {
            // Fall through to null so requests still proceed via normal DNS.
        }

        return null;
    }

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
            CURLOPT_CONNECTTIMEOUT => 15,
        ];

        // Pre-resolve hostname and pass to curl to bypass Docker's DNS
        if ($host) {
            $cacheKey = 'dns_resolve_' . $host;
            $ip = Cache::remember($cacheKey, 300, function () use ($host) {
                return $this->resolveHostIp($host);
            });

            if ($ip) {
                $curlOptions[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$ip}"];
            }
        }

        return Http::withHeaders($headers)->withOptions(['curl' => $curlOptions]);
    }
}
