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

    /*
     * Stage 23 — which App\Contracts\SmsSender implementation AppServiceProvider
     * binds. 'log' writes the message to the application log; no real gateway is
     * contracted yet, so that stays the default.
     */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    /*
     * ERP single sign-on — the EmployeePlus ERP as an OAuth 2.0 authorization
     * server (authorization code + PKCE). ErpAuthController treats the feature
     * as absent until every value but the two numbers is set.
     */
    'erp' => [
        'authorize_url' => env('ERP_AUTHORIZE_URL'),
        'token_url' => env('ERP_TOKEN_URL'),
        'userinfo_url' => env('ERP_USERINFO_URL'),
        'client_id' => env('ERP_CLIENT_ID'),
        'client_secret' => env('ERP_CLIENT_SECRET'),
        'redirect_uri' => env('ERP_REDIRECT_URI'),
        'session_hours' => (int) env('ERP_SESSION_HOURS', 12),
        'timeout' => (int) env('ERP_TIMEOUT', 10),
    ],

];
