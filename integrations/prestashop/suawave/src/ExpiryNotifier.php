<?php

declare(strict_types=1);

namespace Suawave;

final class ExpiryNotifier
{
    private const SWEEP_LIMIT = 50;

    public function __construct(
        private readonly PaymentStore $store,
        private readonly OrderGateway $orders,
        private readonly string $noteText,
        private readonly ?PaymentLabel $labels = null
    ) {
    }

    public function noteIfDue(PaymentRecord $record, int $now): bool
    {
        if (!$record->isOverdue($now) || $record->orderId <= 0) {
            return false;
        }
        return (bool) $this->store->transactional(function () use ($record, $now): bool {
            $rows = $this->store->lockCart($record->cartId);
            $locked = CartAttempts::current($rows);
            if ($locked === null
                || $locked->addressIn !== $record->addressIn
                || CartAttempts::paid($rows) !== null
                || !$locked->isOverdue($now)
            ) {
                return false;
            }
            $this->orders->addNote(CartAttempts::orderId($rows), $this->noteText);
            if ($this->labels !== null) {
                $this->orders->relabel(CartAttempts::orderId($rows), $this->labels->closed());
            }
            $this->store->save($locked->with($locked->received, PaymentRecord::EXPIRED));
            return true;
        });
    }

    public function sweep(int $now): int
    {
        $noted = 0;
        for ($step = 0; $step < self::SWEEP_LIMIT; $step++) {
            $record = $this->store->findOverdue($now);
            if ($record === null || !$this->noteIfDue($record, $now)) {
                break;
            }
            $noted++;
        }
        return $noted;
    }
}
