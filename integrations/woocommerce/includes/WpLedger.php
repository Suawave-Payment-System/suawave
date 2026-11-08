<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class WpLedger implements Ledger
{
    private const PREFIX = 'suawave_ledger_';

    public function __construct(private object $db)
    {
    }

    public function claim(string $key): bool
    {
        $inserted = $this->db->query($this->db->prepare(
            "INSERT IGNORE INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')",
            self::PREFIX . $key
        ));
        return $inserted === 1;
    }
}
