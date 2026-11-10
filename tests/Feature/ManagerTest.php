<?php

declare(strict_types=1);

use Beziworld\EmailLabs\EmailLabsClient;
use Beziworld\EmailLabs\EmailLabsConfig;
use Beziworld\EmailLabs\EmailLabsManager;
use Beziworld\EmailLabs\Exceptions\InvalidConfiguration;
use Beziworld\EmailLabs\Facades\EmailLabs;
use Beziworld\EmailLabs\Messages\EmailLabsMessage;
use Beziworld\EmailLabs\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('resolves named connections and caches the clients', function (): void {
    $manager = app(EmailLabsManager::class);

    expect($manager->connection())->toBe($manager->connection('default'))
        ->and($manager->connection('marketing')->config()->smtpAccount)->toBe('2.shop.smtp')
        ->and($manager->connection('marketing')->connectionName())->toBe('marketing')
        ->and($manager->connectionNames())->toBe(['default', 'marketing'])
        ->and(app(EmailLabsClient::class)->connectionName())->toBe('default');
});

it('throws for unknown connections and missing keys', function (): void {
    expect(fn() => EmailLabs::connection('nope'))->toThrow(InvalidConfiguration::class, 'nope')
        ->and(fn() => EmailLabs::using(['app_key' => 'a']))->toThrow(InvalidConfiguration::class, 'EMAILLABS_SECRET_KEY')
        ->and(fn() => EmailLabs::using(['secret_key' => 'a']))->toThrow(InvalidConfiguration::class, 'EMAILLABS_APP_KEY');
});

it('creates clients for runtime keys with shared options', function (): void {
    Http::fake(['https://el.example/*' => Http::response(TestCase::sendResponse())]);
    config()->set('emaillabs.base_url', 'https://el.example/');

    $client = EmailLabs::using(['app_key' => 'tenant-app', 'secret_key' => 'tenant-secret', 'smtp_account' => '5.tenant.smtp'], 'tenant');
    $client->send(EmailLabsMessage::create('Hi')->from('a@example.com')->to('b@example.com')->text('x'));

    expect($client->connectionName())->toBe('tenant')
        ->and($client->config()->baseUrl)->toBe('https://el.example');
    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://el.example/v2.1/email'
        && $request->header('Application-Key') === ['tenant-app']
        && ($request->data()['smtpAccount'] ?? null) === '5.tenant.smtp');
});

it('switches the SMTP account of a client', function (): void {
    $client = EmailLabs::connection()->forSmtpAccount('8.shop.smtp');

    expect($client->config()->smtpAccount)->toBe('8.shop.smtp')
        ->and($client->config()->appKey)->toBe(TestCase::APP_KEY);
});

it('collects webhook secrets and credentials', function (): void {
    expect(EmailLabs::webhookSecrets())->toBe(['default' => TestCase::WEBHOOK_SECRET, 'marketing' => 'marketing-webhook-secret'])
        ->and(EmailLabs::webhookCredentials('marketing'))->toBe(['hook', 'pass'])
        ->and(EmailLabs::webhookCredentials('default'))->toBeNull();
});

it('builds the configuration from an array', function (): void {
    $config = EmailLabsConfig::fromArray(['app_key' => ' a ', 'secret_key' => 'b', 'timeout' => '5', 'retries' => -3, 'base_url' => 'https://x.example/']);

    expect($config->appKey)->toBe('a')
        ->and($config->timeout)->toBe(5)
        ->and($config->retries)->toBe(0)
        ->and($config->baseUrl)->toBe('https://x.example')
        ->and($config->timezone)->toBe('Europe/Warsaw');
});

it('forgets cached clients', function (): void {
    $first = EmailLabs::connection();
    EmailLabs::purge('default');

    expect(EmailLabs::connection())->not->toBe($first);
});

it('lists SMTP accounts with the artisan command', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(['data' => [['smtpAccount' => '1.shop.smtp', 'smtpName' => 'smtp2-13', 'smtpType' => 'Dedicated', 'vps' => [['host' => 'smtp2-13.emaillabs.net.pl']]]]])]);

    $this->artisan('emaillabs:smtp-accounts')
        ->expectsTable(['Account', 'Name', 'Type', 'Servers'], [['1.shop.smtp', 'smtp2-13', 'Dedicated', 'smtp2-13.emaillabs.net.pl']])
        ->assertSuccessful();
});

it('reports API errors in the artisan command', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(['errors' => [['title' => 'Unknown token', 'message' => 'Not found any user with this token', 'code' => 'E-0-003']]], 401)]);

    $this->artisan('emaillabs:smtp-accounts', ['--connection' => 'marketing'])
        ->expectsOutputToContain('Not found any user with this token')
        ->assertFailed();
});
