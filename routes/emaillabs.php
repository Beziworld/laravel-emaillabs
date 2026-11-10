<?php

declare(strict_types=1);

use Beziworld\EmailLabs\Http\Controllers\WebhookController;
use Beziworld\EmailLabs\Http\Middleware\VerifyEmailLabsWebhook;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

$middleware = Config::get('emaillabs.webhooks.middleware', []);
$middleware = [...(is_array($middleware) ? array_values($middleware) : []), VerifyEmailLabsWebhook::class];
$path = trim((string) Config::get('emaillabs.webhooks.path', 'emaillabs/webhook'), '/');

Route::post($path, WebhookController::class)
    ->middleware($middleware)
    ->name('emaillabs.webhook');
