<?php

declare(strict_types=1);

namespace Suawave;

use Throwable;

final class SqlPaymentStore implements PaymentStore
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $prefix
    ) {
    }

    public function findByCartId(int $cartId): ?PaymentRecord
    {
        return $this->select('`id_cart` = ' . $cartId . ' ORDER BY `id_payment` DESC', '');
    }

    public function findByOrderId(int $orderId): ?PaymentRecord
    {
        return $this->select('`id_order` = ' . $orderId . ' ORDER BY `id_payment` DESC', '');
    }

    public function findByAddressIn(string $addressIn): ?PaymentRecord
    {
        return $this->select('`address_in` = ' . $this->quote($addressIn), '');
    }

    public function lockByAddressIn(string $addressIn): ?PaymentRecord
    {
        return $this->select('`address_in` = ' . $this->quote($addressIn), ' FOR UPDATE');
    }

    public function save(PaymentRecord $record): void
    {
        $this->db->execute(
            'INSERT INTO `' . $this->prefix . 'suawave_payment` SET '
            . '`id_cart` = ' . $record->cartId . ', '
            . '`id_order` = ' . $record->orderId . ', '
            . '`nonce` = ' . $this->quote($record->nonce) . ', '
            . '`ticker` = ' . $this->quote($record->ticker) . ', '
            . '`address_in` = ' . $this->quote($record->addressIn) . ', '
            . '`payout_address` = ' . $this->quote($record->payoutAddress) . ', '
            . '`amount` = ' . $this->quote($record->amount) . ', '
            . '`minimum` = ' . $this->quote($record->minimum) . ', '
            . '`received` = ' . $this->quote($record->received) . ', '
            . '`status` = ' . $this->quote($record->status) . ', '
            . '`date_add` = NOW() '
            . 'ON DUPLICATE KEY UPDATE '
            . '`id_order` = VALUES(`id_order`), `received` = VALUES(`received`), `status` = VALUES(`status`)'
        );
    }

    public function claimEvent(string $uuid, string $addressIn): bool
    {
        $this->db->execute(
            'INSERT IGNORE INTO `' . $this->prefix . 'suawave_event` SET '
            . '`uuid` = ' . $this->quote($uuid) . ', '
            . '`address_in` = ' . $this->quote($addressIn) . ', '
            . '`date_add` = NOW()'
        );
        return $this->db->affectedRows() === 1;
    }

    public function transactional(callable $work): mixed
    {
        $this->db->execute('START TRANSACTION');
        try {
            $result = $work();
        } catch (Throwable $failure) {
            $this->db->execute('ROLLBACK');
            throw $failure;
        }
        $this->db->execute('COMMIT');
        return $result;
    }

    private function select(string $condition, string $lockClause): ?PaymentRecord
    {
        $row = $this->db->fetchFirstRow(
            'SELECT * FROM `' . $this->prefix . 'suawave_payment` WHERE ' . $condition . ' LIMIT 1' . $lockClause
        );
        return $row === null ? null : self::toRecord($row);
    }

    private function quote(string $value): string
    {
        return "'" . $this->db->escape($value) . "'";
    }

    private static function toRecord(array $row): PaymentRecord
    {
        return new PaymentRecord(
            (int) $row['id_cart'],
            (int) $row['id_order'],
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
