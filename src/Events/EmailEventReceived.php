<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Events;

use Beziworld\EmailLabs\Data\EmailEvent;

/**
 * Dispatched for every transactional e-mail event received by the webhook, whatever its status.
 */
final readonly class EmailEventReceived
{
    public function __construct(
        public EmailEvent $event,
        public string $connection,
    ) {}
}
