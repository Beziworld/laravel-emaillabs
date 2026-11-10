<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Events;

use Beziworld\EmailLabs\Data\EmailEvent;

/**
 * Dispatched when a message was delivered (status ok).
 */
final readonly class EmailDelivered
{
    public function __construct(
        public EmailEvent $event,
        public string $connection,
    ) {}
}
