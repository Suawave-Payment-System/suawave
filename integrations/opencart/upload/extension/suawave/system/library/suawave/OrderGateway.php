<?php

declare(strict_types=1);

namespace Suawave;

interface OrderGateway
{
    public function markPaid(int $orderId, string $comment): void;

    public function addNote(int $orderId, string $comment): void;

    public function addCustomerNote(int $orderId, string $comment): void;

    public function currentPayable(int $orderId): ?array;
}
