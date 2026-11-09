<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class CallbackProcessor
{
    public function __construct(private OrderStore $orders, private Ledger $ledger, private OrderLock $lock)
    {
    }

    public function process(array $query, array $signedFields): CallbackResult
    {
        $orderId = $query['order'] ?? '';
        $nonce = $query['nonce'] ?? '';
        if (!is_string($orderId) || $orderId === '' || !is_string($nonce) || $nonce === '') {
            return CallbackResult::rejected(400, 'missing order reference');
        }
        $malformed = self::checkShape($signedFields);
        if ($malformed !== null) {
            return $malformed;
        }
        try {
            return $this->lock->transactional(self::lockKey($query, $orderId), fn (Totals $totals): array => $this->applyLocked($orderId, $nonce, $signedFields, $totals));
        } catch (LockUnavailable $busy) {
            return CallbackResult::rejected(503, 'order busy');
        } catch (\Throwable $failure) {
            return CallbackResult::rejected(500, 'processing failed');
        }
    }

    public static function sourceOf(array $query): string
    {
        return ($query['src'] ?? 'woo') === 'wp' ? 'wp' : 'woo';
    }

    public static function lockKey(array $query, string $orderId): string
    {
        return self::sourceOf($query) === 'wp' ? 'wp_' . $orderId : $orderId;
    }

    private static function checkShape(array $fields): ?CallbackResult
    {
        foreach (['uuid', 'address_in', 'coin', 'pending', 'txid_in'] as $name) {
            if (!isset($fields[$name]) || !is_string($fields[$name]) || $fields[$name] === '') {
                return CallbackResult::rejected(400, 'missing field ' . $name);
            }
        }
        if (preg_match('/^[0-9a-fA-F-]{8,64}$/', $fields['uuid']) !== 1) {
            return CallbackResult::rejected(400, 'invalid uuid');
        }
        if (!in_array($fields['pending'], ['0', '1'], true)) {
            return CallbackResult::rejected(400, 'invalid pending flag');
        }
        if ($fields['pending'] === '0') {
            $value = $fields['value_coin'] ?? '';
            if (!is_string($value) || !Money::isPositive($value)) {
                return CallbackResult::rejected(400, 'invalid value_coin');
            }
        }
        return null;
    }

    private function applyLocked(string $orderId, string $nonce, array $fields, Totals $totals): array
    {
        $order = $this->orders->load($orderId, $nonce);
        if ($order === null) {
            return [CallbackResult::rejected(404, 'unknown order'), $totals];
        }
        if ($fields['address_in'] !== $order->addressIn || $fields['coin'] !== $order->coin) {
            return [CallbackResult::rejected(400, 'callback does not match the order'), $totals];
        }
        if (!$this->ledger->claim(strtolower($fields['uuid']))) {
            return [CallbackResult::acknowledged(), $totals];
        }
        if ($fields['pending'] === '1') {
            if (!$totals->paid) {
                $this->orders->recordPending($orderId, $fields['txid_in']);
            }
            return [CallbackResult::acknowledged(), $totals];
        }
        if ($totals->paid) {
            $this->orders->recordAfterPaid($orderId, $order->addressIn, $fields['txid_in'], $fields['value_coin']);
            return [CallbackResult::acknowledged(), $totals];
        }
        $received = Money::add($totals->received, $fields['value_coin']);
        $becomesPaid = Money::compare($received, $order->amount) >= 0;
        $this->orders->recordDeposit(
            $orderId,
            $order,
            $fields['txid_in'],
            is_string($fields['txid_out'] ?? null) ? $fields['txid_out'] : '',
            $fields['value_coin'],
            $received,
            $becomesPaid
        );
        return [CallbackResult::acknowledged(), new Totals($received, [...$totals->txids, $fields['txid_in']], $becomesPaid)];
    }
}
