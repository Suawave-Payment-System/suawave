<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class CallbackResult
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
    }

    public static function acknowledged(): self
    {
        return new self(200, '*ok*');
    }

    public static function rejected(int $status, string $reason): self
    {
        return new self($status, $reason);
    }
}
