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

    public function query(string $sql): object
    {
        $this->queries[] = $sql;
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
