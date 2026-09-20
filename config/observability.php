<?php

/*
|--------------------------------------------------------------------------
| Error reporting, request context, health and slow-request warnings
|--------------------------------------------------------------------------
|
| Sentry itself is configured in config/sentry.php (it reports nothing
| without SENTRY_LARAVEL_DSN). Everything here works with or without it.
|
*/

return [

    // What is running: a git sha or image tag. Set by the image build
    // (APP_RELEASE); tags server errors, editor errors and the health report.
    'release' => env('APP_RELEASE'),

    // Keys removed from JSON error responses when APP_DEBUG is off. They hold
    // Python output, paths and stack traces: they are logged under the
    // response's request_id instead.
    'scrub_response_keys' => ['output', 'trace', 'stderr', 'exception', 'file', 'line', 'debug'],

    // A request slower than this is logged as a warning with its route name.
    // Per route name; "default" covers the rest. 0 turns a route off.
    'slow_request_ms' => [
        'default' => (int) env('SLOW_REQUEST_MS', 3000),
        'login.store' => 1500,
        'documents.editPdfjs' => 2000,
        'documents.saveAnnotationState' => 1500,
        // These fork Python for the whole document and are expected to be slow.
        'documents.downloadAnnotatedPdf' => 5000,
        'documents.store' => 8000,
    ],

    'client_errors' => [
        'enabled' => (bool) env('CLIENT_ERRORS_ENABLED', true),
        'per_minute' => 20,           // per session and address
        'per_minute_per_ip' => 120,
        'max_body_kb' => 16,
        // The same error from the same visitor is recorded once in this window.
        'dedupe_seconds' => 300,
    ],

    'health' => [
        // GET /health/deep answers only to "Authorization: Bearer <token>" or
        // a signed-in operator. Without a token it is operators only.
        'token' => env('HEALTH_CHECK_TOKEN'),
        // Python is started at most this often; probes in between reuse the result.
        'python_cache_seconds' => 60,
        // Seconds a check may take before it counts as failed.
        'timeout_seconds' => 5,
    ],
];
