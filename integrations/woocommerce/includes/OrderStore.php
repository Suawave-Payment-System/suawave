<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

interface OrderStore
{
    public function load(string $orderId, string $nonce): ?OrderRecord;

    public function recordPending(string $orderId, string $txidIn): void;

    public function recordDeposit(string $orderId, OrderRecord $attempt, string $txidIn, string $txidOut, string $valueCoin, string $received, bool $paid): void;

    public function recordAfterPaid(string $orderId, string $addressIn, string $txidIn, string $valueCoin): void;
}
