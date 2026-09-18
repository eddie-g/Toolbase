<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Production configuration check
    |--------------------------------------------------------------------------
    |
    | In production the app refuses to serve requests or start workers while
    | the settings below are unsafe or a required secret is missing, and says
    | exactly which variables to fix (App\Support\ProductionConfig). The same
    | check runs in any environment with: php artisan app:check-config
    |
    | It does not run for other artisan commands, so an image can be built
    | and its config cached before the secrets exist.
    |
    */

    'enforce' => (bool) env('PRODUCTION_CONFIG_CHECK', true),

    /*
    | Config key => the variable that sets it. Every entry must be non-empty.
    | These are the keys of features that are live: billing, mail, sign-in
    | with Google, domain ideas, logo generation and Word/Excel conversion.
    */

    'required' => [
        'app.key' => 'APP_KEY',
        'services.stripe.key' => 'STRIPE_KEY',
        'services.stripe.secret' => 'STRIPE_SECRET',
        'services.stripe.webhook_secret' => 'STRIPE_WEBHOOK_SECRET',
        'services.google.client_id' => 'GOOGLE_CLIENT_ID',
        'services.google.client_secret' => 'GOOGLE_CLIENT_SECRET',
        'services.gemini.api_key' => 'GEMINI_API_KEY',
        'services.openai.api_key' => 'OPENAI_API_KEY',
        'services.fal.key' => 'FAL_AI_KEY',
        'services.recraft.key' => 'RECRAFT_KEY',
        'services.adobe_pdf_services.client_id' => 'ADOBE_PDF_SERVICES_CLIENT_ID',
        'services.adobe_pdf_services.client_secret' => 'ADOBE_PDF_SERVICES_CLIENT_SECRET',
    ],

    /*
    | A deployment that runs without one of the features above lists the
    | config keys to skip, comma separated, e.g.
    | PRODUCTION_CONFIG_OPTIONAL=services.recraft.key,services.fal.key
    */

    'optional' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PRODUCTION_CONFIG_OPTIONAL', ''))
    ))),

];
