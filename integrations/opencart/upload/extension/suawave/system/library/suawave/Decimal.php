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

    public static function fromOrderTotal(string $total, string $currencyValue, int $places): string
    {
        self::require($total);
        self::require($currencyValue);
        if ($places < 0 || $places > 18) {
            throw new InvalidArgumentException('places out of range');
        }
        $product = bcmul($total, $currencyValue, 24);
        $half = $places === 0 ? '0.5' : '0.' . str_repeat('0', $places) . '5';
        $rounded = bcadd($product, $half, 24);
        return bcadd($rounded, '0', $places);
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
