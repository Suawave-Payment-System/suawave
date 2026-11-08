<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

interface ProcessedEventStore
{
    public function claim(string $eventUuid, string $orderReference, string $depositTransaction): bool;
}
