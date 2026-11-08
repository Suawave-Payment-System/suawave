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

    public function findByCartId(int $cartId): ?PaymentRecord
    {
        $found = null;
        foreach ($this->records as $record) {
            if ($record->cartId === $cartId) {
                $found = $record;
            }
        }
        return $found;
    }

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
        $this->requireLock($record->addressIn);
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

    private function requireLock(string $addressIn): void
    {
        if ($this->inTransaction && !isset($this->lockedAddresses[$addressIn])) {
            throw new LogicException('writes must happen under the row lock of the payment');
        }
    }
}
