<?php

declare(strict_types=1);

use Beziworld\EmailLabs\Data\Address;
use Beziworld\EmailLabs\Data\BlacklistEntry;
use Beziworld\EmailLabs\Data\EmailEvent;
use Beziworld\EmailLabs\Data\SendResult;
use Beziworld\EmailLabs\Enums\EmailStatus;
use Beziworld\EmailLabs\Security\WebhookSignature;
use Beziworld\EmailLabs\Support\Payload;

it('computes the documented webhook checksum', function (): void {
    $signature = new WebhookSignature('secret');

    expect($signature->compute('2026-11-10', 'abc'))->toBe(sha1('secret|2026-11-10|abc'))
        ->and($signature->verify(strtoupper(sha1('secret|2026-11-10|abc')), '2026-11-10', 'abc'))->toBeTrue()
        ->and($signature->verify('', '2026-11-10', 'abc'))->toBeFalse()
        ->and($signature->verify(sha1('other|2026-11-10|abc'), '2026-11-10', 'abc'))->toBeFalse();
});

it('normalises status values', function (): void {
    expect(EmailStatus::fromValue('deffered'))->toBe(EmailStatus::Deferred)
        ->and(EmailStatus::fromValue('OK'))->toBe(EmailStatus::Ok)
        ->and(EmailStatus::fromValue('unknown'))->toBeNull()
        ->and(EmailStatus::fromValue(null))->toBeNull()
        ->and(EmailStatus::Softbounce->isBounce())->toBeTrue()
        ->and(EmailStatus::Softbounce->isPermanentFailure())->toBeFalse()
        ->and(EmailStatus::Dropped->isPermanentFailure())->toBeTrue()
        ->and(EmailStatus::Click->isEngagement())->toBeTrue()
        ->and(EmailStatus::Ok->isDelivered())->toBeTrue();
});

it('keeps unknown statuses as raw values', function (): void {
    $event = EmailEvent::fromArray(['to' => ['email' => 'a@example.com'], 'status' => 'feedbackloop', 'tags' => ['x', '', 5]]);

    expect($event->status)->toBeNull()
        ->and($event->statusValue)->toBe('feedbackloop')
        ->and($event->tags)->toBe(['x', '5'])
        ->and($event->from)->toBeNull()
        ->and($event->raw['status'])->toBe('feedbackloop');
});

it('reads dates in several formats', function (): void {
    $payload = Payload::of(['ts' => 1762000000, 'str' => '1762000000', 'iso' => '2026-11-10T10:00:00Z', 'local' => '2026-11-10 10:00:00', 'bad' => 'not a date']);

    expect($payload->date('ts')?->timestamp)->toBe(1762000000)
        ->and($payload->date('str')?->timestamp)->toBe(1762000000)
        ->and($payload->date('iso')?->utc()->format('H:i'))->toBe('10:00')
        ->and($payload->date('local')?->utc()->format('H:i'))->toBe('09:00')
        ->and($payload->date('bad'))->toBeNull()
        ->and($payload->date('missing'))->toBeNull();
});

it('serialises addresses without empty names', function (): void {
    expect((new Address('a@example.com'))->toArray())->toBe(['email' => 'a@example.com'])
        ->and((new Address('a@example.com', 'A'))->toArray())->toBe(['email' => 'a@example.com', 'name' => 'A']);
});

it('keeps unknown blacklist reasons', function (): void {
    $entry = BlacklistEntry::fromArray(['email' => 'a@example.com', 'reason' => 'complaint_fbl']);

    expect($entry->reason)->toBeNull()->and($entry->reasonValue)->toBe('complaint_fbl')->and($entry->counter)->toBe(0);
});

it('lists message ids of a send result', function (): void {
    $result = SendResult::fromArray(['data' => [['to' => ['email' => 'a@example.com', 'messageId' => 'm1']], ['to' => ['email' => 'b@example.com']]]]);

    expect($result->messageIds())->toBe(['m1'])
        ->and($result->messageIdFor('b@example.com'))->toBeNull()
        ->and($result->messageIdFor('c@example.com'))->toBeNull();
});
