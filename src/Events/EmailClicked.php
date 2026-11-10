<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Events;

use Beziworld\EmailLabs\Data\EmailEvent;

/**
 * Dispatched when a recipient clicked a tracked link (link tracking enabled).
 */
final readonly class EmailClicked
{
    public function __construct(
        public EmailEvent $event,
        public string $connection,
    ) {}
}
