<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Data;

use Beziworld\EmailLabs\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * An open or a click of a tracked link, as returned by the opens and clicks endpoints.
 */
final readonly class Engagement
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?string $messageId,
        public ?string $to,
        public ?CarbonImmutable $date,
        public ?string $smtpAccount,
        public ?string $ip,
        public ?string $browser,
        public ?string $os,
        public ?string $userAgent,
        public ?string $link,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $timezone = 'Europe/Warsaw'): self
    {
        $payload = Payload::of($data);

        return new self(
            messageId: $payload->string('messageId'),
            to: $payload->string('to'),
            date: $payload->date('date', $timezone),
            smtpAccount: $payload->string('smtpAccount'),
            ip: $payload->string('ip'),
            browser: $payload->string('browser'),
            os: $payload->string('os'),
            userAgent: $payload->string('userAgent'),
            link: $payload->string('link'),
            raw: $data,
        );
    }

    public function isClick(): bool
    {
        return $this->link !== null;
    }
}
