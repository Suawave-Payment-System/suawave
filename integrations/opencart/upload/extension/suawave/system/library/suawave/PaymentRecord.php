<?php

declare(strict_types=1);

namespace Suawave;

final class PaymentRecord
{
    public const AWAITING = 'awaiting';
    public const DETECTED = 'detected';
    public const PAID = 'paid';
    public const SUPERSEDED = 'superseded';
    public const EXPIRED = 'expired';

    public function __construct(
        public readonly int $orderId,
        public readonly string $nonce,
        public readonly string $ticker,
        public readonly string $addressIn,
        public readonly string $payoutAddress,
        public readonly string $amount,
        public readonly string $minimum,
        public readonly string $received,
        public readonly string $status,
        public readonly ?int $expiresAt = null,
        public readonly ?int $expiryNotedAt = null
    ) {
    }

    public function with(string $received, string $status): self
    {
        return new self(
            $this->orderId,
            $this->nonce,
            $this->ticker,
            $this->addressIn,
            $this->payoutAddress,
            $this->amount,
            $this->minimum,
            $received,
            $status,
            $this->expiresAt,
            $this->expiryNotedAt
        );
    }

    public function withExpiryNoted(int $at): self
    {
        return new self(
            $this->orderId,
            $this->nonce,
            $this->ticker,
            $this->addressIn,
            $this->payoutAddress,
            $this->amount,
            $this->minimum,
            $this->received,
            $this->status,
            $this->expiresAt,
            $at
        );
    }

    public function isClosed(int $now): bool
    {
        return PaymentWindow::isClosed($this->expiresAt, $now);
    }

    public function isUnpaidAndClosed(int $now): bool
    {
        return ($this->status === self::AWAITING || $this->status === self::EXPIRED) && $this->isClosed($now);
    }

    public function isOverdue(int $now): bool
    {
        return $this->expiryNotedAt === null && $this->isUnpaidAndClosed($now);
    }
}
