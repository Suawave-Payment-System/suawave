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
        private readonly string $callbackBaseUrl
    ) {
    }

    public function begin(int $cartId, string $amount): PaymentRecord
    {
        if (!Decimal::isPositive($amount)) {
            throw new GatewayException('the order amount is not a positive decimal');
        }
        $existing = $this->store->findByCartId($cartId);
        if ($existing !== null && $existing->amount === $amount && $existing->ticker === $this->ticker) {
            return $existing;
        }
        $nonce = bin2hex(random_bytes(16));
        $callbackUrl = $this->callbackBaseUrl
            . (str_contains($this->callbackBaseUrl, '?') ? '&' : '?')
            . http_build_query(['cart' => (string) $cartId, 'nonce' => $nonce], '', '&', PHP_QUERY_RFC3986);
        $created = $this->client->createAddress($this->ticker, $this->payoutAddress, $callbackUrl);
        if (Decimal::compare($amount, $created->minimum) < 0) {
            throw new GatewayException('the order amount is below the minimum of ' . $created->minimum);
        }
        $record = new PaymentRecord(
            $cartId,
            0,
            $nonce,
            $this->ticker,
            $created->addressIn,
            $created->addressOut,
            $amount,
            $created->minimum,
            '0',
            PaymentRecord::AWAITING
        );
        $this->store->save($record);
        return $record;
    }

    public function attachOrder(PaymentRecord $record, int $orderId): PaymentRecord
    {
        $attached = $record->withOrder($orderId);
        $this->store->save($attached);
        return $attached;
    }
}
