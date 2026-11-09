<?php

declare(strict_types=1);

namespace Suawave\Tests\Support {
    final class FakeWoo
    {
        public static array $rows = [];
        public static array $caches = [];
        public static array $ordersData = [];
        public static array $ordersMeta = [];
        public static string $request = 'main';
        public static array $scheduled = [];
        public static ?FakeDb $db = null;

        public static function reset(?FakeDb $db): void
        {
            self::$rows = [];
            self::$caches = [];
            self::$ordersData = [];
            self::$ordersMeta = [];
            self::$request = 'main';
            self::$scheduled = [];
            self::$db = $db;
        }

        public static function seed(int $id, string $status, array $meta): void
        {
            self::$rows[$id] = ['status' => $status, 'meta' => $meta, 'notes' => [], 'completed' => []];
        }

        public static function order(int $id): \WC_Order
        {
            return \WC_Order::fromRow($id, self::$rows[$id]);
        }

        public static function inRequest(string $request, callable $work): mixed
        {
            $previous = self::$request;
            self::$request = $request;
            try {
                return $work();
            } finally {
                self::$request = $previous;
            }
        }

        public static function forget(int $id): void
        {
            unset(self::$caches[self::$request][$id]);
        }

        public static function forgetDatastore(int $id): void
        {
            if (self::forgetOrdersData($id)) {
                self::forgetOrdersMeta($id);
            }
        }

        public static function forgetOrdersData(int $id): bool
        {
            $present = isset(self::$ordersData[self::$request][$id]);
            unset(self::$ordersData[self::$request][$id]);
            return $present;
        }

        public static function forgetOrdersMeta(int $id): bool
        {
            $present = isset(self::$ordersMeta[self::$request][$id]);
            unset(self::$ordersMeta[self::$request][$id]);
            return $present;
        }

        public static function datastoreRow(int $id): ?array
        {
            if (!isset(self::$rows[$id])) {
                return null;
            }
            if (self::$db !== null) {
                self::$db->log[] = 'order:read';
            }
            if (!isset(self::$ordersData[self::$request][$id])) {
                self::$ordersData[self::$request][$id] = ['status' => self::$rows[$id]['status'], 'notes' => self::$rows[$id]['notes'], 'completed' => self::$rows[$id]['completed']];
            }
            if (!isset(self::$ordersMeta[self::$request][$id])) {
                self::$ordersMeta[self::$request][$id] = self::$rows[$id]['meta'];
            }
            return self::$ordersData[self::$request][$id] + ['meta' => self::$ordersMeta[self::$request][$id]];
        }
    }

    final class FakeContainer
    {
        public function get(string $class): object
        {
            return new $class();
        }
    }
}

namespace Automattic\WooCommerce\Caches {
    use Suawave\Tests\Support\FakeWoo;

    if (!class_exists(OrderCache::class)) {
        class OrderCache
        {
            public function remove(int $id): void
            {
                FakeWoo::forget($id);
            }
        }
    }
}

namespace Automattic\WooCommerce\Internal\DataStores\Orders {
    use Suawave\Tests\Support\FakeWoo;

    if (!class_exists(OrdersTableDataStore::class)) {
        class OrdersTableDataStore
        {
            public function clear_cached_data(array $ids): array
            {
                $results = [];
                foreach ($ids as $id) {
                    $results[$id] = FakeWoo::forgetOrdersData((int) $id);
                }
                (new OrdersTableDataStoreMeta())->clear_cached_data(array_keys(array_filter($results)));
                return $results;
            }
        }
    }

    if (!class_exists(OrdersTableDataStoreMeta::class)) {
        class OrdersTableDataStoreMeta
        {
            public function clear_cached_data(array $ids): array
            {
                $results = [];
                foreach ($ids as $id) {
                    $results[$id] = FakeWoo::forgetOrdersMeta((int) $id);
                }
                return $results;
            }
        }
    }
}

namespace {
    use Suawave\Tests\Support\FakeWoo;

    if (!class_exists('WC_Order')) {
        class WC_Order
        {
            public array $meta = [];
            public array $notes = [];
            public array $completedWith = [];
            public string $status = 'pending';
            private array $changes = [];
            private array $deletes = [];
            private bool $statusChanged = false;

            public function __construct(private int $id, private string $method = 'suawave', private string $total = '100.00')
            {
            }

            public static function fromRow(int $id, array $row): self
            {
                $order = new self($id);
                $order->status = $row['status'];
                $order->meta = $row['meta'];
                $order->notes = $row['notes'];
                $order->completedWith = $row['completed'];
                return $order;
            }

            public function get_id(): int
            {
                return $this->id;
            }

            public function get_order_number(): string
            {
                return (string) $this->id;
            }

            public function get_payment_method(): string
            {
                return $this->method;
            }

            public function get_total(): string
            {
                return $this->total;
            }

            public function get_currency(): string
            {
                return 'USD';
            }

            public function get_meta(string $key): mixed
            {
                return $this->meta[$key] ?? '';
            }

            public function read_meta_data(bool $force = false): void
            {
                $row = $force ? FakeWoo::datastoreRow($this->id) : null;
                if ($row !== null) {
                    $this->meta = array_merge($row['meta'], $this->changes);
                    foreach (array_keys($this->deletes) as $key) {
                        unset($this->meta[$key]);
                    }
                }
            }

            public function update_meta_data(string $key, mixed $value): void
            {
                $this->meta[$key] = $value;
                $this->changes[$key] = $value;
                unset($this->deletes[$key]);
            }

            public function delete_meta_data(string $key): void
            {
                unset($this->meta[$key], $this->changes[$key]);
                $this->deletes[$key] = true;
            }

            public function add_order_note(string $note): int
            {
                $this->notes[] = $note;
                FakeWoo::$rows[$this->id]['notes'][] = $note;
                return count($this->notes);
            }

            public function has_status(string|array $status): bool
            {
                return in_array($this->status, (array) $status, true);
            }

            public function is_paid(): bool
            {
                return in_array($this->status, ['processing', 'completed'], true);
            }

            public function update_status(string $status, string $note = ''): bool
            {
                if ($status !== $this->status) {
                    $this->add_order_note(trim($note . ' Order status changed from ' . $this->status . ' to ' . $status . '.'));
                    $this->status = $status;
                    $this->statusChanged = true;
                }
                $this->save();
                return true;
            }

            public function payment_complete(string $transactionId = ''): bool
            {
                $this->completedWith[] = $transactionId;
                FakeWoo::$rows[$this->id]['completed'][] = $transactionId;
                $this->status = 'processing';
                $this->statusChanged = true;
                $this->save();
                return true;
            }

            public function save(): int
            {
                if (FakeWoo::$db !== null) {
                    FakeWoo::$db->log[] = 'order:save';
                }
                $row = FakeWoo::$rows[$this->id] ?? ['status' => $this->status, 'meta' => [], 'notes' => [], 'completed' => []];
                foreach ($this->changes as $key => $value) {
                    $row['meta'][$key] = $value;
                }
                foreach (array_keys($this->deletes) as $key) {
                    unset($row['meta'][$key]);
                }
                if ($this->statusChanged) {
                    $row['status'] = $this->status;
                }
                FakeWoo::$rows[$this->id] = $row;
                FakeWoo::forgetDatastore($this->id);
                $this->changes = [];
                $this->deletes = [];
                $this->statusChanged = false;
                FakeWoo::forget($this->id);
                return $this->id;
            }
        }
    }

    if (!function_exists('wc_get_order')) {
        function wc_get_order(mixed $id): WC_Order|false
        {
            $id = (int) $id;
            if (!isset(FakeWoo::$caches[FakeWoo::$request][$id])) {
                $row = FakeWoo::datastoreRow($id);
                if ($row === null) {
                    return false;
                }
                FakeWoo::$caches[FakeWoo::$request][$id] = $row;
            }
            return WC_Order::fromRow($id, FakeWoo::$caches[FakeWoo::$request][$id]);
        }
    }

    if (!function_exists('wc_get_container')) {
        function wc_get_container(): Suawave\Tests\Support\FakeContainer
        {
            return new Suawave\Tests\Support\FakeContainer();
        }
    }

    if (!function_exists('clean_post_cache')) {
        function clean_post_cache(int $id): void
        {
            FakeWoo::forget($id);
        }
    }

    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete(int|string $key, string $group = ''): bool
        {
            return true;
        }
    }

    if (!function_exists('wp_timezone')) {
        function wp_timezone(): DateTimeZone
        {
            return new DateTimeZone('UTC');
        }
    }

    if (!function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
        {
            FakeWoo::$scheduled[] = [$timestamp, $hook, $args];
            return true;
        }
    }

    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://shop.example' . $path;
        }
    }
}
