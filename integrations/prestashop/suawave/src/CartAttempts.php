<?php

declare(strict_types=1);

namespace Suawave;

final class CartAttempts
{
    private function __construct()
    {
    }

    public static function current(array $rows): ?PaymentRecord
    {
        $last = end($rows);
        return $last instanceof PaymentRecord ? $last : null;
    }

    public static function paid(array $rows): ?PaymentRecord
    {
        foreach ($rows as $row) {
            if ($row->status === PaymentRecord::PAID) {
                return $row;
            }
        }
        return null;
    }

    public static function byAddress(array $rows, string $addressIn): ?PaymentRecord
    {
        foreach ($rows as $row) {
            if ($row->addressIn === $addressIn) {
                return $row;
            }
        }
        return null;
    }

    public static function orderId(array $rows): int
    {
        $orderId = 0;
        foreach ($rows as $row) {
            $orderId = max($orderId, $row->orderId);
        }
        return $orderId;
    }
}
