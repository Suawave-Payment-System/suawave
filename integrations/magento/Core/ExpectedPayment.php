<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class ExpectedPayment
{
    public function __construct(
        public readonly string $orderReference,
        public readonly string $nonce,
        public readonly string $depositAddress,
        public readonly string $ticker,
        public readonly string $expectedAmount,
        public readonly string $receivedAmount,
        public readonly bool $paid
    ) {
    }
}
