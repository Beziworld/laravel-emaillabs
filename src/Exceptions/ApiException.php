<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Exceptions;

use Throwable;

/**
 * Raised when EmailLabs rejects a request or cannot be reached.
 *
 * The first error of the response envelope is exposed through errorCode()
 * (for example "E-0-003" for an unknown token), errorTitle() and
 * getMessage(); all errors through errors(). uniqId() returns the request
 * identifier EmailLabs support asks for.
 */
final class ApiException extends EmailLabsException
{
    /**
     * @param list<array<array-key, mixed>> $errors
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        string $message,
        private readonly ?string $errorCode = null,
        private readonly ?string $errorTitle = null,
        private readonly ?int $httpStatus = null,
        private readonly ?string $uniqId = null,
        private readonly array $errors = [],
        private readonly array $payload = [],
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromResponse(array $payload, int $httpStatus, ?int $retryAfter = null): self
    {
        $errors = [];

        foreach (is_array($payload['errors'] ?? null) ? $payload['errors'] : [] as $error) {
            if (is_array($error)) {
                $errors[] = $error;
            }
        }

        $first = $errors[0] ?? [];
        $code = isset($first['code']) && is_string($first['code']) ? $first['code'] : null;
        $title = isset($first['title']) && is_string($first['title']) ? $first['title'] : null;
        $detail = isset($first['message']) && is_string($first['message']) && $first['message'] !== ''
            ? $first['message']
            : ($title ?? sprintf('request failed with HTTP status %d', $httpStatus));
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $uniqId = isset($meta['uniqId']) && is_string($meta['uniqId']) ? $meta['uniqId'] : null;

        return new self(
            sprintf('EmailLabs error%s: %s', $code !== null ? " {$code}" : '', $detail),
            $code,
            $title,
            $httpStatus,
            $uniqId,
            $errors,
            $payload,
            $retryAfter,
        );
    }

    public static function transport(Throwable $previous): self
    {
        return new self('Could not connect to EmailLabs: ' . $previous->getMessage(), previous: $previous);
    }

    public static function malformedResponse(int $httpStatus): self
    {
        return new self('EmailLabs returned a response that is not a valid JSON object.', httpStatus: $httpStatus);
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorTitle(): ?string
    {
        return $this->errorTitle;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function uniqId(): ?string
    {
        return $this->uniqId;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Seconds to wait before the next attempt, from the Retry-After header of a 429 response.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }

    public function isAuthenticationError(): bool
    {
        return $this->httpStatus === 401 || $this->httpStatus === 403;
    }

    public function isNotFound(): bool
    {
        return $this->httpStatus === 404;
    }

    public function isValidationError(): bool
    {
        return $this->httpStatus === 400 || $this->httpStatus === 422;
    }
}
