<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\PaymentRecord;
use Suawave\PaymentStore;
use LogicException;
use Throwable;

final class InMemoryPaymentStore implements PaymentStore
{
    public array $records = [];
    public array $events = [];
    public array $operations = [];
    private bool $inTransaction = false;
    private array $lockedAddresses = [];
    private array $heldOrders = [];
    public ?\Closure $afterUnlock = null;

    public function findByOrderId(int $orderId): ?PaymentRecord
    {
        $found = null;
        foreach ($this->records as $record) {
            if ($record->orderId === $orderId) {
                $found = $record;
            }
        }
        return $found;
    }

    public function findPaidByOrderId(int $orderId): ?PaymentRecord
    {
        foreach ($this->records as $record) {
            if ($record->orderId === $orderId && $record->status === PaymentRecord::PAID) {
                return $record;
            }
        }
        return null;
    }

    public function findOverdue(int $now): ?PaymentRecord
    {
        foreach ($this->records as $record) {
            if ($record->orderId > 0 && $record->isOverdue($now)) {
                return $record;
            }
        }
        return null;
    }

    public function findByAddressIn(string $addressIn): ?PaymentRecord
    {
        return $this->records[$addressIn] ?? null;
    }

    public function lockByAddressIn(string $addressIn): ?PaymentRecord
    {
        if (!$this->inTransaction) {
            throw new LogicException('the row lock needs an open transaction');
        }
        $this->operations[] = 'lock:' . $addressIn;
        $this->lockedAddresses[$addressIn] = true;
        return $this->records[$addressIn] ?? null;
    }

    public function save(PaymentRecord $record): void
    {
        $this->requireLock($record->addressIn, $record->orderId);
        $this->operations[] = 'save:' . $record->status . ':' . $record->received;
        $this->records[$record->addressIn] = $record;
    }

    public function claimEvent(string $uuid, string $addressIn): bool
    {
        $this->requireLock($addressIn);
        $this->operations[] = 'claim:' . $uuid;
        if (isset($this->events[$uuid])) {
            return false;
        }
        $this->events[$uuid] = $addressIn;
        return true;
    }

    public function transactional(callable $work): mixed
    {
        $records = $this->records;
        $events = $this->events;
        $this->inTransaction = true;
        $this->lockedAddresses = [];
        $this->operations[] = 'begin';
        try {
            $result = $work();
        } catch (Throwable $failure) {
            $this->records = $records;
            $this->events = $events;
            $this->operations[] = 'rollback';
            throw $failure;
        } finally {
            $this->inTransaction = false;
        }
        $this->operations[] = 'commit';
        return $result;
    }

    public function withOrderLock(int $orderId, callable $work): mixed
    {
        if (isset($this->heldOrders[$orderId])) {
            throw new LogicException('the order lock is already held, a second request would wait here');
        }
        $this->operations[] = 'order-lock:' . $orderId;
        $this->heldOrders[$orderId] = true;
        try {
            return $this->transactional($work);
        } finally {
            unset($this->heldOrders[$orderId]);
            $this->operations[] = 'order-unlock:' . $orderId;
            $next = $this->afterUnlock;
            $this->afterUnlock = null;
            if ($next !== null) {
                $next();
            }
        }
    }

    public function holdsOrderLock(int $orderId): bool
    {
        return isset($this->heldOrders[$orderId]);
    }

    private function requireLock(string $addressIn, ?int $orderId = null): void
    {
        if (!$this->inTransaction) {
            return;
        }
        if (!isset($this->lockedAddresses[$addressIn])) {
            throw new LogicException('writes must happen under the row lock of the payment');
        }
        $orderId ??= isset($this->records[$addressIn]) ? $this->records[$addressIn]->orderId : null;
        if ($orderId !== null && !isset($this->heldOrders[$orderId])) {
            throw new LogicException('writes must happen under the order lock');
        }
    }
}
