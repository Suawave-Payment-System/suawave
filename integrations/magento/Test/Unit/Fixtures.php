<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

final class Fixtures
{
    public const ORDER = '000000042';
    public const NONCE = '0123456789abcdef0123456789abcdef';
    public const ADDRESS = 'TDepositAddressExample0000000000';
    public const TICKER = 'trc20/usdt';
    public const ORIGIN = 'https://shop.example';

    public static function read(string $name): string
    {
        return trim((string) file_get_contents(__DIR__ . '/Fixtures/' . $name), "\r\n");
    }

    public static function publicKey(): string
    {
        return (string) file_get_contents(__DIR__ . '/Fixtures/callback_public_key.txt');
    }
}
