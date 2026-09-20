<?php

/*
|--------------------------------------------------------------------------
| Sentry (server errors, and editor errors relayed by /client-errors)
|--------------------------------------------------------------------------
|
| Nothing is sent without SENTRY_LARAVEL_DSN. Exceptions reach Sentry through
| Laravel's handler (bootstrap/app.php), so whatever Laravel does not report
| (validation, 404, auth) is not sent either.
|
*/

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // The git sha or image tag, so an error names the deploy that caused it.
    'release' => env('APP_RELEASE'),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    // Every error; lower it only if an incident floods the quota.
    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),

    // Performance traces: off unless asked for. 0.05 is plenty at thousands of users.
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    // No IPs, cookies, request bodies or e-mail addresses. The account is
    // identified by id only (App\Observability\ErrorContext).
    'send_default_pii' => false,

    // API keys and tokens are taken out of exception messages before they leave.
    'before_send' => [\App\Observability\SentryScrubber::class, 'beforeSend'],

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => true,
        // Queries without their bindings: the bindings are user data.
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    'tracing' => [
        'queue_job_transactions' => true,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'sql_origin' => false,
        'views' => false,
        'livewire' => true,
        'http_client_requests' => true,
        'cache' => false,
        'redis_commands' => false,
        'notifications' => true,
        'missing_routes' => false,
        'continue_after_response' => true,
        'default_integrations' => true,
    ],
];
