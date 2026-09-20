<?php

namespace App\Http\Middleware;

use App\Support\EditorSwitches;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 503 for the editor's or the export's routes while their switch is
 * off (App\Support\EditorSwitches). Before the rate limits and before any
 * controller: a switched-off route must cost nothing.
 */
class EditorKillSwitch
{
    public const RETRY_AFTER_SECONDS = 300;

    public function __construct(private EditorSwitches $switches) {}

    public function handle(Request $request, Closure $next): Response
    {
        $switch = $this->switches->blocking($request->route()?->getName());
        if ($switch === null) {
            return $next($request);
        }

        $message = $this->switches->message($switch);
        $headers = ['Retry-After' => (string) self::RETRY_AFTER_SECONDS, 'Cache-Control' => 'no-store'];

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            return response()->json(['success' => false, 'code' => "{$switch}_disabled", 'message' => $message], 503, $headers);
        }

        return response()->view('errors.editor-unavailable', ['message' => $message], 503, $headers);
    }
}
