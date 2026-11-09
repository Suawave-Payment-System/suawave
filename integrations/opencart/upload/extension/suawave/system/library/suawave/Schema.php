<?php

declare(strict_types=1);

namespace Suawave;

final class Schema
{
    private const PAYMENT_COLUMNS = [
        'expires_at' => 'INT UNSIGNED NULL DEFAULT NULL',
        'expiry_noted_at' => 'INT UNSIGNED NULL DEFAULT NULL',
    ];

    private const PAYMENT_INDEXES = [
        'expires_at' => '`expires_at`',
    ];

    public function __construct(
        private readonly object $db,
        private readonly string $prefix
    ) {
    }

    public function install(): void
    {
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'suawave_payment` ('
            . '`payment_id` INT(11) NOT NULL AUTO_INCREMENT, '
            . '`order_id` INT(11) NOT NULL, '
            . '`nonce` VARCHAR(64) NOT NULL, '
            . '`ticker` VARCHAR(64) NOT NULL, '
            . '`address_in` VARCHAR(128) NOT NULL, '
            . '`payout_address` VARCHAR(128) NOT NULL, '
            . '`amount` VARCHAR(100) NOT NULL, '
            . '`minimum` VARCHAR(100) NOT NULL, '
            . '`received` VARCHAR(100) NOT NULL DEFAULT "0", '
            . '`status` VARCHAR(16) NOT NULL DEFAULT "awaiting", '
            . '`expires_at` INT UNSIGNED NULL DEFAULT NULL, '
            . '`expiry_noted_at` INT UNSIGNED NULL DEFAULT NULL, '
            . '`date_added` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`payment_id`), '
            . 'UNIQUE KEY `address_in` (`address_in`), '
            . 'KEY `order_id` (`order_id`), '
            . 'KEY `expires_at` (`expires_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'
        );
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'suawave_event` ('
            . '`uuid` VARCHAR(64) NOT NULL, '
            . '`address_in` VARCHAR(128) NOT NULL, '
            . '`date_added` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`uuid`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'
        );
        $this->upgrade();
    }

    public function upgrade(): void
    {
        $table = $this->prefix . 'suawave_payment';
        foreach (self::PAYMENT_COLUMNS as $column => $definition) {
            if (!$this->exists('COLUMNS', 'COLUMN_NAME', $table, $column)) {
                $this->db->query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
            }
        }
        foreach (self::PAYMENT_INDEXES as $index => $columns) {
            if (!$this->exists('STATISTICS', 'INDEX_NAME', $table, $index)) {
                $this->db->query('ALTER TABLE `' . $table . '` ADD KEY `' . $index . '` (' . $columns . ')');
            }
        }
    }

    private function exists(string $view, string $nameColumn, string $table, string $name): bool
    {
        $result = $this->db->query(
            'SELECT COUNT(*) AS `found` FROM `information_schema`.`' . $view . '` WHERE `TABLE_SCHEMA` = DATABASE() '
            . 'AND `TABLE_NAME` = "' . $this->db->escape($table) . '" AND `' . $nameColumn . '` = "' . $this->db->escape($name) . '"'
        );
        return (int) ($result->row['found'] ?? 0) > 0;
    }
}
