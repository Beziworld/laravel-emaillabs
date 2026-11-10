<?php

declare(strict_types=1);

use Beziworld\EmailLabs\Enums\EmailStatus;
use Beziworld\EmailLabs\Events\EmailBounced;
use Beziworld\EmailLabs\Events\EmailClicked;
use Beziworld\EmailLabs\Events\EmailDelivered;
use Beziworld\EmailLabs\Events\EmailDropped;
use Beziworld\EmailLabs\Events\EmailEventReceived;
use Beziworld\EmailLabs\Events\EmailOpened;
use Beziworld\EmailLabs\Events\WebhookReceived;
use Beziworld\EmailLabs\Facades\EmailLabs;
use Beziworld\EmailLabs\Security\WebhookSignature;
use Beziworld\EmailLabs\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * @return array<string, string>
 */
function webhookHeaders(string $secret = TestCase::WEBHOOK_SECRET, string $date = '2026-11-10 15:00:00', string $requestId = 'req-1'): array
{
    return [
        'X-Webhook-Date' => $date,
        'Request-Id' => $requestId,
        'X-Webhook-Checksum' => (new WebhookSignature($secret))->compute($date, $requestId),
        'Content-Type' => 'application/json',
    ];
}

function webhookBody(): string
{
    return (string) file_get_contents(__DIR__ . '/../Fixtures/webhook-transactional.json');
}

it('dispatches events for a signed webhook and answers ok', function (): void {
    Event::fake();

    $this->call('POST', '/emaillabs/webhook', server: transformHeaders(webhookHeaders()), content: webhookBody())
        ->assertOk()
        ->assertSeeText('ok');

    Event::assertDispatched(WebhookReceived::class, fn(WebhookReceived $event): bool => count($event->events) === 4
        && count($event->payload) === 5
        && $event->connection === 'default'
        && $event->requestId === 'req-1');
    Event::assertDispatchedTimes(EmailEventReceived::class, 4);
    Event::assertDispatched(EmailDropped::class, fn(EmailDropped $event): bool => $event->event->to->email === 'test@test.com'
        && $event->event->messageId === 'd18aa9aa-109e-1b16-a52d-205eac42e44a@domain.com'
        && $event->event->statusDescription === 'test@test.com in blacklist'
        && $event->event->tags === []
        && count($event->event->history) === 2
        && $event->event->statusTime?->timestamp === 1580996271);
    Event::assertDispatched(EmailDelivered::class, fn(EmailDelivered $event): bool => $event->event->hasTag('shipping'));
    Event::assertDispatched(EmailBounced::class, fn(EmailBounced $event): bool => $event->event->status === EmailStatus::Hardbounce
        && $event->event->status->isPermanentFailure());
    Event::assertDispatched(EmailOpened::class);
    Event::assertNotDispatched(EmailClicked::class);
});

/**
 * @param array<string, string> $headers
 * @return array<string, string>
 */
function transformHeaders(array $headers): array
{
    $server = [];

    foreach ($headers as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
    }

    return $server;
}

it('rejects requests without or with a wrong checksum', function (): void {
    Event::fake([WebhookReceived::class, EmailEventReceived::class]);

    $this->call('POST', '/emaillabs/webhook', content: webhookBody())->assertForbidden();
    $this->call('POST', '/emaillabs/webhook', server: transformHeaders(webhookHeaders('wrong-secret')), content: webhookBody())->assertForbidden();

    $headers = webhookHeaders();
    $headers['Request-Id'] = 'tampered';
    $this->call('POST', '/emaillabs/webhook', server: transformHeaders($headers), content: webhookBody())->assertForbidden();

    Event::assertNotDispatched(WebhookReceived::class);
    Event::assertNotDispatched(EmailEventReceived::class);
});

it('identifies the connection by its webhook secret and checks basic auth', function (): void {
    Event::fake([WebhookReceived::class]);
    $server = transformHeaders(webhookHeaders('marketing-webhook-secret'));

    $this->call('POST', '/emaillabs/webhook', server: $server, content: webhookBody())->assertUnauthorized();
    $this->call('POST', '/emaillabs/webhook', server: [...$server, 'PHP_AUTH_USER' => 'hook', 'PHP_AUTH_PW' => 'wrong'], content: webhookBody())->assertUnauthorized();
    $this->call('POST', '/emaillabs/webhook', server: [...$server, 'PHP_AUTH_USER' => 'hook', 'PHP_AUTH_PW' => 'pass'], content: webhookBody())->assertOk();

    Event::assertDispatched(WebhookReceived::class, fn(WebhookReceived $event): bool => $event->connection === 'marketing');
});

it('accepts checksums of tenant panels from the resolver', function (): void {
    Event::fake([WebhookReceived::class]);
    EmailLabs::resolveWebhookSecretsUsing(fn(): array => ['tenant-42' => 'tenant-secret']);

    $this->call('POST', '/emaillabs/webhook', server: transformHeaders(webhookHeaders('tenant-secret')), content: webhookBody())->assertOk();

    Event::assertDispatched(WebhookReceived::class, fn(WebhookReceived $event): bool => $event->connection === 'tenant-42');
});

it('answers ok without events for an unexpected body', function (): void {
    Event::fake([EmailEventReceived::class, WebhookReceived::class]);

    $this->call('POST', '/emaillabs/webhook', server: transformHeaders(webhookHeaders()), content: 'not json')->assertOk();

    Event::assertNotDispatched(EmailEventReceived::class);
    Event::assertDispatched(WebhookReceived::class, fn(WebhookReceived $event): bool => $event->payload === []);
});

it('accepts a single event object', function (): void {
    Event::fake([EmailEventReceived::class]);
    $event = json_decode(webhookBody(), true)[1];

    $this->call('POST', '/emaillabs/webhook', server: transformHeaders(webhookHeaders()), content: (string) json_encode($event))->assertOk();

    Event::assertDispatchedTimes(EmailEventReceived::class, 1);
});

it('skips verification when disabled', function (): void {
    config()->set('emaillabs.webhooks.verify', false);
    Event::fake([WebhookReceived::class]);

    $this->call('POST', '/emaillabs/webhook', server: ['CONTENT_TYPE' => 'application/json'], content: webhookBody())->assertOk();

    Event::assertDispatched(WebhookReceived::class, fn(WebhookReceived $event): bool => $event->connection === 'default');
});

it('names the webhook route', function (): void {
    expect(route('emaillabs.webhook'))->toBe('http://localhost/emaillabs/webhook');
});
