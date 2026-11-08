<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\OrderGateway;
use RuntimeException;

final class RecordingOrderGateway implements OrderGateway
{
    public array $paidOrders = [];
    public bool $failNext = false;

    public function markPaid(int $orderId, string $comment): void
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('order history failed');
        }
        $this->paidOrders[] = $orderId;
    }
}
