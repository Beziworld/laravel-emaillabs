<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Events;

use Beziworld\EmailLabs\Data\EmailEvent;

/**
 * Dispatched when a message bounced (softbounce, hardbounce or spambounce).
 */
final readonly class EmailBounced
{
    public function __construct(
        public EmailEvent $event,
        public string $connection,
    ) {}
}
