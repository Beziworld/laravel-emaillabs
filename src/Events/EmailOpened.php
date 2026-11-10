<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Events;

use Beziworld\EmailLabs\Data\EmailEvent;

/**
 * Dispatched when a recipient opened a message (open tracking enabled).
 */
final readonly class EmailOpened
{
    public function __construct(
        public EmailEvent $event,
        public string $connection,
    ) {}
}
