<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

final class RecordingSelect
{
    public array $wheres = [];
    public array $orders = [];
    public ?int $limit = null;

    public function join($table, $condition, $columns)
    {
        return $this;
    }

    public function where($condition, $value = null)
    {
        $this->wheres[] = [$condition, $value];
        return $this;
    }

    public function order($order)
    {
        $this->orders[] = $order;
        return $this;
    }

    public function limit($limit)
    {
        $this->limit = $limit;
        return $this;
    }
}
