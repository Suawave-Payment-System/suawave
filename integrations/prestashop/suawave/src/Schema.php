<?php

declare(strict_types=1);

namespace Suawave;

final class Schema
{
    public const VERSION = '2';

    private const COLUMNS = [
        'expires_at' => 'ADD COLUMN `expires_at` INT UNSIGNED NULL DEFAULT NULL AFTER `status`, ADD KEY `expires_at` (`expires_at`)',
    ];

    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $prefix
    ) {
    }

    public function install(): void
    {
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'suawave_payment` ('
            . '`id_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`id_cart` INT UNSIGNED NOT NULL, '
            . '`id_order` INT UNSIGNED NOT NULL DEFAULT 0, '
            . '`nonce` VARCHAR(64) NOT NULL, '
            . '`ticker` VARCHAR(32) NOT NULL, '
            . '`address_in` VARCHAR(128) NOT NULL, '
            . '`payout_address` VARCHAR(128) NOT NULL, '
            . '`amount` VARCHAR(64) NOT NULL, '
            . '`minimum` VARCHAR(64) NOT NULL, '
            . "`received` VARCHAR(64) NOT NULL DEFAULT '0', "
            . '`status` VARCHAR(16) NOT NULL, '
            . '`expires_at` INT UNSIGNED NULL DEFAULT NULL, '
            . '`date_add` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`id_payment`), '
            . 'UNIQUE KEY `address_in` (`address_in`), '
            . 'KEY `id_cart` (`id_cart`), '
            . 'KEY `id_order` (`id_order`), '
            . 'KEY `expires_at` (`expires_at`)'
            . ') ' . $engine
        );
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'suawave_event` ('
            . '`uuid` VARCHAR(64) NOT NULL, '
            . '`address_in` VARCHAR(128) NOT NULL, '
            . '`date_add` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`uuid`)'
            . ') ' . $engine
        );
        $this->upgrade();
    }

    public function upgrade(): int
    {
        $added = 0;
        if (!$this->hasTable('suawave_payment')) {
            return $added;
        }
        foreach (self::COLUMNS as $column => $change) {
            if ($this->hasColumn('suawave_payment', $column)) {
                continue;
            }
            $this->db->execute('ALTER TABLE `' . $this->prefix . 'suawave_payment` ' . $change);
            $added++;
        }
        return $added;
    }

    private function hasTable(string $table): bool
    {
        $row = $this->db->fetchFirstRow(
            'SELECT COUNT(*) AS `found` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()'
            . " AND `TABLE_NAME` = '" . $this->db->escape($this->prefix . $table) . "'"
        );
        return $row !== null && (int) $row['found'] > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $row = $this->db->fetchFirstRow(
            'SELECT COUNT(*) AS `found` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE()'
            . " AND `TABLE_NAME` = '" . $this->db->escape($this->prefix . $table) . "'"
            . " AND `COLUMN_NAME` = '" . $this->db->escape($column) . "'"
        );
        return $row !== null && (int) $row['found'] > 0;
    }
}
