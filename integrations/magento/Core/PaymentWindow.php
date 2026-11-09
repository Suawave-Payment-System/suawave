<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class PaymentWindow
{
    public static function parse(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new GatewayException('gateway returned an invalid expires_at');
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception) {
            throw new GatewayException('gateway returned an invalid expires_at');
        }
    }

    public static function fromStored(mixed $stored): ?int
    {
        return is_string($stored) && preg_match('/^[0-9]{1,12}$/D', $stored) === 1 ? (int) $stored : null;
    }

    public static function isClosed(?int $expiresAt, int $now): bool
    {
        return $expiresAt !== null && $now >= $expiresAt;
    }

    public static function noteDue(?int $expiresAt, bool $paid, bool $alreadyNoted, int $now): bool
    {
        return !$paid && !$alreadyNoted && self::isClosed($expiresAt, $now);
    }
}
