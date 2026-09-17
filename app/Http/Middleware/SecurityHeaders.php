<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side hardening headers on every web response. Values come from
 * config/security.php so an environment can loosen the CSP or turn HSTS off
 * without a code change. HSTS is only sent on https responses, as browsers
 * ignore it otherwise and it must never be cached for a plain-http origin.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        $config = config('security.headers', []);

        if (($config['hsts']['enabled'] ?? true) && $request->secure()) {
            $maxAge = (int) ($config['hsts']['max_age'] ?? 31536000);
            $value = 'max-age='.$maxAge;
            if ($config['hsts']['include_subdomains'] ?? true) {
                $value .= '; includeSubDomains';
            }
            if ($config['hsts']['preload'] ?? false) {
                $value .= '; preload';
            }
            $headers->set('Strict-Transport-Security', $value);
        }

        foreach ([
            'X-Frame-Options' => $config['frame_options'] ?? 'SAMEORIGIN',
            'X-Content-Type-Options' => $config['content_type_options'] ?? 'nosniff',
            'Referrer-Policy' => $config['referrer_policy'] ?? 'strict-origin-when-cross-origin',
            'Permissions-Policy' => $config['permissions_policy'] ?? null,
            'Cross-Origin-Opener-Policy' => $config['cross_origin_opener_policy'] ?? null,
        ] as $name => $value) {
            if ($value !== null && $value !== '' && ! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        $cspMode = $config['csp']['mode'] ?? 'report-only';
        $policy = trim((string) ($config['csp']['policy'] ?? ''));
        if ($policy !== '' && $cspMode !== 'off') {
            $name = $cspMode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            if (! empty($config['csp']['report_uri'])) {
                $policy .= '; report-uri '.$config['csp']['report_uri'];
            }
            $headers->set($name, $policy);
        }

        return $response;
    }
}
