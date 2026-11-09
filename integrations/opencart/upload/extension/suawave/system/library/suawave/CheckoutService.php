<?php

declare(strict_types=1);

namespace Suawave;

final class CheckoutService
{
    public function __construct(
        private readonly GatewayClient $client,
        private readonly PaymentStore $store,
        private readonly string $ticker,
        private readonly string $payoutAddress,
        private readonly string $callbackBaseUrl,
        private $clock = null
    ) {
    }

    public function begin(int $orderId, string $amount, ?callable $underLock = null): PaymentRecord
    {
        if (!Decimal::isPositive($amount)) {
            throw new GatewayException('the order amount is not a positive decimal');
        }
        $existing = $this->store->findPaidByOrderId($orderId) ?? $this->store->findByOrderId($orderId);
        if (!$this->needsNewAttempt($existing, $amount)) {
            if ($underLock === null) {
                return $existing;
            }
            $kept = $this->store->withOrderLock($orderId, function () use ($orderId, $amount, $underLock): ?PaymentRecord {
                $current = $this->store->findPaidByOrderId($orderId) ?? $this->store->findByOrderId($orderId);
                if ($this->needsNewAttempt($current, $amount)) {
                    return null;
                }
                $underLock($current, false);
                return $current;
            });
            if ($kept !== null) {
                return $kept;
            }
        }
        $nonce = bin2hex(random_bytes(16));
        $callbackUrl = $this->callbackBaseUrl
            . (str_contains($this->callbackBaseUrl, '?') ? '&' : '?')
            . http_build_query(['order' => (string) $orderId, 'nonce' => $nonce], '', '&', PHP_QUERY_RFC3986);
        $created = $this->client->createAddress($this->ticker, $this->payoutAddress, $callbackUrl);
        if (Decimal::compare($amount, $created->minimum) < 0) {
            throw new GatewayException('the order amount is below the minimum of ' . $created->minimum);
        }
        $record = new PaymentRecord(
            $orderId,
            $nonce,
            $this->ticker,
            $created->addressIn,
            $created->addressOut,
            $amount,
            $created->minimum,
            '0',
            PaymentRecord::AWAITING,
            $created->expiresAt
        );
        return $this->store->withOrderLock($orderId, function () use ($orderId, $amount, $record, $underLock): PaymentRecord {
            $current = $this->store->findPaidByOrderId($orderId) ?? $this->store->findByOrderId($orderId);
            if (!$this->needsNewAttempt($current, $amount)) {
                if ($underLock !== null) {
                    $underLock($current, false);
                }
                return $current;
            }
            if ($current !== null) {
                $locked = $this->store->lockByAddressIn($current->addressIn);
                if ($locked !== null && $locked->status !== PaymentRecord::PAID) {
                    $this->store->save($locked->with($locked->received, PaymentRecord::SUPERSEDED));
                }
            }
            $this->store->lockByAddressIn($record->addressIn);
            $this->store->save($record);
            if ($underLock !== null) {
                $underLock($record, $current !== null);
            }
            return $record;
        });
    }

    private function needsNewAttempt(?PaymentRecord $current, string $amount): bool
    {
        if ($current === null) {
            return true;
        }
        if ($current->status === PaymentRecord::PAID) {
            return false;
        }
        if ($current->status === PaymentRecord::SUPERSEDED || $current->amount !== $amount || $current->ticker !== $this->ticker) {
            return true;
        }
        return $current->status !== PaymentRecord::DETECTED && $current->isClosed($this->now());
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }
}
