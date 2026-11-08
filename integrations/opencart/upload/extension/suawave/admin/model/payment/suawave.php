<?php

namespace Opencart\Admin\Model\Extension\Suawave\Payment;

class Suawave extends \Opencart\System\Engine\Model
{
    public function install(): void
    {
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'suawave_payment` ('
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
            . '`date_added` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`payment_id`), '
            . 'UNIQUE KEY `address_in` (`address_in`), '
            . 'KEY `order_id` (`order_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'
        );
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'suawave_event` ('
            . '`uuid` VARCHAR(64) NOT NULL, '
            . '`address_in` VARCHAR(128) NOT NULL, '
            . '`date_added` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`uuid`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'
        );
    }

    public function uninstall(): void
    {
    }
}
