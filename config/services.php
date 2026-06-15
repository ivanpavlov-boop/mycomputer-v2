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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shipping' => [
        'speedy' => [
            'api_url' => env('SPEEDY_API_URL'),
        ],
        'econt' => [
            'api_url' => env('ECONT_API_URL'),
        ],
    ],

    'payments' => [
        'mypos' => [
            'api_url' => env('MYPOS_API_URL'),
        ],
        'borica' => [
            'api_url' => env('BORICA_API_URL'),
        ],
        'stripe' => [
            'secret' => env('STRIPE_SECRET'),
        ],
    ],

    'webhooks' => [
        'mock_secret' => env('WEBHOOK_MOCK_SECRET', 'local-webhook-secret'),
    ],

    'suppliers' => [
        'http_connect_timeout' => env('SUPPLIER_FEED_HTTP_CONNECT_TIMEOUT', 30),
        'http_timeout' => env('SUPPLIER_FEED_HTTP_TIMEOUT', 300),
        'apcom' => [
            'feed_url' => env('APCOM_FEED_URL', 'https://example.invalid/apcom-feed.xml'),
        ],
    ],

];
