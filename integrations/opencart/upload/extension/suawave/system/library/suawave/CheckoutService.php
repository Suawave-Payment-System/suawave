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

    public function begin(int $orderId, string $amount): PaymentRecord
    {
        if (!Decimal::isPositive($amount)) {
            throw new GatewayException('the order amount is not a positive decimal');
        }
        $existing = $this->store->findByOrderId($orderId);
        if ($existing !== null && $existing->amount === $amount && $existing->ticker === $this->ticker) {
            return $existing;
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
            PaymentRecord::AWAITING
        );
        if ($existing !== null && $existing->status !== PaymentRecord::PAID && $existing->status !== PaymentRecord::SUPERSEDED) {
            $this->store->save($existing->with($existing->received, PaymentRecord::SUPERSEDED));
        }
        $this->store->save($record);
        return $record;
    }
}
