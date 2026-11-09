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
    public array $afterPaidCalls = [];
    public array $paidBy = [];
    public int $writes = 0;
    public bool $failNextDeposit = false;

    public function __construct(private FakeDb $db)
    {
    }

    public function add(string $id, string $nonce, string $addressIn, string $coin, string $amount): void
    {
        $this->orders[$id] = ['nonce' => $nonce, 'address' => $addressIn, 'coin' => $coin, 'amount' => $amount, 'received' => '0', 'detected' => [], 'previous' => []];
    }

    public function supersede(string $id, string $nonce, string $addressIn): void
    {
        $order = $this->orders[$id];
        $this->orders[$id]['previous'][] = ['nonce' => $order['nonce'], 'address' => $order['address']];
        $this->orders[$id]['nonce'] = $nonce;
        $this->orders[$id]['address'] = $addressIn;
    }

    public function load(string $orderId, string $nonce): ?OrderRecord
    {
        $order = $this->orders[$orderId] ?? null;
        if ($order === null) {
            return null;
        }
        if (hash_equals($order['nonce'], $nonce)) {
            return new OrderRecord($order['nonce'], $order['address'], $order['coin'], $order['amount']);
        }
        foreach ($order['previous'] as $attempt) {
            if (hash_equals($attempt['nonce'], $nonce)) {
                return new OrderRecord($attempt['nonce'], $attempt['address'], $order['coin'], $order['amount'], false);
            }
        }
        return null;
    }

    public function recordAfterPaid(string $orderId, string $addressIn, string $txidIn, string $valueCoin): void
    {
        $this->db->log[] = 'store:recordAfterPaid';
        $this->writes++;
        $this->afterPaidCalls[] = [$addressIn, $txidIn, $valueCoin];
    }

    public function recordPending(string $orderId, string $txidIn): void
    {
        $this->db->log[] = 'store:recordPending';
        $this->writes++;
        $this->orders[$orderId]['detected'][] = $txidIn;
    }

    public function recordDeposit(string $orderId, OrderRecord $attempt, string $txidIn, string $txidOut, string $valueCoin, string $received, bool $paid): void
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
            $this->paidBy[] = $attempt->nonce;
        }
    }
}
