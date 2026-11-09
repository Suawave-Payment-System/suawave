<?php

declare(strict_types=1);

namespace Suawave;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class PaymentWindow
{
    public static function parse(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new GatewayException('the gateway answer has an invalid expires_at');
        }
        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Exception) {
            throw new GatewayException('the gateway answer has an invalid expires_at');
        }
    }

    public static function isClosed(?int $expiresAt, int $now): bool
    {
        return $expiresAt !== null && $now >= $expiresAt;
    }

    public static function format(int $expiresAt, DateTimeZone $zone): string
    {
        return (new DateTimeImmutable('@' . $expiresAt))->setTimezone($zone)->format('Y-m-d H:i (e)');
    }
}
