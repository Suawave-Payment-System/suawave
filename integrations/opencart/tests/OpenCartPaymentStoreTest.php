<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\OpenCartPaymentStore;
use PHPUnit\Framework\TestCase;

final class OpenCartPaymentStoreTest extends TestCase
{
    public function testLockedReadIsTheFirstStatementAfterBeginAndWritesFollowBeforeCommit(): void
    {
        $db = new FakeDb();
        $db->rows['SELECT'] = [
            'order_id' => '1001', 'nonce' => 'n', 'ticker' => 'trc20/usdt', 'address_in' => 'TAddr',
            'payout_address' => 'TOut', 'amount' => '100.00', 'minimum' => '10', 'received' => '40.00', 'status' => 'awaiting',
        ];
        $store = new OpenCartPaymentStore($db, 'oc_');

        $store->transactional(function () use ($store): void {
            $record = $store->lockByAddressIn('TAddr');
            $store->claimEvent('uuid-1', 'TAddr');
            $store->save($record->with('140.00', 'paid'));
        });

        $kinds = array_map(static fn (string $sql): string => strtok($sql, ' ') . (str_contains($sql, 'FOR UPDATE') ? ' FOR UPDATE' : ''), $db->queries);
        $this->assertSame(['START', 'SELECT FOR UPDATE', 'INSERT', 'INSERT', 'COMMIT'], $kinds);
    }

    public function testPlainReadTakesNoLock(): void
    {
        $db = new FakeDb();
        $store = new OpenCartPaymentStore($db, 'oc_');

        $store->findByAddressIn('TAddr');

        $this->assertStringNotContainsString('FOR UPDATE', $db->queries[0]);
    }

    public function testFailureRollsBackAndNeverCommits(): void
    {
        $db = new FakeDb();
        $store = new OpenCartPaymentStore($db, 'oc_');

        try {
            $store->transactional(static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(['START TRANSACTION', 'ROLLBACK'], $db->queries);
    }

    public function testOrderLockWrapsTheTransactionAndIsAlwaysReleased(): void
    {
        $db = new FakeDb();
        $store = new OpenCartPaymentStore($db, 'oc_');

        $result = $store->withOrderLock(55, static fn (): string => 'done');

        $this->assertSame('done', $result);
        $this->assertCount(4, $db->queries);
        $this->assertMatchesRegularExpression('/^SELECT GET_LOCK\("suawave:[0-9a-f]{12}:55", 10\)/', $db->queries[0]);
        $this->assertSame(['START TRANSACTION', 'COMMIT'], [$db->queries[1], $db->queries[2]]);
        $this->assertStringStartsWith('SELECT RELEASE_LOCK("suawave:', $db->queries[3]);

        $db->queries = [];
        try {
            $store->withOrderLock(55, static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(['START TRANSACTION', 'ROLLBACK'], [$db->queries[1], $db->queries[2]]);
        $this->assertStringStartsWith('SELECT RELEASE_LOCK(', $db->queries[3]);
    }

    public function testOrderLockThatCannotBeAcquiredRunsNothing(): void
    {
        $db = new FakeDb();
        $db->lockAnswer = '0';
        $store = new OpenCartPaymentStore($db, 'oc_');
        $ran = false;

        try {
            $store->withOrderLock(55, static function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('the lock timeout must be reported');
        } catch (\RuntimeException) {
        }

        $this->assertFalse($ran);
        $this->assertCount(1, $db->queries);
    }

    public function testClaimReportsDuplicateFromAffectedRows(): void
    {
        $db = new FakeDb();
        $store = new OpenCartPaymentStore($db, 'oc_');

        $db->affected = 1;
        $this->assertTrue($store->claimEvent('uuid-1', 'TAddr'));
        $db->affected = 0;
        $this->assertFalse($store->claimEvent('uuid-1', 'TAddr'));
        $this->assertStringContainsString('INSERT IGNORE', $db->queries[0]);
    }
}

final class FakeDb
{
    public array $queries = [];
    public array $rows = [];
    public int $affected = 1;
    public string $lockAnswer = '1';
    public array $found = [];

    public function query(string $sql): object
    {
        $this->queries[] = $sql;
        if (str_contains($sql, 'GET_LOCK(')) {
            return (object) ['num_rows' => 1, 'row' => ['acquired' => $this->lockAnswer]];
        }
        if (str_contains($sql, 'information_schema')) {
            $hit = false;
            foreach ($this->found as $name) {
                $hit = $hit || str_contains($sql, '"' . $name . '"');
            }
            return (object) ['num_rows' => 1, 'row' => ['found' => $hit ? '1' : '0']];
        }
        $row = $this->rows[strtok($sql, ' ')] ?? [];
        return (object) ['num_rows' => $row === [] ? 0 : 1, 'row' => $row];
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function countAffected(): int
    {
        return $this->affected;
    }
}
