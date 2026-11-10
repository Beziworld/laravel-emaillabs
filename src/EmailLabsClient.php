<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs;

use Beziworld\EmailLabs\Data\BlacklistEntry;
use Beziworld\EmailLabs\Data\EmailEvent;
use Beziworld\EmailLabs\Data\Engagement;
use Beziworld\EmailLabs\Data\SendResult;
use Beziworld\EmailLabs\Data\SmtpAccount;
use Beziworld\EmailLabs\Data\Template;
use Beziworld\EmailLabs\Enums\BlacklistReason;
use Beziworld\EmailLabs\Events\EmailSent;
use Beziworld\EmailLabs\Exceptions\ApiException;
use Beziworld\EmailLabs\Exceptions\InvalidConfiguration;
use Beziworld\EmailLabs\Exceptions\InvalidMessage;
use Beziworld\EmailLabs\Messages\EmailLabsMessage;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Client of the EmailLabs REST API v2.1 for one panel.
 *
 * Requests are sent with the Laravel HTTP client, so Http::fake() works in
 * application tests. Reads (GET, OPTIONS) are retried on connection errors,
 * HTTP 5xx and HTTP 429; writes are never repeated automatically, because a
 * request that timed out may already have sent the e-mail.
 */
class EmailLabsClient
{
    public const string VERSION = 'v2.1';

    private const int MAX_RETRY_AFTER = 60;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly EmailLabsConfig $config,
        private readonly string $connection = 'default',
        private readonly ?Dispatcher $events = null,
    ) {}

    public function config(): EmailLabsConfig
    {
        return $this->config;
    }

    public function connectionName(): string
    {
        return $this->connection;
    }

    /**
     * Client bound to another SMTP account of the same panel.
     */
    public function forSmtpAccount(string $smtpAccount): self
    {
        return new self($this->http, $this->config->withSmtpAccount($smtpAccount), $this->connection, $this->events);
    }

    // Sending

    /**
     * Sends a transactional e-mail and dispatches the EmailSent event.
     *
     * HTTP 207 (some recipients rejected) is not an error: the result lists
     * the accepted messages and the rejections.
     *
     * @throws InvalidMessage When the message is incomplete.
     * @throws ApiException When EmailLabs rejects the whole request or cannot be reached.
     */
    public function send(EmailLabsMessage $message): SendResult
    {
        $payload = $message->toPayload($this->config->smtpAccount);
        $response = $this->perform('POST', $this->url('email'), body: $payload);
        $result = SendResult::fromArray($response['body'], $response['status'], $this->config->timezone);

        $this->events?->dispatch(new EmailSent($result, $message, $this->connection));

        return $result;
    }

    // Statuses and engagement

    /**
     * Statuses of sent messages (one item per recipient), filtered by smtpAccount, messageId, to, tags, dateFrom, dateTo, offset and limit.
     *
     * @param array<string, scalar|list<string>|DateTimeInterface|null> $filters
     * @return list<EmailEvent>
     */
    public function emails(array $filters = []): array
    {
        return array_map(
            fn(array $item): EmailEvent => EmailEvent::fromArray($item, $this->config->timezone),
            $this->list('email', $filters),
        );
    }

    /**
     * Every status page lazily, requesting $pageSize items at a time.
     *
     * @param array<string, scalar|list<string>|DateTimeInterface|null> $filters
     * @return LazyCollection<int, EmailEvent>
     */
    public function allEmails(array $filters = [], int $pageSize = 1000): LazyCollection
    {
        return $this->paginate(fn(array $page): array => $this->emails([...$filters, ...$page]), $pageSize);
    }

    /**
     * Current state of the message with the given Message-ID, or null when EmailLabs does not know it (yet).
     */
    public function email(string $messageId): ?EmailEvent
    {
        return $this->emails(['messageId' => $messageId, 'limit' => 1])[0] ?? null;
    }

    /**
     * Opens of tracked messages (kept for 7 days; dateFrom and dateTo at most 3 days apart).
     *
     * @param array<string, scalar|list<string>|DateTimeInterface|null> $filters
     * @return list<Engagement>
     */
    public function opens(array $filters = []): array
    {
        return array_map(
            fn(array $item): Engagement => Engagement::fromArray($item, $this->config->timezone),
            $this->list('email/open', $filters),
        );
    }

    /**
     * Clicks of tracked links (kept for 7 days; dateFrom and dateTo at most 3 days apart).
     *
     * @param array<string, scalar|list<string>|DateTimeInterface|null> $filters
     * @return list<Engagement>
     */
    public function clicks(array $filters = []): array
    {
        return array_map(
            fn(array $item): Engagement => Engagement::fromArray($item, $this->config->timezone),
            $this->list('email/click', $filters),
        );
    }

    /**
     * Aggregated sending statistics of an SMTP account, optionally split by tags.
     *
     * The structure of the returned data is passed through unchanged.
     *
     * @return array<array-key, mixed>
     */
    public function statistics(DateTimeInterface $from, ?DateTimeInterface $to = null, bool $withTags = false, ?string $smtpAccount = null): array
    {
        $query = [
            'smtpAccount' => $this->smtpAccount($smtpAccount),
            'dateFrom' => (string) $from->getTimestamp(),
            'withTags' => $withTags ? '1' : '0',
        ];

        if ($to !== null) {
            $query['dateTo'] = (string) $to->getTimestamp();
        }

        $body = $this->perform('GET', $this->url('email/aggregate'), query: $query, notFoundIsEmpty: true)['body'];

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    // Templates

    /**
     * @return list<Template>
     */
    public function templates(?string $smtpAccount = null, int $offset = 0, int $limit = 100): array
    {
        $filters = ['offset' => $offset, 'limit' => $limit];

        if (($smtpAccount ?? $this->config->smtpAccount) !== null) {
            $filters['smtpAccount'] = $smtpAccount ?? $this->config->smtpAccount;
        }

        return array_map(
            fn(array $item): Template => Template::fromArray($item, $this->config->timezone),
            $this->list('email/template', $filters),
        );
    }

    public function template(string $externalId): ?Template
    {
        $items = $this->list('email/template', ['externalId' => $externalId]);

        return isset($items[0]) ? Template::fromArray($items[0], $this->config->timezone) : null;
    }

    /**
     * Stores a template and returns its externalId (generated when none is given), used later as templateId.
     *
     * Templates support {{ variable }} placeholders, conditions and loops filled from vars and globalVars.
     */
    public function addTemplate(string $name, string $html, string $text, ?string $externalId = null, ?string $smtpAccount = null): string
    {
        $externalId ??= Str::lower(Str::random(16));

        $this->perform('POST', $this->url('email/template'), body: [
            'html' => $html,
            'text' => $text,
            'name' => $name,
            'externalId' => $externalId,
            'smtpAccount' => $this->smtpAccount($smtpAccount),
        ]);

        return $externalId;
    }

    public function deleteTemplates(string ...$externalIds): void
    {
        $this->perform('DELETE', $this->url('email/template'), body: ['externalId' => array_values($externalIds)]);
    }

    // Blacklist (suppression list)

    /**
     * @return list<BlacklistEntry>
     */
    public function blacklist(?string $smtpAccount = null, int $offset = 0, int $limit = 1000): array
    {
        return array_map(
            fn(array $item): BlacklistEntry => BlacklistEntry::fromArray($item, $this->config->timezone),
            $this->list('email/blacklist', ['smtpAccount' => $this->smtpAccount($smtpAccount), 'offset' => $offset, 'limit' => $limit]),
        );
    }

    /**
     * @return LazyCollection<int, BlacklistEntry>
     */
    public function allBlacklisted(?string $smtpAccount = null, int $pageSize = 1000): LazyCollection
    {
        return $this->paginate(
            fn(array $page): array => $this->blacklist($smtpAccount, $page['offset'], $page['limit']),
            $pageSize,
        );
    }

    /**
     * Whether the address is on the blacklist of the SMTP account (scans the whole list).
     */
    public function isBlacklisted(string $email, ?string $smtpAccount = null): bool
    {
        return $this->allBlacklisted($smtpAccount)->contains(
            static fn(BlacklistEntry $entry): bool => strcasecmp($entry->email, $email) === 0,
        );
    }

    /**
     * Adds an address to the blacklist; EmailLabs skips it in every later transactional sending.
     */
    public function addToBlacklist(string $email, BlacklistReason|string $reason = BlacklistReason::Other, ?string $comment = null, ?string $smtpAccount = null): ?BlacklistEntry
    {
        $body = [
            'email' => $email,
            'smtpAccount' => $this->smtpAccount($smtpAccount),
            'reason' => $reason instanceof BlacklistReason ? $reason->value : $reason,
        ];

        if ($comment !== null && $comment !== '') {
            $body['comment'] = $comment;
        }

        $data = $this->perform('POST', $this->url('email/blacklist'), body: $body)['body']['data'] ?? null;
        $first = is_array($data) ? ($data[0] ?? null) : null;

        return is_array($first) ? BlacklistEntry::fromArray($first, $this->config->timezone) : null;
    }

    /**
     * @param string|list<string> $emails
     */
    public function removeFromBlacklist(string|array $emails, ?string $smtpAccount = null): void
    {
        $this->perform('DELETE', $this->url('email/blacklist/' . rawurlencode($this->smtpAccount($smtpAccount))), body: [
            'id' => is_string($emails) ? [$emails] : $emails,
        ]);
    }

    /**
     * Reasons accepted by addToBlacklist().
     *
     * @return list<string>
     */
    public function blacklistReasons(): array
    {
        $data = $this->perform('OPTIONS', $this->url('email/blacklist/reason'))['body']['data'] ?? [];

        return array_values(array_filter(is_array($data) ? $data : [], 'is_string'));
    }

    /**
     * @return list<mixed>
     */
    public function blacklistedDomains(?string $smtpAccount = null): array
    {
        $body = $this->perform('GET', $this->url('email/domain/blacklist/' . rawurlencode($this->smtpAccount($smtpAccount))), notFoundIsEmpty: true)['body'];

        return is_array($body['data'] ?? null) ? array_values($body['data']) : [];
    }

    public function addDomainToBlacklist(string $domain, ?string $smtpAccount = null): void
    {
        $this->perform('POST', $this->url('email/domain/blacklist/' . rawurlencode($this->smtpAccount($smtpAccount))), body: ['domain' => $domain]);
    }

    /**
     * @param string|list<string> $domains
     */
    public function removeDomainsFromBlacklist(string|array $domains, ?string $smtpAccount = null): void
    {
        $this->perform('DELETE', $this->url('email/domain/blacklist/' . rawurlencode($this->smtpAccount($smtpAccount))), body: [
            'id' => is_string($domains) ? [$domains] : $domains,
        ]);
    }

    // Account

    /**
     * @return list<SmtpAccount>
     */
    public function smtpAccounts(): array
    {
        $data = $this->perform('OPTIONS', $this->url('email/smtpAccount'))['body']['data'] ?? [];
        $accounts = [];

        foreach (is_array($data) ? $data : [] as $item) {
            if (is_array($item)) {
                $accounts[] = SmtpAccount::fromArray($item);
            }
        }

        return $accounts;
    }

    /**
     * Service status (GET /v2.1/service/status); useful as an authenticated health check.
     *
     * @return array<array-key, mixed>
     */
    public function serviceStatus(): array
    {
        $data = $this->perform('GET', $this->url('service/status'))['body']['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * Sends any request authenticated with the connection keys and returns the decoded envelope.
     *
     * Relative URIs are resolved against {base_url}/v2.1/. The body of GET
     * and OPTIONS requests is sent as the query string.
     *
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    public function request(string $method, string $uri, array $data = []): array
    {
        $method = strtoupper($method);
        $url = str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://') ? $uri : $this->url(ltrim($uri, '/'));

        return in_array($method, ['GET', 'OPTIONS'], true)
            ? $this->perform($method, $url, query: self::query($data, $this->config->timezone))['body']
            : $this->perform($method, $url, body: $data)['body'];
    }

    /**
     * @param array<string, scalar|list<string>|DateTimeInterface|null> $filters
     * @return list<array<array-key, mixed>>
     */
    private function list(string $path, array $filters): array
    {
        $body = $this->perform('GET', $this->url($path), query: self::query($filters, $this->config->timezone), notFoundIsEmpty: true)['body'];
        $items = [];

        foreach (is_array($body['data'] ?? null) ? $body['data'] : [] as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @template TItem
     *
     * @param \Closure(array{offset: int, limit: int}): list<TItem> $fetch
     * @return LazyCollection<int, TItem>
     */
    private function paginate(\Closure $fetch, int $pageSize): LazyCollection
    {
        $pageSize = max(1, min(1000, $pageSize));

        return LazyCollection::make(function () use ($fetch, $pageSize) {
            $offset = 0;

            do {
                $items = $fetch(['offset' => $offset, 'limit' => $pageSize]);

                foreach ($items as $item) {
                    yield $item;
                }

                $offset += $pageSize;
            } while (count($items) === $pageSize);
        });
    }

    private function smtpAccount(?string $smtpAccount): string
    {
        $smtpAccount ??= $this->config->smtpAccount;

        if ($smtpAccount === null || $smtpAccount === '') {
            throw InvalidConfiguration::missingSmtpAccount();
        }

        return $smtpAccount;
    }

    private function url(string $path): string
    {
        return $this->config->baseUrl . '/' . self::VERSION . '/' . $path;
    }

    /**
     * Converts filters to query parameters: dates as "Y-m-d H:i:s" in the panel time zone, lists comma separated.
     *
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    private static function query(array $filters, string $timezone): array
    {
        $query = [];

        foreach ($filters as $key => $value) {
            $query[$key] = match (true) {
                $value === null => null,
                $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->setTimezone($timezone)->format('Y-m-d H:i:s'),
                is_bool($value) => $value ? '1' : '0',
                is_array($value) => implode(',', array_map(static fn(mixed $item): string => is_scalar($item) ? (string) $item : '', $value)),
                is_scalar($value) => (string) $value,
                default => null,
            };
        }

        return array_filter($query, static fn(?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * Sends a request, retrying reads on connection errors, HTTP 5xx and HTTP 429.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $query
     * @return array{status: int, body: array<array-key, mixed>}
     */
    private function perform(string $method, string $url, ?array $body = null, array $query = [], bool $notFoundIsEmpty = false): array
    {
        $read = $method === 'GET' || $method === 'OPTIONS';
        $attempt = 0;

        while (true) {
            try {
                $response = $this->dispatch($method, $url, $body, $query);
            } catch (ConnectionException $exception) {
                if (! $read || $attempt >= $this->config->retries) {
                    throw ApiException::transport($exception);
                }

                $this->backoff($attempt++);

                continue;
            }

            $status = $response->status();

            if ($read && ($status === 429 || $status >= 500) && $attempt < $this->config->retries) {
                $this->backoff($attempt++, $status === 429 ? self::retryAfter($response) : null);

                continue;
            }

            if ($notFoundIsEmpty && $status === 404) {
                return ['status' => 404, 'body' => ['data' => []]];
            }

            return ['status' => $status, 'body' => $this->decode($response)];
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $query
     */
    private function dispatch(string $method, string $url, ?array $body, array $query): Response
    {
        $request = $this->http->createPendingRequest()
            ->withHeaders([
                'Application-Key' => $this->config->appKey,
                'Authorization' => $this->config->secretKey,
            ])
            ->timeout($this->config->timeout)
            ->acceptJson()
            ->withUserAgent('beziworld-laravel-emaillabs');

        if ($query !== []) {
            $request = $request->withQueryParameters($query);
        }

        if ($body === null) {
            return $request->send($method, $url);
        }

        return $request->asJson()->send($method, $url, ['json' => $body]);
    }

    private function backoff(int $attempt, ?int $retryAfter = null): void
    {
        if ($retryAfter !== null) {
            Sleep::sleep(min($retryAfter, self::MAX_RETRY_AFTER));

            return;
        }

        if ($this->config->retryDelay > 0) {
            Sleep::usleep($this->config->retryDelay * 1000 * (2 ** $attempt));
        }
    }

    private static function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $time = strtotime($header);

        return $time !== false ? max(0, $time - time()) : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 204 || trim($response->body()) === '') {
            if ($response->failed()) {
                throw ApiException::fromResponse([], $response->status(), self::retryAfter($response));
            }

            return [];
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            if ($response->failed()) {
                throw ApiException::fromResponse([], $response->status(), self::retryAfter($response));
            }

            throw ApiException::malformedResponse($response->status());
        }

        if ($response->failed()) {
            throw ApiException::fromResponse($payload, $response->status(), self::retryAfter($response));
        }

        return $payload;
    }
}
