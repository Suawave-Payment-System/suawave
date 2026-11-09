<?php

declare(strict_types=1);

namespace Suawave;

use Closure;

final class ExpiryNotifier
{
    private const SWEEP_LIMIT = 50;

    public function __construct(
        private readonly PaymentStore $store,
        private readonly OrderGateway $orders,
        private readonly Closure $noteFor
    ) {
    }

    public function noteIfDue(PaymentRecord $record, int $now): bool
    {
        if (!$record->isOverdue($now) || $record->orderId <= 0) {
            return false;
        }
        return (bool) $this->store->withOrderLock($record->orderId, function () use ($record, $now): bool {
            $locked = $this->store->lockByAddressIn($record->addressIn);
            if ($locked === null || !$locked->isOverdue($now)) {
                return false;
            }
            $current = $this->store->findByOrderId($locked->orderId);
            $this->store->save($locked->withExpiryNoted($now));
            if ($current === null || $current->addressIn !== $locked->addressIn || $this->store->findPaidByOrderId($locked->orderId) !== null) {
                return false;
            }
            $this->orders->addCustomerNote($locked->orderId, ($this->noteFor)($locked));
            return true;
        });
    }

    public function sweep(int $now): int
    {
        $noted = 0;
        for ($step = 0; $step < self::SWEEP_LIMIT; $step++) {
            $record = $this->store->findOverdue($now);
            if ($record === null) {
                break;
            }
            if ($this->noteIfDue($record, $now)) {
                $noted++;
            }
        }
        return $noted;
    }
}
