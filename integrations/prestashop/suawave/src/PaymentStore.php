<?php

declare(strict_types=1);

namespace Suawave;

interface PaymentStore
{
    public function findByCartId(int $cartId): ?PaymentRecord;

    public function findByOrderId(int $orderId): ?PaymentRecord;

    public function findByAddressIn(string $addressIn): ?PaymentRecord;

    public function lockCart(int $cartId): array;

    public function findOverdue(int $now): ?PaymentRecord;

    public function save(PaymentRecord $record): void;

    public function claimEvent(string $uuid, string $addressIn): bool;

    public function transactional(callable $work): mixed;
}
