<?php

declare(strict_types=1);

namespace Suawave;

use Throwable;

interface FailureLog
{
    public function record(Throwable $failure): void;
}
