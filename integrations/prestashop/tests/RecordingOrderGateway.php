<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\OrderGateway;
use RuntimeException;

final class RecordingOrderGateway implements OrderGateway
{
    public array $paidOrders = [];
    public array $paidComments = [];
    public array $notes = [];
    public array $labels = [];
    public array $sequence = [];
    public $probe = null;
    public bool $failNext = false;

    public function markPaid(int $orderId, string $comment): void
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('order history failed');
        }
        $this->paidOrders[] = $orderId;
        $this->paidComments[] = $comment;
        $this->sequence[] = 'paid:' . $orderId;
    }

    public function relabel(int $orderId, string $label): void
    {
        $this->labels[] = [$orderId, $label];
        $this->sequence[] = 'label:' . $label;
        if ($this->probe !== null) {
            ($this->probe)($label);
        }
    }

    public function addNote(int $orderId, string $comment): void
    {
        $this->notes[] = [$orderId, $comment];
    }
}
