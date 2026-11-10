<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Every connection is one EmailLabs panel, authenticated with the API key
    | pair generated in Account, Settings, API: the Application-Key ("app_key")
    | and the 128 character Authorization key ("secret_key").
    |
    | "smtp_account" is the default SMTP account messages are sent through,
    | for example "1.mypanel.smtp"; one panel may have several. Override it
    | per mailer ("smtp_account" in config/mail.php), per message
    | (X-EmailLabs-Smtp-Account header) or per call.
    |
    | "webhook_secret" is the secret key generated for the webhook in the
    | panel; "webhook_username" and "webhook_password" are required only when
    | Basic auth is enabled for the webhook.
    |
    | Use EmailLabs::connection('name') for another panel and
    | EmailLabs::using([...]) for keys stored at runtime, for example per
    | tenant. Options outside "connections" are shared by every connection
    | and can be overridden in a connection.
    |
    */

    'default' => env('EMAILLABS_CONNECTION', 'default'),

    'connections' => [

        'default' => [
            'app_key' => env('EMAILLABS_APP_KEY'),
            'secret_key' => env('EMAILLABS_SECRET_KEY'),
            'smtp_account' => env('EMAILLABS_SMTP_ACCOUNT'),
            'webhook_secret' => env('EMAILLABS_WEBHOOK_SECRET'),
            'webhook_username' => env('EMAILLABS_WEBHOOK_USERNAME'),
            'webhook_password' => env('EMAILLABS_WEBHOOK_PASSWORD'),
        ],

        // 'marketing' => [
        //     'app_key' => env('EMAILLABS_MARKETING_APP_KEY'),
        //     'secret_key' => env('EMAILLABS_MARKETING_SECRET_KEY'),
        //     'smtp_account' => env('EMAILLABS_MARKETING_SMTP_ACCOUNT'),
        //     'webhook_secret' => env('EMAILLABS_MARKETING_WEBHOOK_SECRET'),
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Reads (GET and OPTIONS) are retried "retries" times on connection
    | errors, HTTP 5xx and HTTP 429, with an exponential delay starting at
    | "retry_delay" milliseconds (or the Retry-After header). Sending is never
    | repeated automatically. "timezone" is used to interpret dates without
    | an offset and to format date filters.
    |
    */

    'base_url' => env('EMAILLABS_BASE_URL', 'https://api.emaillabs.io'),

    'timeout' => (int) env('EMAILLABS_TIMEOUT', 30),

    'retries' => (int) env('EMAILLABS_RETRIES', 2),

    'retry_delay' => (int) env('EMAILLABS_RETRY_DELAY', 500),

    'timezone' => env('EMAILLABS_TIMEZONE', 'Europe/Warsaw'),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers POST {path} (route name
    | "emaillabs.webhook"). Configure its full URL in the panel (Account,
    | Settings, Webhooks, transactional e-mails). Every request must carry a
    | valid X-Webhook-Checksum computed with the webhook secret of one of the
    | connections. Disable "verify" only for local development.
    |
    */

    'webhooks' => [
        'enabled' => (bool) env('EMAILLABS_WEBHOOKS_ENABLED', false),
        'path' => env('EMAILLABS_WEBHOOK_PATH', 'emaillabs/webhook'),
        'middleware' => [],
        'verify' => (bool) env('EMAILLABS_WEBHOOK_VERIFY', true),
    ],

];
