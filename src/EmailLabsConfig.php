<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs;

use Beziworld\EmailLabs\Exceptions\InvalidConfiguration;

/**
 * Immutable configuration of a single EmailLabs panel (one API key pair).
 */
final readonly class EmailLabsConfig
{
    public const string DEFAULT_BASE_URL = 'https://api.emaillabs.io';

    public function __construct(
        public string $appKey,
        public string $secretKey,
        public ?string $smtpAccount = null,
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public int $timeout = 30,
        public int $retries = 2,
        public int $retryDelay = 500,
        public ?string $webhookSecret = null,
        public ?string $webhookUsername = null,
        public ?string $webhookPassword = null,
        public string $timezone = 'Europe/Warsaw',
    ) {
        if ($appKey === '') {
            throw InvalidConfiguration::missing('app_key', 'EMAILLABS_APP_KEY');
        }

        if ($secretKey === '') {
            throw InvalidConfiguration::missing('secret_key', 'EMAILLABS_SECRET_KEY');
        }
    }

    /**
     * Builds the configuration from a connection array as defined in config/emaillabs.php.
     *
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidConfiguration When the application key or the secret key is missing.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            appKey: self::string($config, 'app_key') ?? '',
            secretKey: self::string($config, 'secret_key') ?? '',
            smtpAccount: self::string($config, 'smtp_account'),
            baseUrl: rtrim(self::string($config, 'base_url') ?? self::DEFAULT_BASE_URL, '/'),
            timeout: max(1, self::integer($config, 'timeout', 30)),
            retries: max(0, self::integer($config, 'retries', 2)),
            retryDelay: max(0, self::integer($config, 'retry_delay', 500)),
            webhookSecret: self::string($config, 'webhook_secret'),
            webhookUsername: self::string($config, 'webhook_username'),
            webhookPassword: self::string($config, 'webhook_password'),
            timezone: self::string($config, 'timezone') ?? 'Europe/Warsaw',
        );
    }

    /**
     * Returns a copy that sends through another SMTP account of the same panel.
     */
    public function withSmtpAccount(string $smtpAccount): self
    {
        return new self(
            $this->appKey,
            $this->secretKey,
            $smtpAccount,
            $this->baseUrl,
            $this->timeout,
            $this->retries,
            $this->retryDelay,
            $this->webhookSecret,
            $this->webhookUsername,
            $this->webhookPassword,
            $this->timezone,
        );
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function string(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function integer(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
