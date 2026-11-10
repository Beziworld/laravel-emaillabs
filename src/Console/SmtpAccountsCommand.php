<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Console;

use Beziworld\EmailLabs\Data\SmtpAccount;
use Beziworld\EmailLabs\EmailLabsManager;
use Beziworld\EmailLabs\Exceptions\EmailLabsException;
use Illuminate\Console\Command;

/**
 * Lists the SMTP accounts of a connection; doubles as a credentials check.
 */
final class SmtpAccountsCommand extends Command
{
    /** @var string */
    protected $signature = 'emaillabs:smtp-accounts {--connection= : Connection name from config/emaillabs.php}';

    /** @var string */
    protected $description = 'List the EmailLabs SMTP accounts available to the configured API keys';

    public function handle(EmailLabsManager $manager): int
    {
        $connection = $this->input->getOption('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        try {
            $accounts = $manager->connection($connection)->smtpAccounts();
        } catch (EmailLabsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($accounts === []) {
            $this->warn('No SMTP accounts were returned.');

            return self::SUCCESS;
        }

        $this->table(['Account', 'Name', 'Type', 'Servers'], array_map(static fn(SmtpAccount $account): array => [
            $account->account,
            $account->name ?? '',
            $account->type ?? '',
            implode(', ', array_filter(
                array_map(static fn(array $server): string => (string) ($server['host'] ?? $server['name']), $account->servers),
                static fn(string $host): bool => $host !== '',
            )),
        ], $accounts));

        return self::SUCCESS;
    }
}
