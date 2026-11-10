<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Exceptions;

/**
 * Raised when a message cannot be sent because it is incomplete or exceeds an EmailLabs limit.
 */
final class InvalidMessage extends EmailLabsException
{
    public static function missing(string $field): self
    {
        return new self(sprintf('The e-mail cannot be sent without "%s".', $field));
    }

    public static function tooManyRecipients(int $count): self
    {
        return new self(sprintf('EmailLabs accepts at most 200 recipients per request, %d given.', $count));
    }

    public static function invalidVariables(string $header): self
    {
        return new self(sprintf('The %s header must contain a JSON object.', $header));
    }
}
