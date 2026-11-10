<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Data;

use Beziworld\EmailLabs\Enums\EmailStatus;
use Beziworld\EmailLabs\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * One status in the history of a message ("allStatuses").
 */
final readonly class StatusEntry
{
    public function __construct(
        public ?EmailStatus $status,
        public string $statusValue,
        public ?CarbonImmutable $time,
        public ?string $description,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $timezone = 'Europe/Warsaw'): self
    {
        $payload = Payload::of($data);
        $value = $payload->string('status') ?? '';

        return new self(
            EmailStatus::fromValue($value),
            $value,
            $payload->date('statusTime', $timezone),
            $payload->string('statusDesc'),
        );
    }
}
