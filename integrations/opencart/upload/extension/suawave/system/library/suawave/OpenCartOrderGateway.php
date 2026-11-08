<?php

declare(strict_types=1);

namespace Suawave;

final class OpenCartOrderGateway implements OrderGateway
{
    public function __construct(
        private readonly object $orderModel,
        private readonly int $paidStatusId,
        private readonly object $db,
        private readonly string $prefix,
        private readonly string $ticker,
        private readonly string $orderCurrency,
        private readonly int $places
    ) {
    }

    public function markPaid(int $orderId, string $comment): void
    {
        $this->orderModel->addHistory($orderId, $this->paidStatusId, $comment, true);
    }

    public function addNote(int $orderId, string $comment): void
    {
        $row = $this->readOrder($orderId);
        $this->orderModel->addHistory($orderId, $row === null ? 0 : (int) $row['order_status_id'], $comment, false);
    }

    public function currentPayable(int $orderId): ?array
    {
        $row = $this->readOrder($orderId);
        if ($row === null || (string) $row['currency_code'] !== $this->orderCurrency) {
            return null;
        }
        return [
            'amount' => Decimal::fromOrderTotal((string) $row['total'], (string) $row['currency_value'], $this->places),
            'ticker' => $this->ticker,
        ];
    }

    private function readOrder(int $orderId): ?array
    {
        $result = $this->db->query(
            'SELECT CAST(`total` AS CHAR) AS `total`, CAST(`currency_value` AS CHAR) AS `currency_value`, '
            . '`currency_code`, `order_status_id` FROM `' . $this->prefix . 'order` WHERE `order_id` = ' . $orderId
        );
        return $result->num_rows > 0 ? $result->row : null;
    }
}
