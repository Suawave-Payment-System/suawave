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
        private $clock = null,
        private readonly ?OrderGateway $orders = null,
        private readonly ?PaymentLabel $labels = null
    ) {
    }

    public function begin(int $cartId, string $amount): PaymentRecord
    {
        if (!Decimal::isPositive($amount)) {
            throw new GatewayException('the order amount is not a positive decimal');
        }
        $current = $this->store->findByCartId($cartId);
        if ($current !== null && ($current->status === PaymentRecord::PAID || $this->reusable($current, $amount))) {
            return $current;
        }
        $nonce = bin2hex(random_bytes(16));
        $callbackUrl = $this->callbackBaseUrl
            . (str_contains($this->callbackBaseUrl, '?') ? '&' : '?')
            . http_build_query(['cart' => (string) $cartId, 'nonce' => $nonce], '', '&', PHP_QUERY_RFC3986);
        $created = $this->client->createAddress($this->ticker, $this->payoutAddress, $callbackUrl);
        if (Decimal::compare($amount, $created->minimum) < 0) {
            throw new GatewayException('the order amount is below the minimum of ' . $created->minimum);
        }
        return $this->store->transactional(function () use ($cartId, $amount, $nonce, $created): PaymentRecord {
            $rows = $this->store->lockCart($cartId);
            $paid = CartAttempts::paid($rows);
            if ($paid !== null) {
                return $paid;
            }
            $latest = CartAttempts::current($rows);
            if ($latest !== null && $this->reusable($latest, $amount)) {
                return $latest;
            }
            if ($latest !== null) {
                $this->store->save($latest->with($latest->received, PaymentRecord::SUPERSEDED));
            }
            $orderId = CartAttempts::orderId($rows);
            $record = new PaymentRecord(
                $cartId,
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
            $this->store->save($record);
            if ($orderId > 0 && $this->orders !== null && $this->labels !== null) {
                $this->orders->relabel($orderId, $this->labels->open($record->expiresAt));
            }
            return $record;
        });
    }

    public function resume(int $cartId): ?PaymentRecord
    {
        $current = $this->store->findByCartId($cartId);
        if ($current === null) {
            return null;
        }
        return $this->begin($cartId, $current->amount);
    }

    public function attachOrder(PaymentRecord $record, int $orderId): PaymentRecord
    {
        return $this->store->transactional(function () use ($record, $orderId): PaymentRecord {
            $fresh = CartAttempts::byAddress($this->store->lockCart($record->cartId), $record->addressIn) ?? $record;
            $attached = $fresh->withOrder($orderId);
            $this->store->save($attached);
            return $attached;
        });
    }

    private function reusable(PaymentRecord $record, string $amount): bool
    {
        return $record->isOpen($this->now())
            && $record->amount === $amount
            && $record->ticker === $this->ticker;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }
}
