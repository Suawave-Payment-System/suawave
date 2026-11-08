<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

interface OrderStore
{
    public function load(string $orderId): ?OrderRecord;

    public function recordPending(string $orderId, string $txidIn): void;

    public function recordDeposit(string $orderId, string $txidIn, string $txidOut, string $valueCoin, string $received, bool $paid): void;
}
