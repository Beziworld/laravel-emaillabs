<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Tests;

use Beziworld\EmailLabs\EmailLabsServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const string APP_KEY = 'test-app-key';

    public const string SECRET_KEY = 'test-secret-key';

    public const string WEBHOOK_SECRET = 'webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [EmailLabsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('emaillabs.connections.default', [
            'app_key' => self::APP_KEY,
            'secret_key' => self::SECRET_KEY,
            'smtp_account' => '1.shop.smtp',
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        $app['config']->set('emaillabs.connections.marketing', [
            'app_key' => 'marketing-app-key',
            'secret_key' => 'marketing-secret-key',
            'smtp_account' => '2.shop.smtp',
            'webhook_secret' => 'marketing-webhook-secret',
            'webhook_username' => 'hook',
            'webhook_password' => 'pass',
        ]);
        $app['config']->set('emaillabs.retry_delay', 0);
        $app['config']->set('emaillabs.webhooks.enabled', true);
        $app['config']->set('mail.default', 'emaillabs');
        $app['config']->set('mail.mailers.emaillabs', ['transport' => 'emaillabs']);
        $app['config']->set('mail.mailers.emaillabs-marketing', ['transport' => 'emaillabs', 'connection' => 'marketing', 'smtp_account' => '7.shop.smtp']);
        $app['config']->set('mail.from', ['address' => 'shop@example.com', 'name' => 'Shop']);
    }

    /**
     * Response of POST /v2.1/email in the documented envelope.
     *
     * @param list<string> $recipients
     * @return array<string, mixed>
     */
    public static function sendResponse(array $recipients = ['jane@example.com']): array
    {
        return [
            'meta' => ['numberOfErrors' => 0, 'numberOfData' => count($recipients), 'status' => 200, 'uniqId' => '00d928f759'],
            'data' => array_map(static fn(string $email): array => [
                'subject' => 'Order shipped',
                'smtpAccount' => '1.shop.smtp',
                'tags' => [],
                'to' => ['email' => $email, 'name' => 'Jane', 'messageId' => md5($email) . '@example.com'],
                'from' => ['email' => 'shop@example.com', 'name' => 'Shop'],
                'status' => 'injected',
                'statusTime' => 1580996275,
                'statusDesc' => 'injected',
                'allStatuses' => [],
            ], $recipients),
        ];
    }
}
