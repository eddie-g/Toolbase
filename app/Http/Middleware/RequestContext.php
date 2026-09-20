<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id. It is on every log line the request writes (and
 * on the lines of the jobs it queues: Context travels with them), on the
 * error report, and on the response as X-Request-Id, so a user's "it failed
 * at 14:02" can be matched to one request.
 *
 * Also the one place that notices a slow request (config observability.slow_request_ms).
 */
class RequestContext
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $id = self::accept($request->headers->get(self::HEADER)) ?? (string) Str::uuid();
        $request->attributes->set('request_id', $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        $this->warnIfSlow($request, $response, (int) round((microtime(true) - $started) * 1000));

        return $response;
    }

    /** The id of the current request, or null on the command line. */
    public static function id(): ?string
    {
        $id = Context::get('request_id');

        return is_string($id) ? $id : null;
    }

    /** An id handed in by the load balancer is kept if it looks like one. */
    private static function accept(?string $inbound): ?string
    {
        return is_string($inbound) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $inbound) === 1 ? $inbound : null;
    }

    private function warnIfSlow(Request $request, Response $response, int $milliseconds): void
    {
        $route = (string) $request->route()?->getName();
        $limits = (array) config('observability.slow_request_ms');
        $limit = (int) ($limits[$route] ?? $limits['default'] ?? 0);
        if ($limit <= 0 || $milliseconds < $limit) {
            return;
        }

        Log::warning('Slow request', [
            'route' => $route !== '' ? $route : $request->path(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'ms' => $milliseconds,
            'limit_ms' => $limit,
            'user_id' => Auth::guard('web')->id(),
            'admin_id' => Auth::guard('admin')->id(),
        ]);
    }
}
