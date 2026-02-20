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

    'google' => [
        'merchant_id' => env('GOOGLE_MERCHANT_ID'),
        
        // Service account credentials
        'service_account' => [
            'type' => env('GOOGLE_SERVICE_ACCOUNT_TYPE'),
            'project_id' => env('GOOGLE_PROJECT_ID'),
            'private_key_id' => env('GOOGLE_PRIVATE_KEY_ID'),
            'private_key' => env('GOOGLE_PRIVATE_KEY'),
            'client_email' => env('GOOGLE_CLIENT_EMAIL'),
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'auth_uri' => env('GOOGLE_AUTH_URI'),
            'token_uri' => env('GOOGLE_TOKEN_URI'),
            'auth_provider_x509_cert_url' => env('GOOGLE_AUTH_PROVIDER_CERT_URL'),
            'client_x509_cert_url' => env('GOOGLE_CLIENT_CERT_URL'),
            'universe_domain' => env('GOOGLE_UNIVERSE_DOMAIN'),
        ],
    ],
];
