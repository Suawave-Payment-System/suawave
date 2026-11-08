<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class CallbackRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $fullUrl,
        public readonly string $rawBody,
        public readonly string $contentType,
        public readonly string $signature
    ) {
    }
}
