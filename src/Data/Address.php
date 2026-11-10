<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Data;

use Beziworld\EmailLabs\Support\Payload;

/**
 * An e-mail address with an optional display name.
 */
final readonly class Address
{
    public function __construct(
        public string $email,
        public ?string $name = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = Payload::of($data);

        return new self($payload->string('email') ?? '', $payload->string('name'));
    }

    /**
     * @return array{email: string, name?: string}
     */
    public function toArray(): array
    {
        return $this->name !== null && $this->name !== '' ? ['email' => $this->email, 'name' => $this->name] : ['email' => $this->email];
    }
}
