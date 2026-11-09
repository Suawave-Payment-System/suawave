<?php

declare(strict_types=1);

namespace Suawave;

use DateTimeZone;

final class PaymentPage
{
    public function __construct(
        private readonly array $texts,
        private readonly DateTimeZone $zone
    ) {
    }

    public function deadline(?int $expiresAt): string
    {
        return $expiresAt === null ? '' : PaymentWindow::format($expiresAt, $this->zone);
    }

    public function historyComment(PaymentRecord $record): string
    {
        $deadline = $this->deadline($record->expiresAt);
        return $deadline === '' ? '' : sprintf($this->text('text_pay_before'), $deadline);
    }

    public function closedText(PaymentRecord $record): string
    {
        return sprintf($this->text('text_closed'), (string) $record->orderId, $this->deadline($record->expiresAt));
    }

    public function confirmPayload(PaymentRecord $record, int $now): array
    {
        $state = $this->state($record, $now);
        if ($state['window_closed'] || ($state['status'] !== 'waiting' && $state['status'] !== 'detected')) {
            return $state;
        }
        return $state + [
            'deadline' => $this->deadline($record->expiresAt),
            'amount' => Decimal::display($record->amount, Decimal::displayPlaces($record->ticker)),
            'address' => $record->addressIn,
            'asset' => $record->ticker,
            'qr_text' => $record->addressIn,
        ];
    }

    public function statusPayload(?PaymentRecord $record, int $now): array
    {
        return $record === null ? ['status' => 'unknown', 'window_closed' => false] : $this->state($record, $now);
    }

    private function state(PaymentRecord $record, int $now): array
    {
        if ($record->status === PaymentRecord::PAID) {
            return ['status' => 'paid', 'window_closed' => false];
        }
        $closed = $record->isClosed($now);
        if ($record->status === PaymentRecord::DETECTED) {
            return ['status' => 'detected', 'window_closed' => $closed];
        }
        if ($closed || $record->status === PaymentRecord::SUPERSEDED) {
            return ['status' => 'closed', 'window_closed' => true, 'order_id' => $record->orderId, 'closed_text' => $this->closedText($record)];
        }
        return ['status' => 'waiting', 'window_closed' => false];
    }

    private function text(string $key): string
    {
        return (string) ($this->texts[$key] ?? $key);
    }
}
