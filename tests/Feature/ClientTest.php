<?php

declare(strict_types=1);

use Beziworld\EmailLabs\Enums\BlacklistReason;
use Beziworld\EmailLabs\Enums\EmailStatus;
use Beziworld\EmailLabs\Events\EmailSent;
use Beziworld\EmailLabs\Exceptions\ApiException;
use Beziworld\EmailLabs\Exceptions\InvalidConfiguration;
use Beziworld\EmailLabs\Exceptions\InvalidMessage;
use Beziworld\EmailLabs\Facades\EmailLabs;
use Beziworld\EmailLabs\Messages\EmailLabsMessage;
use Beziworld\EmailLabs\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * @return array<array-key, mixed>
 */
function queryOf(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function orderMessage(): EmailLabsMessage
{
    return EmailLabsMessage::create('Order shipped')
        ->from('shop@example.com', 'Shop')
        ->to('jane@example.com', 'Jane', ['order' => '1001'], 'order-1001@example.com')
        ->html('<p>Hi {{ name }}</p>')
        ->text('Hi {{ name }}');
}

it('sends an e-mail with the documented JSON body and authentication headers', function (): void {
    Event::fake([EmailSent::class]);
    Http::fake(['api.emaillabs.io/*' => Http::response(TestCase::sendResponse())]);

    $result = EmailLabs::send(orderMessage()->tag('orders')->cc('boss@example.com')->bcc('archive@example.com', 'Archive')
        ->replyTo('help@example.com')->header('X-Order', '1001')->globalVars(['shop' => 'Shop'])
        ->attachData('PDF', 'invoice.pdf', 'application/pdf'));

    expect($result->messages)->toHaveCount(1)
        ->and($result->messages[0]->status)->toBe(EmailStatus::Injected)
        ->and($result->messageIdFor('JANE@example.com'))->toBe(md5('jane@example.com') . '@example.com')
        ->and($result->uniqId)->toBe('00d928f759')
        ->and($result->isPartial())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://api.emaillabs.io/v2.1/email'
            && $request->header('Application-Key') === [TestCase::APP_KEY]
            && $request->header('Authorization') === [TestCase::SECRET_KEY]
            && $request->isJson()
            && $body['smtpAccount'] === '1.shop.smtp'
            && $body['subject'] === 'Order shipped'
            && $body['from'] === ['email' => 'shop@example.com', 'name' => 'Shop']
            && $body['to'] === [['email' => 'jane@example.com', 'name' => 'Jane', 'messageId' => 'order-1001@example.com', 'vars' => ['order' => '1001']]]
            && $body['content'] === ['html' => '<p>Hi {{ name }}</p>', 'text' => 'Hi {{ name }}']
            && $body['cc'] === [['email' => 'boss@example.com']]
            && $body['bcc'] === [['email' => 'archive@example.com', 'name' => 'Archive']]
            && $body['replyTo'] === ['email' => 'help@example.com']
            && $body['tags'] === ['orders']
            && $body['headers'] === ['X-Order' => '1001']
            && $body['globalVars'] === ['shop' => 'Shop']
            && $body['attachments'] === [['fileName' => 'invoice.pdf', 'fileMime' => 'application/pdf', 'fileContent' => base64_encode('PDF'), 'inline' => false]];
    });

    Event::assertDispatched(EmailSent::class, fn(EmailSent $event): bool => $event->connection === 'default' && $event->result === $result);
});

it('sends a stored template with variables and without tracking', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(TestCase::sendResponse())]);

    EmailLabs::send(EmailLabsMessage::create('Welcome')->from('shop@example.com')->to('jane@example.com')
        ->template('welcome-v2', ['name' => 'Jane'])->withoutTracking()->smtpAccount('3.shop.smtp'));

    Http::assertSent(fn(Request $request): bool => ($request->data()['content'] ?? null) === ['templateId' => 'welcome-v2']
        && ($request->data()['globalVars'] ?? null) === ['name' => 'Jane']
        && ($request->data()['headers'] ?? null) === ['X-TRACKING-OFF' => '1']
        && ($request->data()['smtpAccount'] ?? null) === '3.shop.smtp');
});

it('returns accepted and rejected recipients of a 207 response', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response([
        ...TestCase::sendResponse(),
        'errors' => [['title' => 'ValidationError', 'message' => 'Field to.1.email is invalid', 'code' => 'E-01-01']],
    ], 207)]);

    $result = EmailLabs::send(orderMessage()->to('broken@'));

    expect($result->httpStatus)->toBe(207)
        ->and($result->isPartial())->toBeTrue()
        ->and($result->messages)->toHaveCount(1)
        ->and($result->errors[0]['code'])->toBe('E-01-01');
});

it('validates messages before sending', function (EmailLabsMessage $message, string $field): void {
    Http::fake();

    expect(fn() => EmailLabs::send($message))->toThrow(InvalidMessage::class, $field);

    Http::assertNothingSent();
})->with([
    'no recipient' => [EmailLabsMessage::create('Hi')->from('a@example.com')->text('x'), '"to"'],
    'no sender' => [EmailLabsMessage::create('Hi')->to('b@example.com')->text('x'), '"from"'],
    'no subject' => [EmailLabsMessage::create()->from('a@example.com')->to('b@example.com')->text('x'), '"subject"'],
    'no content' => [EmailLabsMessage::create('Hi')->from('a@example.com')->to('b@example.com'), 'content'],
]);

it('rejects more than 200 recipients', function (): void {
    $message = EmailLabsMessage::create('Hi')->from('a@example.com')->text('x');

    foreach (range(1, 201) as $i) {
        $message->to("user{$i}@example.com");
    }

    expect(fn() => $message->toPayload('1.shop.smtp'))->toThrow(InvalidMessage::class, '201');
});

it('maps API errors to ApiException with code, title and request id', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(file_get_contents(__DIR__ . '/../Fixtures/error-401-unknown-token.json'), 401)]);

    try {
        EmailLabs::send(orderMessage());
        $this->fail('Expected an exception.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('E-0-003')
            ->and($exception->errorTitle())->toBe('Unknown token')
            ->and($exception->httpStatus())->toBe(401)
            ->and($exception->uniqId())->toBe('8dafda17da')
            ->and($exception->isAuthenticationError())->toBeTrue()
            ->and($exception->getMessage())->toContain('Not found any user with this token');
    }
});

it('never retries a send request', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::sequence()->push(['errors' => [['title' => 'Too many', 'message' => 'Rate limit exceeded', 'code' => 'E-API-4-001']]], 429, ['Retry-After' => '7'])
        ->push(TestCase::sendResponse())]);

    try {
        EmailLabs::send(orderMessage());
        $this->fail('Expected an exception.');
    } catch (ApiException $exception) {
        expect($exception->isRateLimited())->toBeTrue()->and($exception->retryAfter())->toBe(7);
    }

    Http::assertSentCount(1);
});

it('does not retry a send that hit a connection error', function (): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('timeout');
    });

    expect(fn() => EmailLabs::send(orderMessage()))->toThrow(ApiException::class, 'Could not connect')
        ->and($attempts)->toBe(1);
});

it('retries reads on server errors and rate limits', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::sequence()
        ->push('', 503)
        ->push(['errors' => [['title' => 'Rate', 'message' => 'Rate', 'code' => 'E-API-4-001']]], 429, ['Retry-After' => '0'])
        ->push(['meta' => ['status' => 200], 'data' => [['smtpAccount' => '1.shop.smtp', 'smtpName' => 'smtp2-13', 'smtpType' => 'Dedicated', 'vps' => [['vpsName' => 'smtp2-13', 'host' => 'smtp2-13.emaillabs.net.pl', 'vpsType' => 'dedicated', 'ip' => '1.2.3.4']]]]])]);
    config()->set('emaillabs.retries', 2);
    EmailLabs::purge();

    $accounts = EmailLabs::smtpAccounts();

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->account)->toBe('1.shop.smtp')
        ->and($accounts[0]->servers[0]['host'])->toBe('smtp2-13.emaillabs.net.pl');
    Http::assertSentCount(3);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'OPTIONS' && $request->url() === 'https://api.emaillabs.io/v2.1/email/smtpAccount');
});

it('lists e-mail statuses with formatted filters', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(['meta' => ['status' => 200], 'data' => [[
        'subject' => 'Order shipped',
        'smtpAccount' => '1.shop.smtp',
        'tags' => ['orders'],
        'to' => ['email' => 'jane@example.com', 'name' => 'Jane', 'messageId' => 'm1@example.com'],
        'from' => ['email' => 'shop@example.com', 'name' => 'Shop'],
        'status' => 'deffered',
        'statusTime' => 1580996271,
        'statusDesc' => 'try again later',
        'allStatuses' => [['status' => 'injected', 'statusTime' => 1580996200, 'statusDesc' => 'injected']],
    ]]])]);

    $emails = EmailLabs::emails([
        'to' => 'jane@example.com',
        'tags' => ['orders', 'pl'],
        'dateFrom' => CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'),
        'limit' => 50,
    ]);

    expect($emails[0]->status)->toBe(EmailStatus::Deferred)
        ->and($emails[0]->messageId)->toBe('m1@example.com')
        ->and($emails[0]->statusTime?->timestamp)->toBe(1580996271)
        ->and($emails[0]->history[0]->status)->toBe(EmailStatus::Injected)
        ->and($emails[0]->hasTag('orders'))->toBeTrue();

    Http::assertSent(fn(Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://api.emaillabs.io/v2.1/email?')
        && (queryOf($request)['tags'] ?? null) === 'orders,pl'
        && (queryOf($request)['dateFrom'] ?? null) === '2026-11-01 11:00:00'
        && (queryOf($request)['limit'] ?? null) === '50');
});

it('treats 404 of list endpoints as an empty result', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(['meta' => ['status' => 404], 'errors' => [['title' => 'Not found', 'message' => 'No results', 'code' => 'E-0-404']]], 404)]);

    expect(EmailLabs::emails())->toBe([])
        ->and(EmailLabs::email('missing@example.com'))->toBeNull();
});

it('pages through all statuses lazily', function (): void {
    $item = fn(int $i): array => ['to' => ['email' => "u{$i}@example.com", 'messageId' => "m{$i}"], 'status' => 'ok'];
    Http::fake(['api.emaillabs.io/*' => Http::sequence()
        ->push(['data' => [$item(1), $item(2)]])
        ->push(['data' => [$item(3)]])]);

    $all = EmailLabs::allEmails(['smtpAccount' => '1.shop.smtp'], 2)->map(fn($event) => $event->messageId)->all();

    expect($all)->toBe(['m1', 'm2', 'm3']);
    Http::assertSent(fn(Request $request): bool => (queryOf($request)['offset'] ?? null) === '2' && (queryOf($request)['limit'] ?? null) === '2');
});

it('reads opens and clicks', function (): void {
    Http::fake([
        'api.emaillabs.io/v2.1/email/open*' => Http::response(['data' => [['messageId' => 'm1', 'date' => '2026-11-10 08:39:01', 'to' => 'jane@example.com', 'smtpAccount' => '1.shop.smtp', 'ip' => '1.1.1.1', 'browser' => 'Chrome', 'os' => 'Windows', 'userAgent' => 'Mozilla']]]),
        'api.emaillabs.io/v2.1/email/click*' => Http::response(['data' => [['messageId' => 'm1', 'date' => '2026-11-10 08:40:00', 'to' => 'jane@example.com', 'link' => 'https://example.com/order']]]),
    ]);

    $opens = EmailLabs::opens(['messageId' => 'm1']);
    $clicks = EmailLabs::clicks(['messageId' => 'm1']);

    expect($opens[0]->browser)->toBe('Chrome')
        ->and($opens[0]->date?->format('Y-m-d H:i'))->toBe('2026-11-10 08:39')
        ->and($opens[0]->date?->timezoneName)->toBe('Europe/Warsaw')
        ->and($opens[0]->isClick())->toBeFalse()
        ->and($clicks[0]->link)->toBe('https://example.com/order')
        ->and($clicks[0]->isClick())->toBeTrue();
});

it('reads aggregated statistics with timestamps', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(['data' => ['2026-11-01' => ['13' => ['injected' => 6, 'ok' => 6]]]])]);

    $stats = EmailLabs::statistics(CarbonImmutable::createFromTimestamp(1762000000), CarbonImmutable::createFromTimestamp(1762086400), true);

    expect($stats['2026-11-01']['13']['ok'])->toBe(6);
    Http::assertSent(fn(Request $request): bool => str_contains($request->url(), '/v2.1/email/aggregate?')
        && (queryOf($request)['dateFrom'] ?? null) === '1762000000'
        && (queryOf($request)['dateTo'] ?? null) === '1762086400'
        && (queryOf($request)['withTags'] ?? null) === '1'
        && (queryOf($request)['smtpAccount'] ?? null) === '1.shop.smtp');
});

it('manages templates', function (): void {
    Http::fake([
        'api.emaillabs.io/v2.1/email/template?*' => Http::response(['data' => [['externalId' => 'welcome', 'name' => 'Welcome', 'html' => '<p>Hi</p>', 'text' => 'Hi', 'smtpAccount' => '1.shop.smtp', 'createdAt' => '2026-11-10 10:00:00']]]),
        'api.emaillabs.io/v2.1/email/template' => Http::response(['meta' => ['status' => 200]]),
    ]);

    $id = EmailLabs::addTemplate('Welcome', '<p>Hi {{ name }}</p>', 'Hi {{ name }}', 'welcome');
    $templates = EmailLabs::templates();
    EmailLabs::deleteTemplates('welcome', 'old');

    expect($id)->toBe('welcome')
        ->and($templates[0]->externalId)->toBe('welcome')
        ->and($templates[0]->createdAt?->year)->toBe(2026);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && ($request->data()['externalId'] ?? null) === 'welcome'
        && ($request->data()['name'] ?? null) === 'Welcome'
        && ($request->data()['smtpAccount'] ?? null) === '1.shop.smtp');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE'
        && ($request->data()['externalId'] ?? null) === ['welcome', 'old']);
});

it('generates a template id when none is given', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(['meta' => ['status' => 200]])]);

    $id = EmailLabs::addTemplate('Welcome', '<p>Hi</p>', 'Hi');

    expect($id)->toMatch('/^[a-z0-9]{16}$/');
});

it('manages the blacklist', function (): void {
    $entry = ['email' => 'jane@example.com', 'smtpAccount' => '1.shop.smtp', 'reason' => 'unsubscribe', 'comment' => 'GDPR', 'counter' => '2', 'createdAt' => 1762000000, 'updatedAt' => null];
    Http::fake([
        'api.emaillabs.io/v2.1/email/blacklist/reason' => Http::response(['data' => ['hardbounce', 'unsubscribe', 'spam_complaint', 'other']]),
        'api.emaillabs.io/v2.1/email/blacklist?*' => Http::response(['data' => [$entry]]),
        'api.emaillabs.io/v2.1/email/blacklist' => Http::response(['data' => [$entry]]),
        'api.emaillabs.io/v2.1/email/blacklist/*' => Http::response(['meta' => ['status' => 200]]),
    ]);

    $added = EmailLabs::addToBlacklist('jane@example.com', BlacklistReason::Unsubscribe, 'GDPR');
    $list = EmailLabs::blacklist();
    EmailLabs::removeFromBlacklist('jane@example.com');

    expect($added?->reason)->toBe(BlacklistReason::Unsubscribe)
        ->and($list[0]->counter)->toBe(2)
        ->and($list[0]->createdAt?->timestamp)->toBe(1762000000)
        ->and(EmailLabs::isBlacklisted('JANE@example.com'))->toBeTrue()
        ->and(EmailLabs::isBlacklisted('john@example.com'))->toBeFalse()
        ->and(EmailLabs::blacklistReasons())->toContain('spam_complaint');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['email' => 'jane@example.com', 'smtpAccount' => '1.shop.smtp', 'reason' => 'unsubscribe', 'comment' => 'GDPR']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.emaillabs.io/v2.1/email/blacklist/1.shop.smtp'
        && $request->data() === ['id' => ['jane@example.com']]);
});

it('manages blacklisted domains', function (): void {
    Http::fake(['api.emaillabs.io/v2.1/email/domain/blacklist/*' => Http::response(['data' => ['spam.example']])]);

    expect(EmailLabs::blacklistedDomains())->toBe(['spam.example']);
    EmailLabs::addDomainToBlacklist('spam.example');
    EmailLabs::removeDomainsFromBlacklist(['spam.example']);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && $request->data() === ['domain' => 'spam.example']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && $request->data() === ['id' => ['spam.example']]);
});

it('requires an SMTP account for account scoped calls', function (): void {
    $client = EmailLabs::using(['app_key' => 'a', 'secret_key' => 'b']);

    expect(fn() => $client->blacklist())->toThrow(InvalidConfiguration::class, 'EMAILLABS_SMTP_ACCOUNT');
});

it('exposes a generic request helper and the service status', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(['data' => [['status' => 'ok']]])]);

    expect(EmailLabs::serviceStatus())->toBe([['status' => 'ok']])
        ->and(EmailLabs::request('GET', 'email/open', ['limit' => 5])['data'])->toBe([['status' => 'ok']]);

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://api.emaillabs.io/v2.1/service/status');
    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://api.emaillabs.io/v2.1/email/open?limit=5');
});

it('raises malformed response errors', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response('<html>', 200)]);

    expect(fn() => EmailLabs::smtpAccounts())->toThrow(ApiException::class, 'not a valid JSON');
});
