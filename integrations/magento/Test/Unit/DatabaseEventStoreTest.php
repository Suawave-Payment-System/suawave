<?php

declare(strict_types=1);

namespace Magento\Framework\App {
    if (!class_exists(ResourceConnection::class, false)) {
        class ResourceConnection
        {
            public function getConnection()
            {
                return null;
            }

            public function getTableName(string $name): string
            {
                return $name;
            }
        }
    }
}

namespace Suawave\Payment\Test\Unit {

    use Suawave\Payment\Model\DatabaseEventStore;
    use Magento\Framework\App\ResourceConnection;
    use PHPUnit\Framework\TestCase;

    final class FakeStatement
    {
        public function __construct(private int $rows)
        {
        }

        public function rowCount(): int
        {
            return $this->rows;
        }
    }

    final class FakeConnection
    {
        public array $rows = [];
        public array $queries = [];

        public function quoteIdentifier(string $name): string
        {
            return '`' . $name . '`';
        }

        public function query(string $sql, array $bind): FakeStatement
        {
            $this->queries[] = $sql;
            if (isset($this->rows[$bind[0]])) {
                return new FakeStatement(0);
            }
            $this->rows[$bind[0]] = $bind;
            return new FakeStatement(1);
        }
    }

    final class FakeResource extends ResourceConnection
    {
        public function __construct(private FakeConnection $connection)
        {
        }

        public function getConnection()
        {
            return $this->connection;
        }

        public function getTableName(string $name): string
        {
            return 'prefix_' . $name;
        }
    }

    final class DatabaseEventStoreTest extends TestCase
    {
        public function testFirstClaimSucceedsAndReplayIsRefused(): void
        {
            $connection = new FakeConnection();
            $store = new DatabaseEventStore(new FakeResource($connection));

            self::assertTrue($store->claim('uuid-1', '000000001', '0xabc'));
            self::assertFalse($store->claim('uuid-1', '000000001', '0xabc'));
            self::assertTrue($store->claim('uuid-2', '000000001', '0xdef'));
            self::assertCount(2, $connection->rows);
            self::assertStringContainsString('INSERT IGNORE INTO `prefix_suawave_callback_event`', $connection->queries[0]);
        }

        public function testDiPreferencesResolveToExistingClasses(): void
        {
            $module = dirname(__DIR__, 2);
            $xml = simplexml_load_file($module . '/etc/di.xml');
            self::assertNotFalse($xml);
            $count = 0;
            foreach ($xml->preference as $preference) {
                $type = (string) $preference['type'];
                self::assertStringStartsWith('Suawave\\Payment\\', $type);
                $file = $module . '/' . str_replace('\\', '/', substr($type, strlen('Suawave\\Payment\\'))) . '.php';
                self::assertGreaterThan(0, (int) @filesize($file), $type);
                $source = (string) file_get_contents($file);
                self::assertMatchesRegularExpression('/\b(class|interface)\s+' . preg_quote(basename($file, '.php'), '/') . '\b/', $source);
                $count++;
            }
            self::assertGreaterThanOrEqual(5, $count);
        }
    }
}
