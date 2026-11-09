<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class WooOrderStore implements OrderStore
{
    public const GATEWAY_ID = 'suawave';
    public const EXPIRY_HOOK = 'suawave_payment_expired';
    public const PREVIOUS_ATTEMPTS = '_suawave_previous_attempts';
    public const EXPIRY_NOTED = '_suawave_expiry_noted';
    public const DETECTED = '_suawave_detected';
    public const PAID_NONCE = '_suawave_paid_nonce';
    public const PAID_ADDRESS = '_suawave_paid_address';

    private const ATTEMPT_FIELDS = ['nonce', 'address', 'coin', 'ticker', 'amount', 'minimum', 'expires_at'];

    public function load(string $orderId, string $nonce): ?OrderRecord
    {
        $order = $this->fresh($orderId);
        if ($order === null || $nonce === '') {
            return null;
        }
        $current = (string) $order->get_meta('_suawave_nonce');
        if ($current !== '' && hash_equals($current, $nonce)) {
            return new OrderRecord(
                $current,
                (string) $order->get_meta('_suawave_address'),
                (string) $order->get_meta('_suawave_coin'),
                (string) $order->get_meta('_suawave_amount')
            );
        }
        foreach (self::previousAttempts($order) as $attempt) {
            if (hash_equals((string) $attempt['nonce'], $nonce)) {
                return new OrderRecord((string) $attempt['nonce'], (string) $attempt['address'], (string) $attempt['coin'], (string) $attempt['amount'], false);
            }
        }
        return null;
    }

    public function recordPending(string $orderId, string $txidIn): void
    {
        $order = $this->require($orderId);
        $order->update_meta_data(self::DETECTED, (string) $order->get_meta('_suawave_nonce'));
        $order->add_order_note(sprintf('Deposit detected on the gateway (%s). Waiting for the confirmed callback.', $txidIn));
        if ($order->has_status('pending')) {
            $order->update_status('on-hold');
        }
        $order->save();
    }

    public function recordDeposit(string $orderId, OrderRecord $attempt, string $txidIn, string $txidOut, string $valueCoin, string $received, bool $paid): void
    {
        $order = $this->require($orderId);
        $order->update_meta_data('_suawave_received', $received);
        $order->add_order_note(sprintf(
            'Confirmed deposit of %s to %s (%s attempt, inbound %s, payout %s). Total received %s of %s.',
            Money::plain($valueCoin),
            $attempt->addressIn,
            $attempt->current ? 'current' : 'superseded',
            $txidIn,
            $txidOut,
            Money::plain($received),
            self::exact((string) $order->get_meta('_suawave_amount'))
        ));
        if ($paid) {
            $order->update_meta_data(self::PAID_NONCE, $attempt->nonce);
            $order->update_meta_data(self::PAID_ADDRESS, $attempt->addressIn);
        }
        $order->save();
        if ($paid && !$order->is_paid() && $order->has_status(['pending', 'on-hold', 'failed', 'cancelled'])) {
            $order->payment_complete($txidIn);
        }
    }

    public function recordAfterPaid(string $orderId, string $addressIn, string $txidIn, string $valueCoin): void
    {
        $order = $this->require($orderId);
        $order->add_order_note(sprintf('Suawave payment received on a previous attempt, reconcile: confirmed deposit of %s to %s (inbound %s) after the order was already paid. The order status was not changed.', Money::plain($valueCoin), $addressIn, $txidIn));
        $order->save();
    }

    public function renew(string $orderId, array $attempt, Totals $totals): bool
    {
        $order = $this->require($orderId);
        if ($totals->paid || $order->is_paid()) {
            return false;
        }
        $previousNonce = (string) $order->get_meta('_suawave_nonce');
        $previousAddress = (string) $order->get_meta('_suawave_address');
        if ($previousNonce !== '') {
            $previous = [];
            foreach (self::ATTEMPT_FIELDS as $field) {
                $previous[$field] = (string) $order->get_meta('_suawave_' . $field);
            }
            $order->update_meta_data(self::PREVIOUS_ATTEMPTS, [...self::previousAttempts($order), $previous]);
        }
        foreach (self::ATTEMPT_FIELDS as $field) {
            $order->update_meta_data('_suawave_' . $field, (string) $attempt[$field]);
        }
        $order->update_meta_data('_suawave_received', $totals->received);
        $order->delete_meta_data('_suawave_qr');
        $note = $previousNonce === ''
            ? 'Waiting for the crypto deposit.'
            : sprintf('New Suawave payment attempt with a new deposit address %s. The previous address %s is superseded; an on-time deposit to it still counts for this order until it is paid.', (string) $attempt['address'], $previousAddress);
        if ($order->has_status('on-hold')) {
            $order->add_order_note($note);
            $order->save();
        } else {
            $order->update_status('on-hold', $note);
        }
        return true;
    }

    public function closeExpired(string $orderId, int $now, Totals $totals): bool
    {
        $order = $this->fresh($orderId);
        if ($order === null || $totals->paid || $order->is_paid()) {
            return false;
        }
        $nonce = (string) $order->get_meta('_suawave_nonce');
        $expiresAt = PaymentWindow::fromMeta($order->get_meta('_suawave_expires_at'));
        if ($nonce === '' || !PaymentWindow::isClosed($expiresAt, $now) || (string) $order->get_meta(self::EXPIRY_NOTED) === $nonce) {
            return false;
        }
        $order->update_meta_data(self::EXPIRY_NOTED, $nonce);
        $order->delete_meta_data('_suawave_qr');
        $closedOn = PaymentWindow::format((int) $expiresAt, wp_timezone());
        if ((string) $order->get_meta(self::DETECTED) === $nonce) {
            $order->add_order_note(sprintf(
                __('The Suawave payment window closed on %s. A deposit was detected before it closed and is waiting for confirmation, so the order stays on hold. The deposit address is no longer shown to the customer.', 'suawave-payment'),
                $closedOn
            ));
            $order->save();
            return true;
        }
        $note = sprintf(
            __('The Suawave payment window closed on %s without a confirmed payment. The deposit address is no longer shown to the customer; paying the order again issues a new address.', 'suawave-payment'),
            $closedOn
        );
        if ($order->has_status('on-hold')) {
            $order->update_status('pending', $note);
        } else {
            $order->add_order_note($note);
            $order->save();
        }
        return true;
    }

    public function isCloseDue(string $orderId, int $now): bool
    {
        $order = $this->find($orderId);
        if ($order === null || $order->is_paid()) {
            return false;
        }
        $nonce = (string) $order->get_meta('_suawave_nonce');
        return $nonce !== ''
            && PaymentWindow::isClosed(PaymentWindow::fromMeta($order->get_meta('_suawave_expires_at')), $now)
            && (string) $order->get_meta(self::EXPIRY_NOTED) !== $nonce;
    }

    public function find(string $orderId): ?\WC_Order
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

    public function fresh(string $orderId): ?\WC_Order
    {
        if (preg_match('/^[0-9]{1,18}$/', $orderId) !== 1) {
            return null;
        }
        $id = (int) $orderId;
        if (function_exists('clean_post_cache')) {
            clean_post_cache($id);
        }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($id, 'orders');
            wp_cache_delete($id, 'post_meta');
        }
        self::clearWooCaches($id);
        $order = $this->find($orderId);
        $order?->read_meta_data(true);
        return $order;
    }

    private static function clearWooCaches(int $id): void
    {
        if (!function_exists('wc_get_container')) {
            return;
        }
        if (class_exists(\Automattic\WooCommerce\Caches\OrderCache::class)) {
            try {
                wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($id);
            } catch (\Throwable) {
            }
        }
        if (class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class)) {
            try {
                $hpos = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class);
                if (method_exists($hpos, 'clear_cached_data')) {
                    $hpos->clear_cached_data([$id]);
                }
            } catch (\Throwable) {
            }
        }
        if (class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta::class)) {
            try {
                $meta = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta::class);
                if (method_exists($meta, 'clear_cached_data')) {
                    $meta->clear_cached_data([$id]);
                }
            } catch (\Throwable) {
            }
        }
    }

    private function require(string $orderId): \WC_Order
    {
        $order = $this->fresh($orderId);
        if ($order === null) {
            throw new \RuntimeException('order vanished');
        }
        return $order;
    }

    private static function previousAttempts(\WC_Order $order): array
    {
        $stored = $order->get_meta(self::PREVIOUS_ATTEMPTS);
        if (!is_array($stored)) {
            return [];
        }
        return array_values(array_filter($stored, static fn (mixed $attempt): bool => is_array($attempt) && is_string($attempt['nonce'] ?? null) && $attempt['nonce'] !== ''));
    }

    private static function exact(string $value): string
    {
        return Money::isDecimal($value) ? Money::plain($value) : $value;
    }
}
