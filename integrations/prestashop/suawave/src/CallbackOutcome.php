<?php

declare(strict_types=1);

namespace Suawave;

final class CallbackOutcome
{
    public const ACKNOWLEDGEMENT = '*ok*';

    public function __construct(
        public readonly int $httpStatus,
        public readonly string $body,
        public readonly string $reason
    ) {
    }

    public static function acknowledged(string $reason): self
    {
        return new self(200, self::ACKNOWLEDGEMENT, $reason);
    }

    public static function rejected(int $httpStatus, string $reason): self
    {
        return new self($httpStatus, $reason, $reason);
    }
}
