<?php

declare(strict_types=1);

namespace Suawave;

use DateTimeZone;

final class PaymentLabel
{
    public const PAY_BEFORE = 'pay before';
    public const CLOSED = 'payment window closed';
    public const PAID = 'paid';

    private $translate;

    public function __construct(
        private readonly string $name,
        private readonly DateTimeZone $zone,
        ?callable $translate = null
    ) {
        $this->translate = $translate ?? static fn (string $text): string => $text;
    }

    public function open(?int $expiresAt): string
    {
        if ($expiresAt === null) {
            return $this->name;
        }
        return $this->name . ' - ' . ($this->translate)(self::PAY_BEFORE) . ' ' . PaymentWindow::format($expiresAt, $this->zone);
    }

    public function closed(): string
    {
        return $this->name . ' - ' . ($this->translate)(self::CLOSED);
    }

    public function paid(): string
    {
        return $this->name . ' - ' . ($this->translate)(self::PAID);
    }
}
