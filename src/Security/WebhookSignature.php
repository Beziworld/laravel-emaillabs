<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Security;

/**
 * Checksum of EmailLabs webhook requests.
 *
 * EmailLabs sends the headers X-Webhook-Date, Request-Id and
 * X-Webhook-Checksum, where the checksum is SHA1 of
 * "{secret}|{X-Webhook-Date}|{Request-Id}" and the secret is generated in
 * the panel (Account, Settings, Webhooks).
 */
final readonly class WebhookSignature
{
    public function __construct(
        private string $secret,
    ) {}

    public function compute(string $date, string $requestId): string
    {
        return sha1($this->secret . '|' . $date . '|' . $requestId);
    }

    /**
     * Compares in constant time; the hexadecimal checksum is accepted in any letter case.
     */
    public function verify(string $checksum, string $date, string $requestId): bool
    {
        return $checksum !== '' && hash_equals($this->compute($date, $requestId), strtolower(trim($checksum)));
    }
}
