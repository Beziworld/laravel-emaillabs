<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Events;

use Beziworld\EmailLabs\Data\SendResult;
use Beziworld\EmailLabs\Messages\EmailLabsMessage;

/**
 * Dispatched after EmailLabs accepted a send request (fully or, with HTTP 207, partially).
 */
final readonly class EmailSent
{
    public function __construct(
        public SendResult $result,
        public EmailLabsMessage $message,
        public string $connection,
    ) {}
}
