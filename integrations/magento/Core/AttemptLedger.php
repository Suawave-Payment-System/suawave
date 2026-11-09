<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

interface AttemptLedger extends PaymentLedger
{
    public function replaceAttempt(string $orderReference, array $opened): void;

    public function recordExpiry(string $orderReference, string $nonce, string $note): void;
}
