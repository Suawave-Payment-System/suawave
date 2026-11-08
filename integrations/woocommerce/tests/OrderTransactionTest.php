<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\CallbackProcessor;
use Suawave\CallbackResult;
use Suawave\Tests\Support\FakeDb;
use Suawave\Tests\Support\MemoryOrders;
use Suawave\WpLedger;
use Suawave\WpOrderLock;
use PHPUnit\Framework\TestCase;

final class OrderTransactionTest extends TestCase
{
    private const NONCE = '7f3a9c0000000000000000000000aaaa';
    private const ADDRESS = 'TDepositAddressExample0000000000';
    private const TOTALS_ROW = 'suawave_totals_1001';

    private FakeDb $db;
    private MemoryOrders $orders;
    private CallbackProcessor $processor;

    protected function setUp(): void
    {
        $this->db = new FakeDb();
        $this->orders = new MemoryOrders($this->db);
        $this->orders->add('1001', self::NONCE, self::ADDRESS, 'trc20_usdt', '100.00');
        $this->processor = new CallbackProcessor($this->orders, new WpLedger($this->db), new WpOrderLock($this->db));
    }

    private function confirmed(string $uuid, string $txid, string $value): CallbackResult
    {
        return $this->processor->process(['order' => '1001', 'nonce' => self::NONCE], [
            'uuid' => $uuid, 'address_in' => self::ADDRESS, 'coin' => 'trc20_usdt', 'pending' => '0',
            'txid_in' => $txid, 'txid_out' => 'out', 'value_coin' => $value,
        ]);
    }

    private function startsWith(string $prefix): array
    {
        return array_filter($this->db->log, static fn (string $entry): bool => str_starts_with($entry, $prefix));
    }

    public function testReadSumDecideAndWriteHappenInsideTheLockedTransaction(): void
    {
        $this->assertSame(200, $this->confirmed('aaaaaaaa-0000-0000-0000-000000000001', 'tx1', '100.000000')->status);
        $log = $this->db->log;
        $begin = array_search('START TRANSACTION', $log, true);
        $commit = array_search('COMMIT', $log, true);
        $lock = array_key_first($this->startsWith('SELECT option_value FROM wp_options WHERE option_name = %s FOR UPDATE'));
        $claim = array_key_last($this->startsWith('INSERT IGNORE'));
        $store = array_search('store:recordDeposit', $log, true);
        $update = array_key_first($this->startsWith('UPDATE'));

        $this->assertNotFalse($begin);
        $this->assertSame($begin + 1, $lock, 'the first statement of the transaction is the FOR UPDATE read');
        $this->assertGreaterThan($lock, $claim, 'the uuid is claimed after the lock');
        $this->assertGreaterThan($claim, $store);
        $this->assertGreaterThan($store, $update);
        $this->assertGreaterThan($update, $commit);
        $this->assertSame(1, count(array_keys($log, 'COMMIT', true)));
        $this->assertNotContains('ROLLBACK', $log);
        $this->assertLessThan($begin, array_search('SET TRANSACTION ISOLATION LEVEL READ COMMITTED', $log, true));
        $this->assertSame(['1001'], $this->orders->paidCalls);
    }

    public function testInvoiceAndWooOrderWithTheSameReferenceKeepSeparateTotals(): void
    {
        $invoices = new MemoryOrders($this->db);
        $invoices->add('1001', self::NONCE, self::ADDRESS, 'trc20_usdt', '100.00');
        $invoiceProcessor = new CallbackProcessor($invoices, new WpLedger($this->db), new WpOrderLock($this->db));
        $fields = static fn (string $uuid, string $txid): array => [
            'uuid' => $uuid, 'address_in' => self::ADDRESS, 'coin' => 'trc20_usdt', 'pending' => '0',
            'txid_in' => $txid, 'txid_out' => 'out', 'value_coin' => '50.000000',
        ];

        $invoiceProcessor->process(['order' => '1001', 'nonce' => self::NONCE, 'src' => 'wp'], $fields('aaaaaaaa-1111-0000-0000-000000000001', 'txa'));
        $this->processor->process(['order' => '1001', 'nonce' => self::NONCE, 'src' => 'woo'], $fields('aaaaaaaa-1111-0000-0000-000000000002', 'txb'));

        $this->assertSame([], $invoices->paidCalls);
        $this->assertSame([], $this->orders->paidCalls);
        $this->assertSame('50.000000', json_decode($this->db->rows['suawave_totals_wp_1001'], true)['received']);
        $this->assertSame('50.000000', json_decode($this->db->rows[self::TOTALS_ROW], true)['received']);

        $invoiceProcessor->process(['order' => '1001', 'nonce' => self::NONCE, 'src' => 'wp'], $fields('aaaaaaaa-1111-0000-0000-000000000003', 'txc'));

        $this->assertSame(['1001'], $invoices->paidCalls);
        $this->assertSame([], $this->orders->paidCalls);
        $this->assertSame('50.000000', json_decode($this->db->rows[self::TOTALS_ROW], true)['received']);
    }

    public function testRepeatedUuidIsANoOpInsideTheTransaction(): void
    {
        $this->confirmed('aaaaaaaa-0000-0000-0000-000000000001', 'tx1', '100.000000');
        $depositsBefore = count($this->orders->depositCalls);
        $updatesBefore = count($this->startsWith('UPDATE'));
        $this->assertSame(200, $this->confirmed('aaaaaaaa-0000-0000-0000-000000000001', 'tx1', '100.000000')->status);
        $this->assertSame($depositsBefore, count($this->orders->depositCalls));
        $this->assertSame($updatesBefore, count($this->startsWith('UPDATE')));
        $this->assertSame(['1001'], $this->orders->paidCalls);
    }

    public function testTwoDifferentUuidsAreEachCreditedOnceAndPayOnce(): void
    {
        $this->confirmed('bbbbbbbb-0000-0000-0000-000000000001', 'tx1', '60.000000');
        $this->confirmed('bbbbbbbb-0000-0000-0000-000000000002', 'tx2', '40.000000');
        $this->assertSame(['tx1', 'tx2'], $this->orders->depositCalls);
        $this->assertSame('100.000000', $this->orders->orders['1001']['received']);
        $this->assertSame(['1001'], $this->orders->paidCalls);
        $totals = json_decode($this->db->rows[self::TOTALS_ROW], true);
        $this->assertSame(['received' => '100.000000', 'txids' => ['tx1', 'tx2'], 'paid' => true], $totals);
    }

    public function testDepositAfterThePaidTransitionNeverPaysAgain(): void
    {
        $this->confirmed('cccccccc-0000-0000-0000-000000000001', 'tx1', '100.000000');
        $this->confirmed('cccccccc-0000-0000-0000-000000000002', 'tx2', '5.000000');
        $this->assertSame(['tx1', 'tx2'], $this->orders->depositCalls);
        $this->assertSame('105.000000', $this->orders->orders['1001']['received']);
        $this->assertCount(1, $this->orders->paidCalls);
    }

    public function testSameTxidUnderTwoUuidsIsTwoRealDepositsAndBothAreCredited(): void
    {
        $this->confirmed('dddddddd-0000-0000-0000-000000000001', 'tx1', '60.000000');
        $this->confirmed('dddddddd-0000-0000-0000-000000000002', 'tx1', '40.000000');
        $this->assertSame(['tx1', 'tx1'], $this->orders->depositCalls);
        $this->assertSame('100.000000', json_decode($this->db->rows[self::TOTALS_ROW], true)['received']);
        $this->assertSame(['1001'], $this->orders->paidCalls);
    }

    public function testFailureRollsBackTheUuidClaimSoTheRetryApplies(): void
    {
        $this->orders->failNextDeposit = true;
        $this->assertSame(500, $this->confirmed('eeeeeeee-0000-0000-0000-000000000001', 'tx1', '100.000000')->status);
        $this->assertContains('ROLLBACK', $this->db->log);
        $this->assertNotContains('COMMIT', $this->db->log);
        $this->assertArrayNotHasKey('suawave_ledger_eeeeeeee-0000-0000-0000-000000000001', $this->db->rows);
        $this->assertSame(200, $this->confirmed('eeeeeeee-0000-0000-0000-000000000001', 'tx1', '100.000000')->status);
        $this->assertSame(['1001'], $this->orders->paidCalls);
    }

    public function testFailedCommitIsReportedAndRolledBack(): void
    {
        $this->db->failCommit = true;
        $this->assertSame(500, $this->confirmed('ffffffff-0000-0000-0000-000000000001', 'tx1', '100.000000')->status);
        $this->assertContains('ROLLBACK', $this->db->log);
        $this->assertSame('0', json_decode($this->db->rows[self::TOTALS_ROW], true)['received']);
    }

    public function testUnavailableLockAnswers503AndWritesNothing(): void
    {
        $this->db->failLock = true;
        $this->assertSame(503, $this->confirmed('99999999-0000-0000-0000-000000000001', 'tx1', '100.000000')->status);
        $this->assertSame(0, $this->orders->writes);
        $this->assertContains('ROLLBACK', $this->db->log);
        $this->assertSame([], $this->orders->paidCalls);
    }
}
