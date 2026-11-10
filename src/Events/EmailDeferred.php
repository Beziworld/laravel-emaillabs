<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Events;

use Beziworld\EmailLabs\Data\EmailEvent;

/**
 * Dispatched when the delivery of a message was postponed.
 */
final readonly class EmailDeferred
{
    public function __construct(
        public EmailEvent $event,
        public string $connection,
    ) {}
}
