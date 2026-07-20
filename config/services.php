<?php

declare(strict_types=1);

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sms' => [
        'url' => env('SMS_GATEWAY_URL', 'https://richcommunication.dialog.lk/api/sms/send'),
        'user' => env('SMS_GATEWAY_USER'),
        'digest' => env('SMS_GATEWAY_DIGEST'),
        'mask' => env('SMS_GATEWAY_MASK', 'ELWIDS'),
        'campaign_name' => env('SMS_GATEWAY_CAMPAIGN_NAME', 'alerts'),
        'timeout_seconds' => (int) env('SMS_GATEWAY_TIMEOUT_SECONDS', 15),
    ],

];
