<?php

declare(strict_types=1);

namespace Suawave;

use Db;
use RuntimeException;

final class PrestashopConnection implements SqlConnection
{
    public function execute(string $sql): void
    {
        $db = Db::getInstance();
        if ($db->execute($sql, false) === false) {
            throw new RuntimeException('database statement failed: ' . $db->getMsgError());
        }
    }

    public function fetchFirstRow(string $sql): ?array
    {
        $db = Db::getInstance();
        $rows = $db->executeS($sql, true, false);
        if ($rows === false) {
            throw new RuntimeException('database query failed: ' . $db->getMsgError());
        }
        return $rows === [] ? null : $rows[0];
    }

    public function fetchRows(string $sql): array
    {
        $db = Db::getInstance();
        $rows = $db->executeS($sql, true, false);
        if ($rows === false) {
            throw new RuntimeException('database query failed: ' . $db->getMsgError());
        }
        return $rows;
    }

    public function affectedRows(): int
    {
        return (int) Db::getInstance()->Affected_Rows();
    }

    public function escape(string $value): string
    {
        return pSQL($value);
    }
}
