<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

interface Ledger
{
    public function claim(string $key): bool;
}
