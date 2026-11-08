<?php

declare(strict_types=1);

namespace Suawave;

interface PaymentStore
{
    public function findByOrderId(int $orderId): ?PaymentRecord;

    public function findByAddressIn(string $addressIn): ?PaymentRecord;

    public function lockByAddressIn(string $addressIn): ?PaymentRecord;

    public function save(PaymentRecord $record): void;

    public function claimEvent(string $uuid, string $addressIn): bool;

    public function transactional(callable $work): mixed;
}
