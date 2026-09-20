<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the editor's rate limits (config/editor_limits.php) by route name,
 * so the classification lives in one reviewable list instead of on seventy
 * route definitions. The limiters themselves are in AppServiceProvider.
 */
class ThrottleEditorRoutes
{
    public function __construct(private ThrottleRequests $throttle)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $class = config('editor_limits.routes')[(string) $request->route()?->getName()] ?? null;
        if ($class === null) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, "editor-{$class}");
    }
}
