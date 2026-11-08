<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class Decimal
{
    private const PATTERN = '/^[0-9]+(\.[0-9]+)?$/D';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public static function assertValid(string $value): string
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException('not a plain decimal string');
        }
        return $value;
    }

    public static function fromStorage(string|int|float $value): string
    {
        if (is_string($value)) {
            return self::assertValid(trim($value));
        }
        return self::assertValid(number_format($value, 8, '.', ''));
    }

    public static function compare(string $left, string $right): int
    {
        self::assertValid($left);
        self::assertValid($right);
        return bccomp($left, $right, max(self::fractionDigits($left), self::fractionDigits($right)));
    }

    public static function add(string $left, string $right): string
    {
        self::assertValid($left);
        self::assertValid($right);
        return bcadd($left, $right, max(self::fractionDigits($left), self::fractionDigits($right)));
    }

    public static function atLeast(string $amount, string $threshold): bool
    {
        return self::compare($amount, $threshold) >= 0;
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function twoPlaces(string $value): string
    {
        self::assertValid($value);
        return bcadd(bcadd($value, '0.005', 3), '0', 2);
    }

    private static function fractionDigits(string $value): int
    {
        $position = strpos($value, '.');
        return $position === false ? 0 : strlen($value) - $position - 1;
    }
}
