<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class Totals
{
    public function __construct(
        public readonly string $received,
        public readonly array $txids,
        public readonly bool $paid
    ) {
    }

    public static function empty(): self
    {
        return new self('0', [], false);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['received'] ?? null) || !Money::isDecimal($data['received']) || !is_array($data['txids'] ?? null) || !is_bool($data['paid'] ?? null)) {
            throw new \RuntimeException('corrupt totals row');
        }
        return new self($data['received'], array_values($data['txids']), $data['paid']);
    }

    public function toJson(): string
    {
        return (string) json_encode(['received' => $this->received, 'txids' => $this->txids, 'paid' => $this->paid]);
    }
}
