# Laravel EmailLabs

[![tests](https://github.com/beziworld/laravel-emaillabs/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-emaillabs/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-emaillabs.svg)](https://packagist.org/packages/beziworld/laravel-emaillabs)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-emaillabs.svg)](LICENSE.md)

Laravel integration for [EmailLabs](https://emaillabs.io) (Vercom): a Laravel Mail transport on the EmailLabs REST API
v2.1, stored templates with variables, tags and custom headers, signed delivery, bounce, open and click webhooks
dispatched as Laravel events, the blacklist (suppression list), sending statistics and multiple panels and SMTP
accounts.

[Polska wersja dokumentacji](README.pl.md)

## Features

- `emaillabs` mail transport (`Mail::extend`): send any Mailable or notification through the API, with per-recipient
  Message-IDs, Cc, Bcc, Reply-To, attachments and inline images.
- Mailable `tag()` and `metadata()` mapped to EmailLabs tags and `X-Metadata-*` headers; control headers for the SMTP
  account, a stored template and its variables.
- Direct API: `EmailLabsMessage` builder with per-recipient variables, global variables and templates.
- Templates: list, add, delete (`{{ variable }}`, conditions and loops).
- Webhook route with `X-Webhook-Checksum` verification (SHA1 of secret, date and request ID), optional Basic auth, and
  events: `EmailDelivered`, `EmailBounced`, `EmailDropped`, `EmailDeferred`, `EmailOpened`, `EmailClicked`,
  `EmailEventReceived`, `WebhookReceived`.
- Statuses, opens and clicks of sent messages, aggregated statistics, SMTP account list.
- Blacklist of addresses and domains: list, add, remove, check.
- Multiple panels (named connections, runtime keys per tenant) and multiple SMTP accounts per panel.
- No SDK dependency: Laravel HTTP client, so `Http::fake()` works; reads retried on 5xx and 429, sends never repeated.
- Typed immutable responses, one exception hierarchy, PHP 8.3+, Laravel 12 and 13, PHPStan level 8.

## Why this package

| Package | Status (Packagist, November 2026) |
|---|---|
| [`emaillabs/emaillabs-php-api`](https://packagist.org/packages/emaillabs/emaillabs-php-api) (50k downloads) | Official SDK, `dev-master` only, last change 2018, targets the older v1 API (`api.emaillabs.net.pl`), no Laravel integration |
| [`sigrun/lelpl`](https://packagist.org/packages/sigrun/lelpl) (2k) | `dev-master` from 2019, wraps the SDK above |
| [`beryldev/laravel-emaillabs`](https://packagist.org/packages/beryldev/laravel-emaillabs) (374) | v1.0.0 from 2016, Laravel 5.2 |

This package targets the current EmailLabs API v2.1 (`api.emaillabs.io`, `Application-Key` and `Authorization`
headers), integrates with Laravel Mail as a Symfony Mailer transport, verifies webhook checksums and turns delivery
reports into events, supports Laravel 12 and 13 and is fully tested.

## Installation

```bash
composer require beziworld/laravel-emaillabs
php artisan vendor:publish --tag=emaillabs-config   # optional
```

```dotenv
EMAILLABS_APP_KEY=your-application-key
EMAILLABS_SECRET_KEY=your-128-character-authorization-key
EMAILLABS_SMTP_ACCOUNT=1.yourpanel.smtp
EMAILLABS_WEBHOOK_SECRET=secret-generated-for-the-webhook   # for webhooks
```

Keys are generated in the panel under Account, Settings, API.

## Mail transport

Add a mailer to `config/mail.php` and make it the default (or use it selectively):

```php
'mailers' => [
    'emaillabs' => [
        'transport' => 'emaillabs',
        // 'connection' => 'default',          // connection from config/emaillabs.php
        // 'smtp_account' => '2.yourpanel.smtp', // another SMTP account of the panel
    ],
],
```

```dotenv
MAIL_MAILER=emaillabs
```

Every Mailable, notification and `Mail::raw()` now goes through the API:

```php
use Illuminate\Mail\Mailables\Envelope;

public function envelope(): Envelope
{
    return new Envelope(
        subject: 'Your order has shipped',
        tags: ['orders', 'shipping'],          // EmailLabs tags (reports, webhooks)
        metadata: ['order_id' => $this->order->id], // sent as X-Metadata-order_id
    );
}
```

Each "To" recipient receives a separate copy with its own Message-ID (EmailLabs requires unique IDs to match webhook
statuses). The ID of the first recipient becomes the Message-ID of `SentMessage`; all IDs are in
`$transport->lastResult()` and in the `EmailSent` event.

Control headers (removed from the forwarded headers):

| Header | Effect |
|---|---|
| `X-EmailLabs-Smtp-Account` | send through another SMTP account |
| `X-EmailLabs-Template` | use a stored template (templateId) |
| `X-EmailLabs-Vars` | JSON object with template variables (`globalVars`) |

```php
use Beziworld\EmailLabs\Transport\EmailLabsTransport;

public function headers(): Headers
{
    return new Headers(text: [
        EmailLabsTransport::HEADER_TEMPLATE => 'welcome-v2',
        EmailLabsTransport::HEADER_VARS => json_encode(['name' => $this->user->name]),
        'X-TRACKING-OFF' => '1', // disable link tracking for this message
    ]);
}
```

Other custom headers are passed to EmailLabs unchanged. Inline images (`$message->embed()`) are referenced by file
name, as EmailLabs expects.

## Sending through the API

```php
use Beziworld\EmailLabs\Facades\EmailLabs;
use Beziworld\EmailLabs\Messages\EmailLabsMessage;

$result = EmailLabs::send(
    EmailLabsMessage::create('Hello {{ name }}')
        ->from('shop@example.com', 'Shop')
        ->to('jane@example.com', 'Jane', vars: ['name' => 'Jane'], messageId: 'order-1001@example.com')
        ->to('john@example.com', 'John', vars: ['name' => 'John'])
        ->html('<p>Hi {{ name }}</p>')
        ->text('Hi {{ name }}')
        ->tag('orders')
        ->header('X-Order', '1001')
        ->attachData($pdf, 'invoice.pdf', 'application/pdf')
);

$result->messageIdFor('jane@example.com'); // order-1001@example.com
$result->isPartial();                       // true when EmailLabs answered 207
$result->errors;                            // rejected recipients
```

Templates:

```php
$id = EmailLabs::addTemplate('Welcome', '<p>Dear {{ name }}</p>', 'Dear {{ name }}', externalId: 'welcome-v2');

EmailLabs::send(EmailLabsMessage::create('Welcome')->from('shop@example.com')
    ->to('jane@example.com', vars: ['name' => 'Jane'])
    ->template('welcome-v2', ['shop' => 'Example']));

EmailLabs::templates();
EmailLabs::deleteTemplates('welcome-v2');
```

## Statuses, opens, clicks and statistics

```php
EmailLabs::email('order-1001@example.com');           // EmailEvent: status, statusTime, history
EmailLabs::emails(['to' => 'jane@example.com', 'tags' => ['orders'], 'dateFrom' => now()->subDay()]);
EmailLabs::allEmails(['smtpAccount' => '1.yourpanel.smtp'])->each(fn ($event) => ...); // LazyCollection
EmailLabs::opens(['messageId' => 'order-1001@example.com']);   // kept 7 days
EmailLabs::clicks(['dateFrom' => now()->subDays(2)]);
EmailLabs::statistics(now()->subWeek(), now(), withTags: true); // aggregated data as returned
EmailLabs::smtpAccounts();
```

Status values (`EmailStatus`): `Injected`, `Ok`, `Deferred`, `Softbounce`, `Hardbounce`, `Spambounce`, `Dropped`,
`Open`, `Click`, with helpers `isDelivered()`, `isBounce()`, `isPermanentFailure()`, `isEngagement()`.

## Blacklist

```php
use Beziworld\EmailLabs\Enums\BlacklistReason;

EmailLabs::addToBlacklist('jane@example.com', BlacklistReason::Unsubscribe, 'GDPR request');
EmailLabs::isBlacklisted('jane@example.com');
EmailLabs::blacklist();                       // first page (up to 1000)
EmailLabs::allBlacklisted();                  // LazyCollection over every page
EmailLabs::removeFromBlacklist(['jane@example.com']);
EmailLabs::addDomainToBlacklist('spam.example');
EmailLabs::blacklistReasons();                // hardbounce, unsubscribe, spam_complaint, other
```

## Webhooks

1. Enable the route:

   ```dotenv
   EMAILLABS_WEBHOOKS_ENABLED=true
   EMAILLABS_WEBHOOK_SECRET=secret-generated-in-the-panel
   ```

   It registers `POST /emaillabs/webhook` (route `emaillabs.webhook`).

2. In the panel (Account, Settings, Webhooks, transactional e-mails) enter the URL, generate the SecretKey and copy it
   to `EMAILLABS_WEBHOOK_SECRET`. With Basic auth enabled, set `webhook_username` and `webhook_password` of the
   connection.

3. Listen to the events:

   ```php
   use Beziworld\EmailLabs\Events\EmailBounced;
   use Beziworld\EmailLabs\Events\EmailDelivered;

   Event::listen(function (EmailBounced $event) {
       if ($event->event->status->isPermanentFailure()) {
           User::where('email', $event->event->to->email)->update(['email_bounced_at' => now()]);
       }
   });

   Event::listen(fn (EmailDelivered $event) => MailLog::where('message_id', $event->event->messageId)
       ->update(['delivered_at' => $event->event->statusTime]));
   ```

The middleware compares `X-Webhook-Checksum` with `sha1("{secret}|{X-Webhook-Date}|{Request-Id}")` for the secret of
every connection (and of `EmailLabs::resolveWebhookSecretsUsing()` for tenants), so the event reports which panel sent
it. The response is always `ok` with HTTP 200, as EmailLabs requires; EmailLabs waits only 500 ms, so queue slow
listeners. `WebhookReceived` carries every item of the request, including SMS or push events, which are not mapped.
Opens and clicks arrive only when tracking is enabled for the SMTP account.

## Multiple panels and SMTP accounts

```php
'connections' => [
    'default' => ['app_key' => env('EMAILLABS_APP_KEY'), 'secret_key' => env('EMAILLABS_SECRET_KEY'), 'smtp_account' => '1.shop.smtp'],
    'marketing' => ['app_key' => env('EMAILLABS_MARKETING_APP_KEY'), 'secret_key' => env('EMAILLABS_MARKETING_SECRET_KEY'), 'smtp_account' => '1.news.smtp'],
],
```

```php
EmailLabs::connection('marketing')->send($message);
EmailLabs::connection()->forSmtpAccount('2.shop.smtp')->send($message);
EmailLabs::using(['app_key' => $tenant->el_app_key, 'secret_key' => decrypt($tenant->el_secret), 'smtp_account' => $tenant->el_smtp]);
```

`php artisan emaillabs:smtp-accounts --connection=marketing` lists the SMTP accounts and checks the keys.

## Error handling

All exceptions extend `Beziworld\EmailLabs\Exceptions\EmailLabsException`:

| Exception | When |
|---|---|
| `ApiException` | EmailLabs rejected the request (`errorCode()` such as `E-0-003`, `errorTitle()`, `httpStatus()`, `uniqId()`, `errors()`, `retryAfter()`, `isRateLimited()`, `isAuthenticationError()`, `isValidationError()`, `isNotFound()`) or could not be reached |
| `InvalidMessage` | missing recipient, sender, subject or content, more than 200 recipients, invalid `X-EmailLabs-Vars` |
| `InvalidConfiguration` | missing keys or SMTP account, unknown connection |

The mail transport wraps them in Symfony's `TransportException`, as Laravel expects.

Reads (GET, OPTIONS) are retried on connection errors, HTTP 5xx and HTTP 429 (honouring `Retry-After`, at most 60 s).
Sending is never retried automatically: a request that timed out may already have been accepted.

## Testing your application

```php
Http::fake(['api.emaillabs.io/*' => Http::response(['meta' => ['status' => 200], 'data' => []])]);

Mail::to('jane@example.com')->send(new OrderShipped($order));

Http::assertSent(fn ($request) => $request->url() === 'https://api.emaillabs.io/v2.1/email');
```

## Development

```bash
composer install
composer ci   # pint --test, phpstan (level 8), pest
```

## Author

Created and maintained by **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Issues and feature requests: [GitHub issues](https://github.com/beziworld/laravel-emaillabs/issues)
- Security vulnerabilities: see [SECURITY.md](SECURITY.md)

Need an integration? Get in touch through [beziworld.eu](https://beziworld.eu).

## License

MIT. See [LICENSE.md](LICENSE.md). This package is not affiliated with, endorsed by or sponsored by Vercom S.A. or
EmailLabs. "EmailLabs" is a trademark of its owner.
