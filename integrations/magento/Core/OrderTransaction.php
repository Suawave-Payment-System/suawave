<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

interface OrderTransaction
{
    public function runLocked(string $orderReference, callable $work): mixed;
}
