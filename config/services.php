<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URL'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'pricing' => [
            'input_per_million' => 0.10, // $0.10 per 1M input tokens
            'output_per_million' => 0.40, // $0.40 per 1M output tokens
        ],
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'dall-e-3'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'pricing' => [
            'dalle3_standard_1024x1024' => 0.040,
            'dalle3_standard_1024x1792' => 0.080,
            'dalle3_standard_1792x1024' => 0.080,
            'dalle3_hd_1024x1024' => 0.080,
            'dalle3_hd_1024x1792' => 0.120,
            'dalle3_hd_1792x1024' => 0.120,
        ],
    ],

    'fal' => [
        'key' => env('FAL_AI_KEY'),
    ],

    'recraft' => [
        'key' => env('RECRAFT_KEY'),
        'base_url' => env('RECRAFT_BASE_URL', 'https://external.api.recraft.ai'),
    ],

    'logo_editor_enabled' => env('LOGO_EDITOR_ENABLED', false),
    'logo_custom_prompt_enabled' => env('LOGO_CUSTOM_PROMPT_ENABLED', false),

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'namecheap' => [
        'api_user'  => env('NAMECHEAP_API_USER', ''),
        'api_key'   => env('NAMECHEAP_API_KEY', ''),
        'username'  => env('NAMECHEAP_USERNAME', ''),   // defaults to api_user if empty
        'client_ip' => env('NAMECHEAP_CLIENT_IP', '127.0.0.1'),
        'sandbox'   => env('NAMECHEAP_SANDBOX', true),  // sandbox by default for safety
    ],

    'domain_lookup' => env('DOMAIN_LOOKUP', 'whois'), // 'namecheap' or 'whois'

    'godaddy' => [
        'api_key'    => env('GODADDY_API_KEY', ''),
        'api_secret' => env('GODADDY_API_SECRET', ''),
        'base_url'   => env('GODADDY_BASE_URL', 'https://api.godaddy.com'),
    ],

];
