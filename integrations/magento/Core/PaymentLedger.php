<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

interface PaymentLedger
{
    public function find(string $orderReference): ?ExpectedPayment;

    public function applyDeposit(
        string $orderReference,
        string $eventUuid,
        string $depositTransaction,
        string $depositAmount,
        string $newReceivedTotal,
        bool $fullyPaid
    ): void;
}
