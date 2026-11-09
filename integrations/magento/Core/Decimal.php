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

    public static function display(string $value, ?int $places): string
    {
        self::assertValid($value);
        $scale = self::fractionDigits($value);
        if ($places !== null && $scale > $places) {
            $shown = bcadd($value, '0', $places);
            if (bccomp($shown, $value, $scale) < 0) {
                $shown = bcadd($shown, $places === 0 ? '1' : '0.' . str_repeat('0', $places - 1) . '1', $places);
            }
            $value = $shown;
        }
        return self::trimmed($value);
    }

    public static function plain(string $value): string
    {
        self::assertValid($value);
        return self::trimmed($value);
    }

    public static function displayPlaces(string $ticker): ?int
    {
        $symbol = strtolower(substr($ticker, (int) strpos($ticker, '/') + 1));
        return in_array($symbol, ['usdt', 'usdc'], true) ? 6 : null;
    }

    private static function trimmed(string $value): string
    {
        if (strpos($value, '.') === false) {
            return $value;
        }
        $value = rtrim($value, '0');
        $digits = self::fractionDigits($value);
        return $digits < 2 ? $value . str_repeat('0', 2 - $digits) : $value;
    }

    private static function fractionDigits(string $value): int
    {
        $position = strpos($value, '.');
        return $position === false ? 0 : strlen($value) - $position - 1;
    }
}
