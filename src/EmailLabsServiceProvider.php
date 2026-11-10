<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs;

use Beziworld\EmailLabs\Console\SmtpAccountsCommand;
use Beziworld\EmailLabs\Http\Middleware\VerifyEmailLabsWebhook;
use Beziworld\EmailLabs\Transport\EmailLabsTransport;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Mail\MailManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the EmailLabs manager, the "emaillabs" mail transport, the webhook middleware and route and the artisan command.
 */
final class EmailLabsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/emaillabs.php', 'emaillabs');

        $this->app->singleton(EmailLabsManager::class, static fn(Application $app): EmailLabsManager => new EmailLabsManager(
            $app->make(HttpFactory::class),
            $app->make(Config::class),
            $app->make(Dispatcher::class),
        ));

        $this->app->bind(EmailLabsClient::class, static fn(Application $app): EmailLabsClient => $app->make(EmailLabsManager::class)->connection());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/emaillabs.php' => $this->app->configPath('emaillabs.php'),
            ], 'emaillabs-config');

            $this->commands([SmtpAccountsCommand::class]);
        }

        $this->callAfterResolving(MailManager::class, static function (MailManager $mail, Application $app): void {
            $mail->extend('emaillabs', static function (array $config) use ($app): EmailLabsTransport {
                $manager = $app->make(EmailLabsManager::class);
                $connection = isset($config['connection']) && is_string($config['connection']) && $config['connection'] !== '' ? $config['connection'] : null;
                $client = $manager->connection($connection);

                if (isset($config['smtp_account']) && is_string($config['smtp_account']) && $config['smtp_account'] !== '') {
                    $client = $client->forSmtpAccount($config['smtp_account']);
                }

                return new EmailLabsTransport($client);
            });
        });

        $this->app->make(Router::class)->aliasMiddleware('emaillabs.webhook', VerifyEmailLabsWebhook::class);

        if ((bool) $this->app->make(Config::class)->get('emaillabs.webhooks.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/emaillabs.php');
        }
    }
}
