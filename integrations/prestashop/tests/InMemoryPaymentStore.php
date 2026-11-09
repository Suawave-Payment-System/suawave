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
    private array $lockedCarts = [];
    public $onLock = null;
    public $onCommit = null;

    public function findByCartId(int $cartId): ?PaymentRecord
    {
        return $this->paidFirst(array_filter($this->records, static fn (PaymentRecord $row): bool => $row->cartId === $cartId));
    }

    public function findByOrderId(int $orderId): ?PaymentRecord
    {
        return $this->paidFirst(array_filter($this->records, static fn (PaymentRecord $row): bool => $row->orderId === $orderId));
    }

    private function paidFirst(array $rows): ?PaymentRecord
    {
        foreach ($rows as $row) {
            if ($row->status === PaymentRecord::PAID) {
                return $row;
            }
        }
        $last = end($rows);
        return $last === false ? null : $last;
    }

    public function findByAddressIn(string $addressIn): ?PaymentRecord
    {
        return $this->records[$addressIn] ?? null;
    }

    public function lockCart(int $cartId): array
    {
        if (!$this->inTransaction) {
            throw new LogicException('the row lock needs an open transaction');
        }
        $this->operations[] = 'lock:' . $cartId;
        $this->lockedCarts[$cartId] = true;
        if ($this->onLock !== null) {
            ($this->onLock)($cartId);
        }
        return $this->rowsOf($cartId);
    }

    public function findOverdue(int $now): ?PaymentRecord
    {
        foreach ($this->records as $record) {
            $rows = $this->rowsOf($record->cartId);
            if ($record->orderId > 0
                && $record->isOverdue($now)
                && end($rows) === $record
                && array_filter($rows, static fn (PaymentRecord $row): bool => $row->status === PaymentRecord::PAID) === []
            ) {
                return $record;
            }
        }
        return null;
    }

    public function rowsOf(int $cartId): array
    {
        return array_values(array_filter($this->records, static fn (PaymentRecord $row): bool => $row->cartId === $cartId));
    }

    public function save(PaymentRecord $record): void
    {
        $this->requireLock($record->cartId);
        $this->operations[] = 'save:' . $record->status . ':' . $record->received;
        $this->records[$record->addressIn] = $record;
    }

    public function claimEvent(string $uuid, string $addressIn): bool
    {
        $this->requireLock(($this->records[$addressIn] ?? null)?->cartId ?? -1);
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
        if ($this->inTransaction) {
            throw new LogicException('transactions do not nest');
        }
        $this->inTransaction = true;
        $this->lockedCarts = [];
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
        if ($this->onCommit !== null) {
            $hook = $this->onCommit;
            $this->onCommit = null;
            $hook();
        }
        return $result;
    }

    private function requireLock(int $cartId): void
    {
        if ($this->inTransaction && !isset($this->lockedCarts[$cartId])) {
            throw new LogicException('writes must happen under the row lock of the payment');
        }
    }
}
