<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Data;

use Beziworld\EmailLabs\Support\Payload;

/**
 * Response of the send endpoint.
 *
 * EmailLabs answers HTTP 207 when some recipients were accepted and others
 * rejected; accepted recipients are listed in "messages" and the rejections
 * in "errors".
 */
final readonly class SendResult
{
    /**
     * @param list<EmailEvent> $messages
     * @param list<array<array-key, mixed>> $errors
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public array $messages,
        public array $errors,
        public ?string $uniqId,
        public int $httpStatus,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, int $httpStatus = 200, string $timezone = 'Europe/Warsaw'): self
    {
        $payload = Payload::of($data);
        $messages = [];
        $errors = [];

        foreach ($payload->array('data') as $item) {
            if (is_array($item)) {
                $messages[] = EmailEvent::fromArray($item, $timezone);
            }
        }

        foreach ($payload->array('errors') as $error) {
            if (is_array($error)) {
                $errors[] = $error;
            }
        }

        return new self($messages, $errors, Payload::of($payload->array('meta'))->string('uniqId'), $httpStatus, $data);
    }

    public function isPartial(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Message-ID assigned to the given recipient, when EmailLabs returned one.
     */
    public function messageIdFor(string $email): ?string
    {
        foreach ($this->messages as $message) {
            if (strcasecmp($message->to->email, $email) === 0) {
                return $message->messageId;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function messageIds(): array
    {
        $ids = [];

        foreach ($this->messages as $message) {
            if ($message->messageId !== null && $message->messageId !== '') {
                $ids[] = $message->messageId;
            }
        }

        return $ids;
    }
}
