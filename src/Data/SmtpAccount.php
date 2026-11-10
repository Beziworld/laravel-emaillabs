<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Data;

use Beziworld\EmailLabs\Support\Payload;

/**
 * An SMTP account of the panel, for example "1.mypanel.smtp".
 */
final readonly class SmtpAccount
{
    /**
     * @param list<array{name: ?string, host: ?string, type: ?string, ip: ?string}> $servers
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $account,
        public ?string $name,
        public ?string $type,
        public array $servers,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = Payload::of($data);
        $servers = [];

        foreach ($payload->array('vps') as $vps) {
            if (is_array($vps)) {
                $server = Payload::of($vps);
                $servers[] = [
                    'name' => $server->string('vpsName'),
                    'host' => $server->string('host'),
                    'type' => $server->string('vpsType'),
                    'ip' => $server->string('ip'),
                ];
            }
        }

        return new self(
            account: $payload->string('smtpAccount') ?? '',
            name: $payload->string('smtpName'),
            type: $payload->string('smtpType'),
            servers: $servers,
            raw: $data,
        );
    }
}
