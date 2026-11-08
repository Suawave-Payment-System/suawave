<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class OrderRecord
{
    public function __construct(
        public readonly string $nonce,
        public readonly string $addressIn,
        public readonly string $coin,
        public readonly string $amount
    ) {
    }
}
