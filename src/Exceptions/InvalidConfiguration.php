<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Exceptions;

/**
 * Raised when a connection is missing or incomplete.
 */
final class InvalidConfiguration extends EmailLabsException
{
    public static function missing(string $key, string $env): self
    {
        return new self(sprintf('The EmailLabs option "%s" is not configured (%s).', $key, $env));
    }

    public static function unknownConnection(string $name): self
    {
        return new self(sprintf('EmailLabs connection [%s] is not defined.', $name));
    }

    public static function missingSmtpAccount(): self
    {
        return new self('No EmailLabs SMTP account given and no default configured (EMAILLABS_SMTP_ACCOUNT).');
    }
}
