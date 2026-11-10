<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Enums;

/**
 * Reasons accepted when adding an address to the EmailLabs blacklist.
 */
enum BlacklistReason: string
{
    case Hardbounce = 'hardbounce';
    case Unsubscribe = 'unsubscribe';
    case SpamComplaint = 'spam_complaint';
    case Other = 'other';
}
