<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class Checkout
{
    public static function open(GatewayClient $client, string $ticker, string $payoutAddress, string $amount, callable $callbackUrlFor): array
    {
        $nonce = bin2hex(random_bytes(16));
        $created = $client->createAddress($ticker, $payoutAddress, $callbackUrlFor($nonce));
        if (Money::compare($amount, $created['minimum']) < 0) {
            throw new GatewayException(sprintf('The amount %s is below the minimum deposit of %s.', Money::plain($amount), Money::display($created['minimum'], Money::displayPlaces($ticker))));
        }
        return [
            'nonce' => $nonce,
            'address' => $created['address_in'],
            'coin' => str_replace('/', '_', $ticker),
            'ticker' => $ticker,
            'amount' => $amount,
            'minimum' => $created['minimum'],
            'received' => '0',
            'expires_at' => $created['expires_at'] === null ? '' : (string) $created['expires_at'],
        ];
    }
}
