<?php

declare(strict_types=1);

namespace Opencart\System\Engine {
    if (!class_exists(Controller::class, false)) {
        class Controller
        {
            public function __construct(protected object $registry)
            {
            }

            public function __get(string $key): mixed
            {
                return $this->registry->get($key);
            }

            public function __set(string $key, mixed $value): void
            {
                $this->registry->set($key, $value);
            }
        }
    }
}

namespace Suawave\Tests\Support {
    final class Registry
    {
        private array $values = [];

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }

        public function set(string $key, mixed $value): void
        {
            $this->values[$key] = $value;
        }
    }

    final class Loader
    {
        public function __construct(private readonly Registry $registry, private readonly array $models)
        {
        }

        public function language(string $route): void
        {
        }

        public function model(string $route): void
        {
            $this->registry->set('model_' . str_replace('/', '_', $route), $this->models[$route]);
        }

        public function view(string $route, array $data = []): string
        {
            return '';
        }
    }

    final class Config
    {
        public function __construct(private readonly array $values)
        {
        }

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }
    }

    final class Language
    {
        public function __construct(private readonly array $texts)
        {
        }

        public function get(string $key): string
        {
            return $this->texts[$key] ?? $key;
        }
    }

    final class Response
    {
        public array $headers = [];
        public string $output = '';

        public function addHeader(string $header): void
        {
            $this->headers[] = $header;
        }

        public function setOutput(string $output): void
        {
            $this->output = $output;
        }
    }

    final class Log
    {
        public array $lines = [];

        public function write(string $line): void
        {
            $this->lines[] = $line;
        }
    }

    final class Session
    {
        public function __construct(public array $data)
        {
        }
    }

    final class Order
    {
        public array $history = [];
        public ?\Closure $onHistory = null;

        public function __construct(public int $statusId, public readonly string $total)
        {
        }

        public function addHistory(int $orderId, int $statusId, string $comment = '', bool $notify = false): void
        {
            $this->history[] = ['order' => $orderId, 'status' => $statusId, 'comment' => $comment, 'notify' => $notify];
            $this->statusId = $statusId;
            if ($this->onHistory !== null) {
                ($this->onHistory)();
            }
        }

        public function row(): array
        {
            return ['total' => $this->total, 'currency_value' => '1.00000000', 'currency_code' => 'USD', 'order_status_id' => (string) $this->statusId];
        }
    }

    final class CheckoutModel
    {
        public function __construct(private readonly Order $order)
        {
        }

        public function getOrderAmounts(int $orderId): ?array
        {
            return [
                'total' => $this->order->total,
                'currency_value' => '1.00000000',
                'currency_code' => 'USD',
                'order_status_id' => $this->order->statusId,
            ];
        }
    }

    final class OrderDb
    {
        public function __construct(private readonly Order $order)
        {
        }

        public function query(string $sql): object
        {
            return (object) ['num_rows' => 1, 'row' => $this->order->row()];
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }

        public function countAffected(): int
        {
            return 1;
        }
    }
}
