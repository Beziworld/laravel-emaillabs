<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Enums;

/**
 * Statuses of a transactional e-mail reported by EmailLabs (status lists and webhooks).
 */
enum EmailStatus: string
{
    case Injected = 'injected';
    case Ok = 'ok';
    case Deferred = 'deferred';
    case Softbounce = 'softbounce';
    case Hardbounce = 'hardbounce';
    case Spambounce = 'spambounce';
    case Dropped = 'dropped';
    case Open = 'open';
    case Click = 'click';

    /**
     * Normalises the spelling variants used by the API ("deffered", upper case).
     */
    public static function fromValue(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return self::tryFrom($value === 'deffered' ? 'deferred' : $value);
    }

    public function isDelivered(): bool
    {
        return $this === self::Ok;
    }

    public function isBounce(): bool
    {
        return in_array($this, [self::Softbounce, self::Hardbounce, self::Spambounce], true);
    }

    /**
     * Whether the message will not reach the recipient (permanent bounce, spam rejection or drop).
     */
    public function isPermanentFailure(): bool
    {
        return in_array($this, [self::Hardbounce, self::Spambounce, self::Dropped], true);
    }

    public function isEngagement(): bool
    {
        return $this === self::Open || $this === self::Click;
    }
}
