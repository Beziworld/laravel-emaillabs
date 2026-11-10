<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Http\Middleware;

use Beziworld\EmailLabs\EmailLabsManager;
use Beziworld\EmailLabs\Security\WebhookSignature;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Rejects webhook requests without a valid X-Webhook-Checksum.
 *
 * The checksum is compared with SHA1("{secret}|{X-Webhook-Date}|{Request-Id}")
 * for the webhook secret of every configured connection (and of the
 * resolver registered with EmailLabs::resolveWebhookSecretsUsing()); the
 * matching connection is stored in the "emaillabs_connection" request
 * attribute. When that connection defines webhook_username and
 * webhook_password, HTTP Basic credentials are required as well.
 */
final class VerifyEmailLabsWebhook
{
    public const string ATTRIBUTE = 'emaillabs_connection';

    public function __construct(
        private readonly EmailLabsManager $manager,
        private readonly Config $config,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) $this->config->get('emaillabs.webhooks.verify', true)) {
            $request->attributes->set(self::ATTRIBUTE, $this->manager->getDefaultConnection());

            return $next($request);
        }

        $checksum = $request->header('X-Webhook-Checksum', '');
        $date = $request->header('X-Webhook-Date', '');
        $requestId = $request->header('Request-Id', '');

        if ($checksum === '' || $date === '' || $requestId === '') {
            throw new AccessDeniedHttpException('Missing EmailLabs webhook checksum headers.');
        }

        $connection = null;

        foreach ($this->manager->webhookSecrets() as $name => $secret) {
            if ((new WebhookSignature($secret))->verify($checksum, $date, $requestId)) {
                $connection = $name;

                break;
            }
        }

        if ($connection === null) {
            throw new AccessDeniedHttpException('Invalid EmailLabs webhook checksum.');
        }

        $credentials = $this->manager->webhookCredentials($connection);

        if ($credentials !== null && (
            ! hash_equals($credentials[0], (string) $request->getUser())
            || ! hash_equals($credentials[1], (string) $request->getPassword())
        )) {
            throw new UnauthorizedHttpException('Basic realm="EmailLabs webhook"', 'Invalid EmailLabs webhook credentials.');
        }

        $request->attributes->set(self::ATTRIBUTE, $connection);

        return $next($request);
    }
}
