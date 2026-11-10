<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs;

use Beziworld\EmailLabs\Exceptions\InvalidConfiguration;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves EmailLabs clients for any number of panels.
 *
 * Named connections are defined in config/emaillabs.php in the same way as
 * database connections; options outside "connections" are shared defaults.
 * Clients for keys stored at runtime (for example per tenant) are created
 * with using(). Calls made directly on the manager go to the default
 * connection.
 *
 * @mixin EmailLabsClient
 */
final class EmailLabsManager
{
    /** @var array<string, EmailLabsClient> */
    private array $clients = [];

    /** @var (Closure(): iterable<string, string>)|null */
    private ?Closure $webhookSecretResolver = null;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Config $config,
        private readonly ?Dispatcher $events = null,
    ) {}

    /**
     * Client of a configured connection; the default connection when no name is given.
     *
     * @throws InvalidConfiguration When the connection is not defined or incomplete.
     */
    public function connection(?string $name = null): EmailLabsClient
    {
        $name ??= $this->getDefaultConnection();

        return $this->clients[$name] ??= new EmailLabsClient($this->http, $this->configFor($name), $name, $this->events);
    }

    /**
     * Client for keys provided at runtime, merged with the shared options.
     *
     * @param EmailLabsConfig|array<string, mixed> $config Keys as in a connection: app_key, secret_key, smtp_account, ...
     */
    public function using(EmailLabsConfig|array $config, string $name = 'runtime'): EmailLabsClient
    {
        $config = $config instanceof EmailLabsConfig ? $config : EmailLabsConfig::fromArray(self::merge($this->shared(), $config));

        return new EmailLabsClient($this->http, $config, $name, $this->events);
    }

    public function getDefaultConnection(): string
    {
        $default = $this->config->get('emaillabs.default');

        return is_string($default) && $default !== '' ? $default : 'default';
    }

    /**
     * @return list<string>
     */
    public function connectionNames(): array
    {
        $connections = $this->config->get('emaillabs.connections');

        if (! is_array($connections) || $connections === []) {
            return [$this->getDefaultConnection()];
        }

        return array_map(strval(...), array_keys($connections));
    }

    /**
     * Registers additional webhook secrets, for example of tenant panels stored in the database.
     *
     * The resolver returns secrets keyed by a name that is reported as the
     * connection of the received events.
     *
     * @param Closure(): iterable<string, string> $resolver
     */
    public function resolveWebhookSecretsUsing(Closure $resolver): void
    {
        $this->webhookSecretResolver = $resolver;
    }

    /**
     * Webhook secrets of every configured connection and of the custom resolver, keyed by connection name.
     *
     * @return array<string, string>
     */
    public function webhookSecrets(): array
    {
        $secrets = [];

        foreach ($this->connectionNames() as $name) {
            $connection = $this->rawConnection($name);
            $secret = $connection['webhook_secret'] ?? null;

            if (is_string($secret) && trim($secret) !== '') {
                $secrets[$name] = trim($secret);
            }
        }

        if ($this->webhookSecretResolver !== null) {
            foreach (($this->webhookSecretResolver)() as $name => $secret) {
                if ($secret !== '') {
                    $secrets[$name] = $secret;
                }
            }
        }

        return $secrets;
    }

    /**
     * Basic auth credentials expected on webhook requests of a connection, when configured.
     *
     * @return array{0: string, 1: string}|null
     */
    public function webhookCredentials(string $name): ?array
    {
        $connection = $this->rawConnection($name);
        $username = $connection['webhook_username'] ?? null;
        $password = $connection['webhook_password'] ?? null;

        return is_string($username) && $username !== '' && is_string($password) && $password !== '' ? [$username, $password] : null;
    }

    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->clients = [];

            return;
        }

        unset($this->clients[$name]);
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $callable = [$this->connection(), $method];

        if (! is_callable($callable)) {
            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', EmailLabsClient::class, $method));
        }

        return $callable(...$arguments);
    }

    private function configFor(string $name): EmailLabsConfig
    {
        $connections = $this->config->get('emaillabs.connections');

        if ((! is_array($connections) || $connections === []) && $name === $this->getDefaultConnection()) {
            return EmailLabsConfig::fromArray($this->shared());
        }

        if (! is_array($connections) || ! is_array($connections[$name] ?? null)) {
            throw InvalidConfiguration::unknownConnection($name);
        }

        return EmailLabsConfig::fromArray(self::merge($this->shared(), $connections[$name]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rawConnection(string $name): array
    {
        $connections = $this->config->get('emaillabs.connections');
        $connection = is_array($connections) && is_array($connections[$name] ?? null) ? $connections[$name] : [];

        /** @var array<string, mixed> */
        return self::merge($this->shared(), $connection);
    }

    /**
     * Applies connection options over the shared ones; empty values (unset environment variables) do not override.
     *
     * @param array<string, mixed> $shared
     * @param array<array-key, mixed> $connection
     * @return array<string, mixed>
     */
    private static function merge(array $shared, array $connection): array
    {
        foreach ($connection as $key => $option) {
            if ($option !== null && $option !== '') {
                $shared[(string) $key] = $option;
            }
        }

        return $shared;
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        $config = $this->config->get('emaillabs', []);
        $config = is_array($config) ? $config : [];
        unset($config['default'], $config['connections'], $config['webhooks']);

        /** @var array<string, mixed> $config */
        return $config;
    }
}
