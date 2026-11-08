<?php

declare(strict_types=1);

namespace Suawave\Tests\Support;

final class FakeDb
{
    public string $options = 'wp_options';
    public array $log = [];
    public array $rows = [];
    public bool $failLock = false;
    public bool $failCommit = false;
    private ?array $snapshot = null;

    public function prepare(string $sql, mixed ...$args): string
    {
        return $sql . "\x00" . json_encode($args);
    }

    public function query(string $statement): int|bool
    {
        [$sql, $args] = $this->split($statement);
        $this->log[] = $sql;
        if ($sql === 'START TRANSACTION') {
            $this->snapshot = $this->rows;
            return 0;
        }
        if ($sql === 'COMMIT') {
            if ($this->failCommit) {
                return false;
            }
            $this->snapshot = null;
            return 0;
        }
        if ($sql === 'ROLLBACK') {
            if ($this->snapshot !== null) {
                $this->rows = $this->snapshot;
                $this->snapshot = null;
            }
            return 0;
        }
        if (str_starts_with($sql, 'INSERT IGNORE')) {
            if (array_key_exists($args[0], $this->rows)) {
                return 0;
            }
            $this->rows[$args[0]] = $args[1] ?? '1';
            return 1;
        }
        if (str_starts_with($sql, 'UPDATE')) {
            $this->rows[$args[1]] = $args[0];
            return 1;
        }
        return 0;
    }

    public function get_var(string $statement): ?string
    {
        [$sql, $args] = $this->split($statement);
        $this->log[] = $sql;
        if ($this->failLock) {
            return null;
        }
        return $this->rows[$args[0]] ?? null;
    }

    public function statementsLike(string $prefix): array
    {
        return array_keys(array_filter($this->log, static fn (string $entry): bool => str_starts_with($entry, $prefix)));
    }

    private function split(string $statement): array
    {
        $parts = explode("\x00", $statement, 2);
        return [$parts[0], isset($parts[1]) ? json_decode($parts[1], true) : []];
    }
}
