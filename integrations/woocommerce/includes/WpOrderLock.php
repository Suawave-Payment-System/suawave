<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class WpOrderLock implements OrderLock
{
    private const PREFIX = 'suawave_totals_';

    public function __construct(private object $db)
    {
    }

    public function transactional(string $key, callable $work): mixed
    {
        $name = self::PREFIX . $key;
        $this->db->query($this->db->prepare(
            "INSERT IGNORE INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            Totals::empty()->toJson()
        ));
        $this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        if ($this->db->query('START TRANSACTION') === false) {
            throw new LockUnavailable('cannot start a transaction');
        }
        try {
            $stored = $this->db->get_var($this->db->prepare(
                "SELECT option_value FROM {$this->db->options} WHERE option_name = %s FOR UPDATE",
                $name
            ));
            if (!is_string($stored)) {
                throw new LockUnavailable('cannot lock the order totals');
            }
            $totals = Totals::fromJson($stored);
            [$result, $updated] = $work($totals);
            if ($updated->toJson() !== $totals->toJson()) {
                $written = $this->db->query($this->db->prepare(
                    "UPDATE {$this->db->options} SET option_value = %s WHERE option_name = %s",
                    $updated->toJson(),
                    $name
                ));
                if ($written === false) {
                    throw new \RuntimeException('cannot store the order totals');
                }
            }
            if ($this->db->query('COMMIT') === false) {
                throw new \RuntimeException('commit failed');
            }
            return $result;
        } catch (\Throwable $failure) {
            $this->db->query('ROLLBACK');
            throw $failure;
        }
    }
}
