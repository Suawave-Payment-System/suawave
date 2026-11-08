<?php

declare(strict_types=1);

namespace Suawave;

interface SqlConnection
{
    public function execute(string $sql): void;

    public function fetchFirstRow(string $sql): ?array;

    public function affectedRows(): int;

    public function escape(string $value): string;
}
