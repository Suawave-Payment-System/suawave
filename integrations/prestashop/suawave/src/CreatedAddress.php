<?php

declare(strict_types=1);

namespace Suawave;

final class CreatedAddress
{
    public function __construct(
        public readonly string $addressIn,
        public readonly string $addressOut,
        public readonly string $minimum
    ) {
    }
}
