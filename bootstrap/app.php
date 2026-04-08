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
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureIsAdmin::class,
            'json.response' => \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            '/ai/chat',
            '/ai/sections',
            '/ai/sections/*',
            '/domain-search/ai-generate',
            '/stripe/webhook',
            '/pdf-state/stamp-preview',
        ]);
        
        // Enable stateful Sanctum authentication
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
