<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Events;

use Beziworld\EmailLabs\Data\EmailEvent;

/**
 * Dispatched when EmailLabs did not send a message, for example because the recipient is blacklisted.
 */
final readonly class EmailDropped
{
    public function __construct(
        public EmailEvent $event,
        public string $connection,
    ) {}
}
