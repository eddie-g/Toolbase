<?php

// Ensure files written by PHP (view cache, config cache, etc.) are always
// world-writable. This prevents permission errors when artisan runs as root
// (via `sail artisan`) and the sail web process later tries to overwrite them.
umask(0000);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Production sits behind a load balancer or reverse proxy that terminates
        // TLS; without this the app never sees https, secure cookies are not
        // sent and every client shares the proxy's IP in rate limiters.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'json.response' => \App\Http\Middleware\ForceJsonResponse::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);

        // Only Stripe's webhook is called without a session. Every other
        // POST is made by the app's own pages, which send X-CSRF-TOKEN.
        $middleware->validateCsrfTokens(except: [
            '/stripe/webhook',
        ]);

        // AuthenticateSession keeps the password hash in the session and logs
        // every other session out when it changes (password change, "log out
        // other devices"). The Filament panels already run it; the plain web
        // routes did not.
        $middleware->web(append: [
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\ProtectAuthForms::class,
            // Rate limits for the editor's routes, by route name (config/editor_limits.php).
            \App\Http\Middleware\ThrottleEditorRoutes::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every Python slot taken: tell the client to retry rather than
        // queueing another process behind the ones already running.
        $exceptions->render(function (\App\Exceptions\PythonServiceBusyException $e, \Illuminate\Http\Request $request) {
            $headers = ['Retry-After' => (string) $e->retryAfterSeconds];
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 503, $headers);
            }

            return response($e->getMessage(), 503, $headers + ['Content-Type' => 'text/plain']);
        });
    })->create();
