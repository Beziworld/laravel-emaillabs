<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Http\Controllers;

use Beziworld\EmailLabs\Data\EmailEvent;
use Beziworld\EmailLabs\EmailLabsManager;
use Beziworld\EmailLabs\Enums\EmailStatus;
use Beziworld\EmailLabs\Events\EmailBounced;
use Beziworld\EmailLabs\Events\EmailClicked;
use Beziworld\EmailLabs\Events\EmailDeferred;
use Beziworld\EmailLabs\Events\EmailDelivered;
use Beziworld\EmailLabs\Events\EmailDropped;
use Beziworld\EmailLabs\Events\EmailEventReceived;
use Beziworld\EmailLabs\Events\EmailOpened;
use Beziworld\EmailLabs\Events\WebhookReceived;
use Beziworld\EmailLabs\Http\Middleware\VerifyEmailLabsWebhook;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives EmailLabs webhooks and dispatches them as Laravel events.
 *
 * The body is a JSON array of events. Transactional e-mail events become
 * EmailEventReceived plus a status specific event (EmailDelivered,
 * EmailBounced, EmailDropped, EmailDeferred, EmailOpened, EmailClicked);
 * every request also dispatches WebhookReceived with the raw items. The
 * response is always "ok" with HTTP 200, as EmailLabs requires, so keep
 * listeners fast or queued (EmailLabs waits 500 ms).
 */
final class WebhookController
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly EmailLabsManager $manager,
    ) {}

    public function __invoke(Request $request): Response
    {
        $connection = $request->attributes->get(VerifyEmailLabsWebhook::ATTRIBUTE);
        $connection = is_string($connection) ? $connection : $this->manager->getDefaultConnection();
        $payload = self::items($request->getContent());
        $timezone = $this->timezone($connection);
        $events = [];

        foreach ($payload as $item) {
            if (is_array($item) && self::isEmailEvent($item)) {
                $events[] = EmailEvent::fromArray($item, $timezone);
            }
        }

        $requestId = $request->header('Request-Id');
        $this->events->dispatch(new WebhookReceived($events, $payload, $connection, is_string($requestId) ? $requestId : null));

        foreach ($events as $event) {
            $this->events->dispatch(new EmailEventReceived($event, $connection));

            $specific = match ($event->status) {
                EmailStatus::Ok => new EmailDelivered($event, $connection),
                EmailStatus::Softbounce, EmailStatus::Hardbounce, EmailStatus::Spambounce => new EmailBounced($event, $connection),
                EmailStatus::Dropped => new EmailDropped($event, $connection),
                EmailStatus::Deferred => new EmailDeferred($event, $connection),
                EmailStatus::Open => new EmailOpened($event, $connection),
                EmailStatus::Click => new EmailClicked($event, $connection),
                default => null,
            };

            if ($specific !== null) {
                $this->events->dispatch($specific);
            }
        }

        return new Response('ok', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @return list<mixed>
     */
    private static function items(string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_is_list($decoded) ? $decoded : [$decoded];
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private static function isEmailEvent(array $item): bool
    {
        return is_array($item['to'] ?? null) && isset($item['status']) && is_string($item['status'])
            && (array_key_exists('smtpAccount', $item) || array_key_exists('allStatuses', $item));
    }

    private function timezone(string $connection): string
    {
        try {
            return $this->manager->connection($connection)->config()->timezone;
        } catch (\Throwable) {
            return 'Europe/Warsaw';
        }
    }
}
