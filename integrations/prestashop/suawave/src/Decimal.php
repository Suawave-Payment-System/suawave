<?php

declare(strict_types=1);

namespace Suawave;

use InvalidArgumentException;

final class Decimal
{
    private const PATTERN = '/^[0-9]+(\.[0-9]+)?$/';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1 && strlen($value) <= 100;
    }

    public static function compare(string $left, string $right): int
    {
        self::require($left);
        self::require($right);
        return bccomp($left, $right, max(self::scaleOf($left), self::scaleOf($right)));
    }

    public static function add(string $left, string $right): string
    {
        self::require($left);
        self::require($right);
        return bcadd($left, $right, max(self::scaleOf($left), self::scaleOf($right)));
    }

    public static function isPositive(string $value): bool
    {
        return self::isValid($value) && self::compare($value, '0') > 0;
    }

    private static function scaleOf(string $value): int
    {
        $position = strpos($value, '.');
        return $position === false ? 0 : strlen($value) - $position - 1;
    }

    private static function require(string $value): void
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('not a plain decimal string');
        }
    }
}
