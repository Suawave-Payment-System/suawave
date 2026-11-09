<?php

declare(strict_types=1);

namespace Suawave;

use RuntimeException;
use Throwable;

final class OpenCartPaymentStore implements PaymentStore
{
    private const LOCK_WAIT_SECONDS = 10;

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

    public function findPaidByOrderId(int $orderId): ?PaymentRecord
    {
        $result = $this->db->query(
            'SELECT * FROM `' . $this->prefix . 'suawave_payment` WHERE `order_id` = ' . $orderId . ' AND `status` = "' . PaymentRecord::PAID . '" ORDER BY `payment_id` ASC LIMIT 1'
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

    public function findOverdue(int $now): ?PaymentRecord
    {
        $result = $this->db->query(
            'SELECT * FROM `' . $this->prefix . 'suawave_payment` WHERE `status` IN ("' . PaymentRecord::AWAITING . '", "' . PaymentRecord::EXPIRED . '") '
            . 'AND `order_id` > 0 AND `expiry_noted_at` IS NULL AND `expires_at` IS NOT NULL AND `expires_at` <= ' . $now . ' ORDER BY `payment_id` ASC LIMIT 1'
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
            . '`expires_at` = ' . self::nullableInt($record->expiresAt) . ', '
            . '`expiry_noted_at` = ' . self::nullableInt($record->expiryNotedAt) . ', '
            . '`date_added` = NOW() '
            . 'ON DUPLICATE KEY UPDATE '
            . '`received` = VALUES(`received`), `status` = VALUES(`status`), `expiry_noted_at` = VALUES(`expiry_noted_at`)'
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

    public function withOrderLock(int $orderId, callable $work): mixed
    {
        $name = 'suawave:' . substr(sha1($this->prefix), 0, 12) . ':' . $orderId;
        $result = $this->db->query('SELECT GET_LOCK("' . $this->db->escape($name) . '", ' . self::LOCK_WAIT_SECONDS . ') AS `acquired`');
        if ((string) ($result->row['acquired'] ?? '') !== '1') {
            throw new RuntimeException('the order lock could not be acquired');
        }
        try {
            return $this->transactional($work);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK("' . $this->db->escape($name) . '")');
        }
    }

    private static function nullableInt(?int $value): string
    {
        return $value === null ? 'NULL' : (string) $value;
    }

    private static function nullableColumn(array $row, string $name): ?int
    {
        return isset($row[$name]) && $row[$name] !== '' ? (int) $row[$name] : null;
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
            (string) $row['status'],
            self::nullableColumn($row, 'expires_at'),
            self::nullableColumn($row, 'expiry_noted_at')
        );
    }
}
