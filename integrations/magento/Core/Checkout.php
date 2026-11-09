<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class Checkout
{
    public static function open(
        GatewayClient $client,
        string $ticker,
        string $payoutAddress,
        string $fiatAmount,
        string $fiatCurrency,
        callable $callbackUrlFor
    ): array {
        $nonce = bin2hex(random_bytes(16));
        $expected = $client->convertToTicker($ticker, $fiatAmount, $fiatCurrency);
        $created = $client->createAddress($ticker, $payoutAddress, $callbackUrlFor($nonce));
        if (!Decimal::atLeast($expected, $created['minimum_transaction_coin'])) {
            throw new BelowMinimumException('the order total is below the minimum amount');
        }
        return [
            'nonce' => $nonce,
            'address_in' => $created['address_in'],
            'ticker' => $ticker,
            'expected' => $expected,
            'expires_at' => $created['expires_at'] === null ? '' : (string) $created['expires_at'],
        ];
    }
}
