<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

interface OrderLock
{
    public function transactional(string $key, callable $work): mixed;
}
