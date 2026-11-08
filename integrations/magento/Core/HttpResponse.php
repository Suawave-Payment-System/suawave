<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body
    ) {
    }
}
