<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\OrderGateway;
use RuntimeException;

final class RecordingOrderGateway implements OrderGateway
{
    public array $paidOrders = [];
    public bool $failNext = false;
    public array $quotes = [];
    public array $notes = [];
    public array $paidComments = [];
    public array $customerNotes = [];

    public function markPaid(int $orderId, string $comment): void
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('order history failed');
        }
        $this->paidOrders[] = $orderId;
        $this->paidComments[] = $comment;
    }

    public function addNote(int $orderId, string $comment): void
    {
        $this->notes[] = [$orderId, $comment];
    }

    public function addCustomerNote(int $orderId, string $comment): void
    {
        $this->customerNotes[] = [$orderId, $comment];
    }

    public function currentPayable(int $orderId): ?array
    {
        return $this->quotes[$orderId] ?? null;
    }
}
