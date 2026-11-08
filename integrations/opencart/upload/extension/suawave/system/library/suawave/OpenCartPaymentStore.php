<?php

declare(strict_types=1);

namespace Suawave;

use Throwable;

final class OpenCartPaymentStore implements PaymentStore
{
    public function __construct(
        private readonly object $db,
        private readonly string $prefix
    ) {
    }

    public function findByOrderId(int $orderId): ?PaymentRecord
    {
        $result = $this->db->query(
            'SELECT * FROM `' . $this->prefix . 'suawave_payment` WHERE `order_id` = ' . $orderId . ' ORDER BY `payment_id` DESC LIMIT 1'
        );
        return $result->num_rows > 0 ? self::toRecord($result->row) : null;
    }

    public function findByAddressIn(string $addressIn): ?PaymentRecord
    {
        return $this->selectByAddressIn($addressIn, '');
    }

    public function lockByAddressIn(string $addressIn): ?PaymentRecord
    {
        return $this->selectByAddressIn($addressIn, ' FOR UPDATE');
    }

    private function selectByAddressIn(string $addressIn, string $lockClause): ?PaymentRecord
    {
        $result = $this->db->query(
            'SELECT * FROM `' . $this->prefix . 'suawave_payment` WHERE `address_in` = "' . $this->db->escape($addressIn) . '" LIMIT 1' . $lockClause
        );
        return $result->num_rows > 0 ? self::toRecord($result->row) : null;
    }

    public function save(PaymentRecord $record): void
    {
        $this->db->query(
            'INSERT INTO `' . $this->prefix . 'suawave_payment` SET '
            . '`order_id` = ' . $record->orderId . ', '
            . '`nonce` = "' . $this->db->escape($record->nonce) . '", '
            . '`ticker` = "' . $this->db->escape($record->ticker) . '", '
            . '`address_in` = "' . $this->db->escape($record->addressIn) . '", '
            . '`payout_address` = "' . $this->db->escape($record->payoutAddress) . '", '
            . '`amount` = "' . $this->db->escape($record->amount) . '", '
            . '`minimum` = "' . $this->db->escape($record->minimum) . '", '
            . '`received` = "' . $this->db->escape($record->received) . '", '
            . '`status` = "' . $this->db->escape($record->status) . '", '
            . '`date_added` = NOW() '
            . 'ON DUPLICATE KEY UPDATE '
            . '`received` = VALUES(`received`), `status` = VALUES(`status`)'
        );
    }

    public function claimEvent(string $uuid, string $addressIn): bool
    {
        $this->db->query(
            'INSERT IGNORE INTO `' . $this->prefix . 'suawave_event` SET '
            . '`uuid` = "' . $this->db->escape($uuid) . '", '
            . '`address_in` = "' . $this->db->escape($addressIn) . '", '
            . '`date_added` = NOW()'
        );
        return $this->db->countAffected() === 1;
    }

    public function transactional(callable $work): mixed
    {
        $this->db->query('START TRANSACTION');
        try {
            $result = $work();
        } catch (Throwable $failure) {
            $this->db->query('ROLLBACK');
            throw $failure;
        }
        $this->db->query('COMMIT');
        return $result;
    }

    private static function toRecord(array $row): PaymentRecord
    {
        return new PaymentRecord(
            (int) $row['order_id'],
            (string) $row['nonce'],
            (string) $row['ticker'],
            (string) $row['address_in'],
            (string) $row['payout_address'],
            (string) $row['amount'],
            (string) $row['minimum'],
            (string) $row['received'],
            (string) $row['status']
        );
    }
}
