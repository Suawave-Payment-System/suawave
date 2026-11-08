<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class WpInvoiceStore implements OrderStore
{
    private const PREFIX = 'suawave_invoice_';

    public static function create(string $reference, array $invoice): void
    {
        add_option(self::PREFIX . $reference, $invoice, '', 'no');
    }

    public static function read(string $reference): ?array
    {
        $invoice = get_option(self::PREFIX . $reference, null);
        return is_array($invoice) ? $invoice : null;
    }

    public function load(string $orderId): ?OrderRecord
    {
        $invoice = self::read($orderId);
        if ($invoice === null) {
            return null;
        }
        return new OrderRecord(
            (string) $invoice['nonce'],
            (string) $invoice['address_in'],
            (string) $invoice['coin'],
            (string) $invoice['amount']
        );
    }

    public function recordPending(string $orderId, string $txidIn): void
    {
        $invoice = self::read($orderId);
        if ($invoice === null) {
            throw new \RuntimeException('invoice vanished');
        }
        $invoice['detected_txid'] = $txidIn;
        update_option(self::PREFIX . $orderId, $invoice, false);
        do_action('suawave_invoice_detected', $orderId, $invoice);
    }

    public function recordDeposit(string $orderId, string $txidIn, string $txidOut, string $valueCoin, string $received, bool $paid): void
    {
        $invoice = self::read($orderId);
        if ($invoice === null) {
            throw new \RuntimeException('invoice vanished');
        }
        $invoice['received'] = $received;
        $alreadyPaid = $invoice['paid'] === true;
        $invoice['paid'] = $alreadyPaid || $paid;
        update_option(self::PREFIX . $orderId, $invoice, false);
        if ($paid && !$alreadyPaid) {
            do_action('suawave_invoice_paid', $orderId, $invoice);
        }
    }
}
