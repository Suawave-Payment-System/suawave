<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class Invoices
{
    public static function create(string $reference, string $amount): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $reference) !== 1 || !Money::isPositive($amount)) {
            throw new GatewayException('invalid reference or amount');
        }
        if (WpInvoiceStore::read($reference) !== null) {
            throw new GatewayException('reference already used');
        }
        $ticker = Settings::get('ticker', 'trc20/usdt');
        $nonce = bin2hex(random_bytes(16));
        $created = Settings::client()->createAddress($ticker, Settings::get('payout_address'), Settings::callbackUrl('wp', $reference, $nonce));
        if (Money::compare($amount, $created['minimum']) < 0) {
            throw new GatewayException('amount is below the minimum deposit of ' . esc_html($created['minimum']));
        }
        $invoice = [
            'nonce' => $nonce,
            'address_in' => $created['address_in'],
            'coin' => str_replace('/', '_', $ticker),
            'ticker' => $ticker,
            'amount' => $amount,
            'minimum' => $created['minimum'],
            'received' => '0',
            'paid' => false,
        ];
        WpInvoiceStore::create($reference, $invoice);
        $invoice['qr_code'] = Settings::client()->qrCode($ticker, $created['address_in'], $amount);
        unset($invoice['nonce']);
        return $invoice;
    }

    public static function isPaid(string $reference): bool
    {
        $invoice = WpInvoiceStore::read($reference);
        return $invoice !== null && $invoice['paid'] === true;
    }
}
