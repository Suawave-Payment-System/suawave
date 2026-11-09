<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class PaymentView
{
    public const PAID = 'paid';
    public const CLOSED = 'closed';
    public const OPEN = 'open';

    public static function state(ExpectedPayment $payment, int $now): string
    {
        if ($payment->paid) {
            return self::PAID;
        }
        return PaymentWindow::isClosed($payment->expiresAt, $now) ? self::CLOSED : self::OPEN;
    }

    public static function details(ExpectedPayment $payment, string $orderId, int $now, callable $deadline, callable $qr): array
    {
        $state = self::state($payment, $now);
        $formatted = $payment->expiresAt === null ? '' : (string) $deadline($payment->expiresAt);
        if ($state !== self::OPEN) {
            return ['state' => $state, 'order_id' => $orderId, 'deadline' => $formatted];
        }
        $amount = Decimal::display($payment->expectedAmount, Decimal::displayPlaces($payment->ticker));
        return [
            'state' => $state,
            'order_id' => $orderId,
            'deadline' => $formatted,
            'address' => $payment->depositAddress,
            'amount' => $amount,
            'ticker' => $payment->ticker,
            'qr' => $qr($payment->ticker, $payment->depositAddress, $amount),
        ];
    }

    public static function infoLines(ExpectedPayment $payment, string $orderId, int $now, callable $deadline, callable $translate): array
    {
        $state = self::state($payment, $now);
        if ($state === self::PAID) {
            return [(string) $translate('Payment') => (string) $translate('Received')];
        }
        if ($payment->expiresAt === null) {
            return [];
        }
        $formatted = (string) $deadline($payment->expiresAt);
        if ($state === self::CLOSED) {
            return [
                (string) $translate('Payment window closed on') => $formatted,
                (string) $translate('Order') => $orderId,
            ];
        }
        return [(string) $translate('Pay before') => $formatted];
    }
}
