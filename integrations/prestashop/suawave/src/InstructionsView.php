<?php

declare(strict_types=1);

namespace Suawave;

final class InstructionsView
{
    private function __construct()
    {
    }

    public static function variables(
        PaymentRecord $record,
        int $orderId,
        string $orderReference,
        string $deadline,
        int $now,
        callable $qr,
        string $retryUrl,
        string $retryKey
    ): array {
        $paid = $record->status === PaymentRecord::PAID;
        $closed = !$paid && !$record->isOpen($now);
        $shown = !$paid && !$closed;
        $amount = Decimal::display($record->amount, Decimal::displayPlaces($record->ticker));
        return [
            'suawave_amount' => $amount,
            'suawave_received' => Decimal::plain($record->received),
            'suawave_ticker' => strtoupper($record->ticker),
            'suawave_address' => $shown ? $record->addressIn : '',
            'suawave_minimum' => Decimal::display($record->minimum, Decimal::displayPlaces($record->ticker)),
            'suawave_paid' => $paid,
            'suawave_detected' => $shown && $record->status === PaymentRecord::DETECTED,
            'suawave_closed' => $closed,
            'suawave_deadline' => $deadline,
            'suawave_order_id' => (string) $orderId,
            'suawave_order_reference' => $orderReference,
            'suawave_qr' => $shown ? (string) $qr($record, $amount) : '',
            'suawave_retry_url' => $closed ? $retryUrl : '',
            'suawave_retry_key' => $closed ? $retryKey : '',
        ];
    }
}
