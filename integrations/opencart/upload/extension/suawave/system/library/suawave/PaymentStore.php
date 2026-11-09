<?php

declare(strict_types=1);

namespace Suawave;

interface PaymentStore
{
    public function findByOrderId(int $orderId): ?PaymentRecord;

    public function findPaidByOrderId(int $orderId): ?PaymentRecord;

    public function findByAddressIn(string $addressIn): ?PaymentRecord;

    public function lockByAddressIn(string $addressIn): ?PaymentRecord;

    public function findOverdue(int $now): ?PaymentRecord;

    public function save(PaymentRecord $record): void;

    public function claimEvent(string $uuid, string $addressIn): bool;

    public function transactional(callable $work): mixed;

    public function withOrderLock(int $orderId, callable $work): mixed;
}
