<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Data;

use Beziworld\EmailLabs\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * A stored e-mail template; its externalId is passed as templateId when sending.
 */
final readonly class Template
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?string $externalId,
        public string $name,
        public string $html,
        public string $text,
        public ?string $smtpAccount,
        public ?CarbonImmutable $createdAt,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $timezone = 'Europe/Warsaw'): self
    {
        $payload = Payload::of($data);

        return new self(
            externalId: $payload->string('externalId'),
            name: $payload->string('name') ?? '',
            html: $payload->string('html') ?? '',
            text: $payload->string('text') ?? '',
            smtpAccount: $payload->string('smtpAccount'),
            createdAt: $payload->date('createdAt', $timezone),
            raw: $data,
        );
    }
}
