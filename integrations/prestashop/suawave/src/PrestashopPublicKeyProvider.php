<?php

declare(strict_types=1);

namespace Suawave;

use Configuration;

final class PrestashopPublicKeyProvider implements PublicKeyProvider
{
    public const SETTING = 'SUAWAVE_PUBKEY';

    public function __construct(private readonly GatewayClient $client)
    {
    }

    public function current(): string
    {
        $stored = Configuration::get(self::SETTING);
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
        return $this->refresh();
    }

    public function refresh(): string
    {
        $key = $this->client->publicKey();
        new SignatureVerifier($key);
        Configuration::updateValue(self::SETTING, $key);
        return $key;
    }
}
