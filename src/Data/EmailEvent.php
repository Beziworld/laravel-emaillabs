<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Data;

use Beziworld\EmailLabs\Enums\EmailStatus;
use Beziworld\EmailLabs\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * State of one transactional message for one recipient.
 *
 * The same structure is returned by the send and status list endpoints and
 * pushed by the transactional e-mail webhook. "status" is the latest status,
 * "history" every status received so far.
 */
final readonly class EmailEvent
{
    /**
     * @param list<string> $tags
     * @param list<StatusEntry> $history
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public Address $to,
        public ?string $messageId,
        public ?Address $from,
        public ?string $subject,
        public ?string $smtpAccount,
        public array $tags,
        public ?EmailStatus $status,
        public ?string $statusValue,
        public ?CarbonImmutable $statusTime,
        public ?string $statusDescription,
        public array $history,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $timezone = 'Europe/Warsaw'): self
    {
        $payload = Payload::of($data);
        $to = $payload->array('to');
        $from = $payload->array('from');
        $history = [];

        foreach ($payload->array('allStatuses') as $entry) {
            if (is_array($entry)) {
                $history[] = StatusEntry::fromArray($entry, $timezone);
            }
        }

        $value = $payload->string('status');

        return new self(
            to: Address::fromArray($to),
            messageId: Payload::of($to)->string('messageId') ?? $payload->string('messageId'),
            from: $from !== [] ? Address::fromArray($from) : null,
            subject: $payload->string('subject'),
            smtpAccount: $payload->string('smtpAccount'),
            tags: $payload->strings('tags'),
            status: EmailStatus::fromValue($value),
            statusValue: $value,
            statusTime: $payload->date('statusTime', $timezone),
            statusDescription: $payload->string('statusDesc'),
            history: $history,
            raw: $data,
        );
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }
}
