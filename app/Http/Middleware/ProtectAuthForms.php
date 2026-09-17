<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the registration and password-reset forms, which Fortify registers
 * with only the "guest" middleware: an empty honeypot field, an optional
 * Cloudflare Turnstile check, and the named rate limiter for the path.
 */
class ProtectAuthForms
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $limiterName = config('security.auth_forms.paths.'.trim($request->path(), '/'));
        if (! $limiterName) {
            return $next($request);
        }

        $honeypot = (string) config('security.auth_forms.honeypot_field', 'website');
        if ($honeypot !== '' && filled($request->input($honeypot))) {
            Log::notice('Auth form honeypot tripped', ['path' => $request->path(), 'ip' => $request->ip()]);

            return $this->reject($request, 'email', 'The form could not be submitted. Please try again.', 422);
        }

        if (config('security.turnstile.secret_key') && ! $this->turnstilePasses($request)) {
            return $this->reject($request, 'email', 'Please complete the verification challenge and try again.', 422);
        }

        $limits = RateLimiter::limiter($limiterName)($request);
        foreach (is_array($limits) ? $limits : [$limits] as $limit) {
            /** @var Limit $limit */
            if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
                $seconds = RateLimiter::availableIn($limit->key);

                return $this->reject(
                    $request,
                    'email',
                    'Too many attempts. Please try again in '.max(1, (int) ceil($seconds / 60)).' minute(s).',
                    429,
                    ['Retry-After' => $seconds],
                );
            }
        }
        foreach (is_array($limits) ? $limits : [$limits] as $limit) {
            RateLimiter::hit($limit->key, $limit->decaySeconds);
        }

        return $next($request);
    }

    private function turnstilePasses(Request $request): bool
    {
        $token = (string) $request->input('cf-turnstile-response', '');
        if ($token === '') {
            return false;
        }

        try {
            $result = Http::asForm()->timeout(5)->post((string) config('security.turnstile.verify_url'), [
                'secret' => config('security.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);

            return $result->ok() && (bool) $result->json('success', false);
        } catch (\Throwable $e) {
            Log::warning('Turnstile verification failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function reject(Request $request, string $field, string $message, int $status, array $headers = []): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'errors' => [$field => [$message]]], $status, $headers);
        }

        $response = redirect()->back()->withInput($request->except(['password', 'password_confirmation']))->withErrors([$field => $message]);
        foreach ($headers as $name => $value) {
            $response->headers->set($name, (string) $value);
        }

        return $response;
    }
}
