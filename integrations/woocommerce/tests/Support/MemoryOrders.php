<?php

declare(strict_types=1);

namespace Suawave\Tests\Support;

use Suawave\OrderRecord;
use Suawave\OrderStore;

final class MemoryOrders implements OrderStore
{
    public array $orders = [];
    public array $paidCalls = [];
    public array $depositCalls = [];
    public int $writes = 0;
    public bool $failNextDeposit = false;

    public function __construct(private FakeDb $db)
    {
    }

    public function add(string $id, string $nonce, string $addressIn, string $coin, string $amount): void
    {
        $this->orders[$id] = ['nonce' => $nonce, 'address' => $addressIn, 'coin' => $coin, 'amount' => $amount, 'received' => '0', 'detected' => []];
    }

    public function load(string $orderId): ?OrderRecord
    {
        $order = $this->orders[$orderId] ?? null;
        if ($order === null) {
            return null;
        }
        return new OrderRecord($order['nonce'], $order['address'], $order['coin'], $order['amount']);
    }

    public function recordPending(string $orderId, string $txidIn): void
    {
        $this->db->log[] = 'store:recordPending';
        $this->writes++;
        $this->orders[$orderId]['detected'][] = $txidIn;
    }

    public function recordDeposit(string $orderId, string $txidIn, string $txidOut, string $valueCoin, string $received, bool $paid): void
    {
        $this->db->log[] = 'store:recordDeposit';
        if ($this->failNextDeposit) {
            $this->failNextDeposit = false;
            throw new \RuntimeException('database down');
        }
        $this->writes++;
        $this->depositCalls[] = $txidIn;
        $this->orders[$orderId]['received'] = $received;
        if ($paid) {
            $this->paidCalls[] = $orderId;
        }
    }
}
