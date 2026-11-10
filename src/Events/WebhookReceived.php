<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Events;

use Beziworld\EmailLabs\Data\EmailEvent;

/**
 * Dispatched once per verified webhook request, before the per-event notifications.
 *
 * "payload" holds every item of the request as received (including SMS,
 * push or campaign events), "events" the items recognised as transactional
 * e-mail events.
 */
final readonly class WebhookReceived
{
    /**
     * @param list<EmailEvent> $events
     * @param list<mixed> $payload
     */
    public function __construct(
        public array $events,
        public array $payload,
        public string $connection,
        public ?string $requestId = null,
    ) {}
}
