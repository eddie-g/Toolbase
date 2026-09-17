<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Response security headers
    |--------------------------------------------------------------------------
    |
    | Sent by App\Http\Middleware\SecurityHeaders on every web response. The
    | CSP starts in report-only mode: browsers report violations but block
    | nothing, so the pdf.js editor, Filament and Stripe keep working while
    | the policy is tightened. Switch SECURITY_CSP_MODE to "enforce" once the
    | reports are clean, or "off" to send no CSP header at all.
    |
    */

    'headers' => [
        'hsts' => [
            'enabled' => (bool) env('SECURITY_HSTS', true),
            'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
            'include_subdomains' => (bool) env('SECURITY_HSTS_SUBDOMAINS', true),
            'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
        ],
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'SAMEORIGIN'),
        'content_type_options' => 'nosniff',
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', 'camera=(), microphone=(), geolocation=(), payment=(self "https://js.stripe.com" "https://checkout.stripe.com")'),
        'cross_origin_opener_policy' => env('SECURITY_COOP', 'same-origin-allow-popups'),
        'csp' => [
            'mode' => env('SECURITY_CSP_MODE', 'report-only'),
            'report_uri' => env('SECURITY_CSP_REPORT_URI'),
            'policy' => env('SECURITY_CSP_POLICY', implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "frame-ancestors 'self'",
                "form-action 'self' https://checkout.stripe.com",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://js.stripe.com https://checkout.stripe.com https://challenges.cloudflare.com https://www.googletagmanager.com https://www.google-analytics.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net",
                "font-src 'self' data: https://fonts.gstatic.com https://fonts.bunny.net",
                "img-src 'self' data: blob: https:",
                "media-src 'self' data: blob:",
                "worker-src 'self' blob:",
                "child-src 'self' blob:",
                "frame-src 'self' https://js.stripe.com https://checkout.stripe.com https://hooks.stripe.com https://challenges.cloudflare.com",
                "connect-src 'self' https://api.stripe.com https://checkout.stripe.com https://www.google-analytics.com wss: https:",
            ])),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration and password-reset forms
    |--------------------------------------------------------------------------
    |
    | App\Http\Middleware\ProtectAuthForms guards the POST paths listed here
    | with the named rate limiter, a honeypot field that must stay empty, and
    | Cloudflare Turnstile when a site key and secret are configured.
    |
    */

    'auth_forms' => [
        'paths' => [
            'register' => 'register',
            'forgot-password' => 'forgot-password',
        ],
        'honeypot_field' => 'website',
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

];
