<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Support;

use Carbon\CarbonImmutable;
use Exception;

/**
 * Typed accessors over decoded EmailLabs payloads.
 *
 * EmailLabs returns counters and timestamps either as numbers or as numeric
 * strings, so numeric getters accept both.
 */
final readonly class Payload
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function of(array $data): self
    {
        return new self($data);
    }

    public function string(string $key): ?string
    {
        $item = $this->data[$key] ?? null;

        if (is_int($item) || is_float($item)) {
            return (string) $item;
        }

        return is_string($item) && $item !== '' ? $item : null;
    }

    public function int(string $key): ?int
    {
        $item = $this->data[$key] ?? null;

        return is_numeric($item) ? (int) $item : null;
    }

    /**
     * Parses a Unix timestamp, an RFC 3339 string or a "Y-m-d H:i:s" string in the given time zone.
     */
    public function date(string $key, string $timezone = 'Europe/Warsaw'): ?CarbonImmutable
    {
        $item = $this->data[$key] ?? null;

        if (is_int($item) || is_float($item) || (is_string($item) && ctype_digit($item))) {
            return CarbonImmutable::createFromTimestamp((int) $item, $timezone);
        }

        if (! is_string($item) || $item === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($item, $timezone);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $item = $this->data[$key] ?? null;

        return is_array($item) ? $item : [];
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $strings = [];

        foreach ($this->array($key) as $item) {
            if ((is_string($item) || is_int($item)) && trim((string) $item) !== '') {
                $strings[] = trim((string) $item);
            }
        }

        return $strings;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
