<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Data;

use Beziworld\EmailLabs\Enums\BlacklistReason;
use Beziworld\EmailLabs\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * An address on the blacklist (suppression list) of an SMTP account.
 */
final readonly class BlacklistEntry
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $email,
        public ?string $smtpAccount,
        public ?BlacklistReason $reason,
        public ?string $reasonValue,
        public ?string $comment,
        public int $counter,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $updatedAt,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $timezone = 'Europe/Warsaw'): self
    {
        $payload = Payload::of($data);
        $reason = $payload->string('reason');

        return new self(
            email: $payload->string('email') ?? '',
            smtpAccount: $payload->string('smtpAccount'),
            reason: $reason !== null ? BlacklistReason::tryFrom($reason) : null,
            reasonValue: $reason,
            comment: $payload->string('comment'),
            counter: $payload->int('counter') ?? 0,
            createdAt: $payload->date('createdAt', $timezone),
            updatedAt: $payload->date('updatedAt', $timezone),
            raw: $data,
        );
    }
}
