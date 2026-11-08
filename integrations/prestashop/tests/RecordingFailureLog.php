<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\FailureLog;
use Throwable;

final class RecordingFailureLog implements FailureLog
{
    public array $failures = [];

    public function record(Throwable $failure): void
    {
        $this->failures[] = $failure;
    }
}
