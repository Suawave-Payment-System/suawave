<?php

declare(strict_types=1);

namespace Suawave;

final class PaymentRecord
{
    public const AWAITING = 'awaiting';
    public const DETECTED = 'detected';
    public const PAID = 'paid';
    public const EXPIRED = 'expired';
    public const SUPERSEDED = 'superseded';

    public function __construct(
        public readonly int $cartId,
        public readonly int $orderId,
        public readonly string $nonce,
        public readonly string $ticker,
        public readonly string $addressIn,
        public readonly string $payoutAddress,
        public readonly string $amount,
        public readonly string $minimum,
        public readonly string $received,
        public readonly string $status,
        public readonly ?int $expiresAt = null
    ) {
    }

    public function with(string $received, string $status): self
    {
        return new self(
            $this->cartId,
            $this->orderId,
            $this->nonce,
            $this->ticker,
            $this->addressIn,
            $this->payoutAddress,
            $this->amount,
            $this->minimum,
            $received,
            $status,
            $this->expiresAt
        );
    }

    public function withOrder(int $orderId): self
    {
        return new self(
            $this->cartId,
            $orderId,
            $this->nonce,
            $this->ticker,
            $this->addressIn,
            $this->payoutAddress,
            $this->amount,
            $this->minimum,
            $this->received,
            $this->status,
            $this->expiresAt
        );
    }

    public function isOpen(int $now): bool
    {
        return ($this->status === self::AWAITING || $this->status === self::DETECTED)
            && !PaymentWindow::isClosed($this->expiresAt, $now);
    }

    public function isOverdue(int $now): bool
    {
        return ($this->status === self::AWAITING || $this->status === self::DETECTED)
            && PaymentWindow::isClosed($this->expiresAt, $now);
    }
}
