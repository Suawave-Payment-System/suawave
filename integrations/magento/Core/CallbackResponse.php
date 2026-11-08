<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class CallbackResponse
{
    public const ACKNOWLEDGEMENT = '*ok*';

    private function __construct(
        public readonly int $status,
        public readonly string $body
    ) {
    }

    public static function acknowledged(): self
    {
        return new self(200, self::ACKNOWLEDGEMENT);
    }

    public static function rejected(int $status, string $reason): self
    {
        return new self($status, $reason);
    }

    public function isAcknowledged(): bool
    {
        return $this->status === 200 && $this->body === self::ACKNOWLEDGEMENT;
    }
}
