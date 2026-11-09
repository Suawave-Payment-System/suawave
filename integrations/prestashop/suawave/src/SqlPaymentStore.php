<?php

declare(strict_types=1);

namespace Suawave;

use Throwable;

final class SqlPaymentStore implements PaymentStore
{
    private const PAID_FIRST = " ORDER BY `p`.`status` = '" . PaymentRecord::PAID . "' DESC, `p`.`id_payment` DESC";

    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $prefix
    ) {
    }

    public function findByCartId(int $cartId): ?PaymentRecord
    {
        return $this->select('`p`.`id_cart` = ' . $cartId . self::PAID_FIRST);
    }

    public function findByOrderId(int $orderId): ?PaymentRecord
    {
        return $this->select('`p`.`id_order` = ' . $orderId . self::PAID_FIRST);
    }

    public function findByAddressIn(string $addressIn): ?PaymentRecord
    {
        return $this->select('`p`.`address_in` = ' . $this->quote($addressIn));
    }

    public function lockCart(int $cartId): array
    {
        $rows = $this->db->fetchRows(
            'SELECT * FROM `' . $this->prefix . 'suawave_payment` WHERE `id_cart` = ' . $cartId . ' ORDER BY `id_payment` ASC FOR UPDATE'
        );
        return array_map(static fn (array $row): PaymentRecord => self::toRecord($row), $rows);
    }

    public function findOverdue(int $now): ?PaymentRecord
    {
        $table = '`' . $this->prefix . 'suawave_payment`';
        return $this->select(
            "`p`.`status` IN ('" . PaymentRecord::AWAITING . "', '" . PaymentRecord::DETECTED . "') AND `p`.`id_order` > 0 AND `p`.`expires_at` IS NOT NULL AND `p`.`expires_at` <= " . $now
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $table . ' `n` WHERE `n`.`id_cart` = `p`.`id_cart` AND (`n`.`id_payment` > `p`.`id_payment` OR `n`.`status` = \'' . PaymentRecord::PAID . '\'))'
            . ' ORDER BY `p`.`id_payment` ASC'
        );
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
            . '`expires_at` = ' . ($record->expiresAt === null ? 'NULL' : (string) $record->expiresAt) . ', '
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

    private function select(string $condition): ?PaymentRecord
    {
        $row = $this->db->fetchFirstRow(
            'SELECT `p`.* FROM `' . $this->prefix . 'suawave_payment` `p` WHERE ' . $condition . ' LIMIT 1'
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
            (string) $row['status'],
            isset($row['expires_at']) && $row['expires_at'] !== '' ? (int) $row['expires_at'] : null
        );
    }
}
