<?php

declare(strict_types=1);

namespace Suawave;

use PrestaShopLogger;
use Throwable;

final class PrestashopFailureLog implements FailureLog
{
    public function record(Throwable $failure): void
    {
        PrestaShopLogger::addLog(
            'suawave callback failed: ' . get_class($failure) . ': ' . $failure->getMessage() . ' at ' . $failure->getFile() . ':' . $failure->getLine(),
            3
        );
    }
}
