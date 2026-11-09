<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class Money
{
    private const PATTERN = '/^[0-9]{1,30}(\.[0-9]{1,18})?$/';

    public static function isDecimal(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public static function compare(string $left, string $right): int
    {
        self::requireDecimal($left);
        self::requireDecimal($right);
        return bccomp($left, $right, self::scale($left, $right));
    }

    public static function add(string $left, string $right): string
    {
        self::requireDecimal($left);
        self::requireDecimal($right);
        return bcadd($left, $right, self::scale($left, $right));
    }

    public static function isPositive(string $value): bool
    {
        return self::isDecimal($value) && self::compare($value, '0') > 0;
    }

    public static function display(string $value, ?int $places): string
    {
        self::requireDecimal($value);
        $scale = self::fractionLength($value);
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
        self::requireDecimal($value);
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
        $digits = self::fractionLength($value);
        return $digits < 2 ? $value . str_repeat('0', 2 - $digits) : $value;
    }

    private static function scale(string $left, string $right): int
    {
        return max(self::fractionLength($left), self::fractionLength($right));
    }

    private static function fractionLength(string $value): int
    {
        $position = strpos($value, '.');
        return $position === false ? 0 : strlen($value) - $position - 1;
    }

    private static function requireDecimal(string $value): void
    {
        if (!self::isDecimal($value)) {
            throw new \InvalidArgumentException('not a plain decimal string');
        }
    }
}
