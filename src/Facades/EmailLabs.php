<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Facades;

use Beziworld\EmailLabs\EmailLabsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Beziworld\EmailLabs\EmailLabsClient connection(?string $name = null)
 * @method static \Beziworld\EmailLabs\EmailLabsClient using(\Beziworld\EmailLabs\EmailLabsConfig|array<string, mixed> $config, string $name = 'runtime')
 * @method static string getDefaultConnection()
 * @method static list<string> connectionNames()
 * @method static void resolveWebhookSecretsUsing(\Closure(): iterable<string, string> $resolver)
 * @method static array<string, string> webhookSecrets()
 * @method static array{0: string, 1: string}|null webhookCredentials(string $name)
 * @method static void purge(?string $name = null)
 * @method static \Beziworld\EmailLabs\EmailLabsConfig config()
 * @method static string connectionName()
 * @method static \Beziworld\EmailLabs\EmailLabsClient forSmtpAccount(string $smtpAccount)
 * @method static \Beziworld\EmailLabs\Data\SendResult send(\Beziworld\EmailLabs\Messages\EmailLabsMessage $message)
 * @method static list<\Beziworld\EmailLabs\Data\EmailEvent> emails(array<string, scalar|list<string>|\DateTimeInterface|null> $filters = [])
 * @method static \Illuminate\Support\LazyCollection<int, \Beziworld\EmailLabs\Data\EmailEvent> allEmails(array<string, scalar|list<string>|\DateTimeInterface|null> $filters = [], int $pageSize = 1000)
 * @method static \Beziworld\EmailLabs\Data\EmailEvent|null email(string $messageId)
 * @method static list<\Beziworld\EmailLabs\Data\Engagement> opens(array<string, scalar|list<string>|\DateTimeInterface|null> $filters = [])
 * @method static list<\Beziworld\EmailLabs\Data\Engagement> clicks(array<string, scalar|list<string>|\DateTimeInterface|null> $filters = [])
 * @method static array<array-key, mixed> statistics(\DateTimeInterface $from, ?\DateTimeInterface $to = null, bool $withTags = false, ?string $smtpAccount = null)
 * @method static list<\Beziworld\EmailLabs\Data\Template> templates(?string $smtpAccount = null, int $offset = 0, int $limit = 100)
 * @method static \Beziworld\EmailLabs\Data\Template|null template(string $externalId)
 * @method static string addTemplate(string $name, string $html, string $text, ?string $externalId = null, ?string $smtpAccount = null)
 * @method static void deleteTemplates(string ...$externalIds)
 * @method static list<\Beziworld\EmailLabs\Data\BlacklistEntry> blacklist(?string $smtpAccount = null, int $offset = 0, int $limit = 1000)
 * @method static \Illuminate\Support\LazyCollection<int, \Beziworld\EmailLabs\Data\BlacklistEntry> allBlacklisted(?string $smtpAccount = null, int $pageSize = 1000)
 * @method static bool isBlacklisted(string $email, ?string $smtpAccount = null)
 * @method static \Beziworld\EmailLabs\Data\BlacklistEntry|null addToBlacklist(string $email, \Beziworld\EmailLabs\Enums\BlacklistReason|string $reason = \Beziworld\EmailLabs\Enums\BlacklistReason::Other, ?string $comment = null, ?string $smtpAccount = null)
 * @method static void removeFromBlacklist(string|list<string> $emails, ?string $smtpAccount = null)
 * @method static list<string> blacklistReasons()
 * @method static list<mixed> blacklistedDomains(?string $smtpAccount = null)
 * @method static void addDomainToBlacklist(string $domain, ?string $smtpAccount = null)
 * @method static void removeDomainsFromBlacklist(string|list<string> $domains, ?string $smtpAccount = null)
 * @method static list<\Beziworld\EmailLabs\Data\SmtpAccount> smtpAccounts()
 * @method static array<array-key, mixed> serviceStatus()
 * @method static array<array-key, mixed> request(string $method, string $uri, array<string, mixed> $data = [])
 *
 * @see EmailLabsManager
 * @see \Beziworld\EmailLabs\EmailLabsClient
 */
final class EmailLabs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EmailLabsManager::class;
    }
}
