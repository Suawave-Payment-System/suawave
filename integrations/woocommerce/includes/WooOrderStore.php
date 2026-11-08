<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class WooOrderStore implements OrderStore
{
    public const GATEWAY_ID = 'suawave';

    public function load(string $orderId): ?OrderRecord
    {
        $order = $this->find($orderId);
        if ($order === null) {
            return null;
        }
        return new OrderRecord(
            (string) $order->get_meta('_suawave_nonce'),
            (string) $order->get_meta('_suawave_address'),
            (string) $order->get_meta('_suawave_coin'),
            (string) $order->get_meta('_suawave_amount')
        );
    }

    public function recordPending(string $orderId, string $txidIn): void
    {
        $order = $this->find($orderId);
        if ($order === null) {
            throw new \RuntimeException('order vanished');
        }
        $order->add_order_note(sprintf('Deposit detected on the gateway (%s). Waiting for the confirmed callback.', $txidIn));
        if ($order->has_status('pending')) {
            $order->update_status('on-hold');
        }
        $order->save();
    }

    public function recordDeposit(string $orderId, string $txidIn, string $txidOut, string $valueCoin, string $received, bool $paid): void
    {
        $order = $this->find($orderId);
        if ($order === null) {
            throw new \RuntimeException('order vanished');
        }
        $order->update_meta_data('_suawave_received', $received);
        $order->add_order_note(sprintf('Confirmed deposit of %s (inbound %s, payout %s). Total received %s of %s.', $valueCoin, $txidIn, $txidOut, $received, (string) $order->get_meta('_suawave_amount')));
        $order->save();
        if ($paid && $order->has_status(['pending', 'on-hold', 'failed', 'cancelled'])) {
            $order->payment_complete($txidIn);
        }
    }

    private function find(string $orderId): ?\WC_Order
    {
        if (preg_match('/^[0-9]{1,18}$/', $orderId) !== 1) {
            return null;
        }
        $order = wc_get_order((int) $orderId);
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== self::GATEWAY_ID) {
            return null;
        }
        return $order;
    }
}
