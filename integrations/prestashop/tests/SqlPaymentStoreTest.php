<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\CallbackProcessor;
use Suawave\CallbackRequest;
use Suawave\SqlConnection;
use Suawave\SqlPaymentStore;
use PHPUnit\Framework\TestCase;

final class SqlPaymentStoreTest extends TestCase
{
    private const ORIGIN = 'https://shop.example';
    private const PATH = '/index.php?fc=module&module=suawave&controller=callback';
    private const ADDRESS = 'TDepositAddressExample0000000000';
    private const PAYOUT = 'TXyzPayoutAddressExample0000000000000';
    private const NONCE = '7f3a9c0d1e2b4a5c8d9e0f1a2b3c4d5e';

    private TestKeyPair $pair;
    private FakeSqlConnection $db;
    private RecordingOrderGateway $orders;
    private CallbackProcessor $processor;

    protected function setUp(): void
    {
        $this->pair = TestKeyPair::generate();
        $this->db = new FakeSqlConnection();
        $this->db->row = [
            'id_cart' => '1001', 'id_order' => '2001', 'nonce' => self::NONCE, 'ticker' => 'trc20/usdt',
            'address_in' => self::ADDRESS, 'payout_address' => self::PAYOUT, 'amount' => '100.00',
            'minimum' => '10.000000', 'received' => '40.000000', 'status' => 'detected',
        ];
        $this->orders = new RecordingOrderGateway();
        $this->processor = new CallbackProcessor(
            new StaticKeyProvider($this->pair->publicPem, $this->pair->publicPem),
            self::ORIGIN,
            new SqlPaymentStore($this->db, 'ps_'),
            $this->orders
        );
    }

    public function testConfirmedCallbackRunsStartLockClaimWriteCommitInThatOrder(): void
    {
        $outcome = $this->processor->process($this->signedCallback('uuid-1', '60.000000', '0'));

        $this->assertSame('paid', $outcome->reason);
        $this->assertSame(
            ['START TRANSACTION', 'SELECT', 'SELECT FOR UPDATE', 'INSERT IGNORE ps_suawave_event', 'INSERT ps_suawave_payment', 'COMMIT'],
            $this->db->kinds()
        );
        $this->assertSame([2001], $this->orders->paidOrders);
        $this->assertStringContainsString("`received` = '100.000000'", $this->db->statements[4]);
        $this->assertStringContainsString("`status` = 'paid'", $this->db->statements[4]);
    }

    public function testTheLockedSelectTargetsThePaymentRowAndTheClaimUsesTheUuid(): void
    {
        $this->processor->process($this->signedCallback('uuid-77', '60.000000', '0'));

        $this->assertStringContainsString('WHERE `p`.`address_in` = \'' . self::ADDRESS . '\' LIMIT 1', $this->db->statements[1]);
        $this->assertStringNotContainsString('FOR UPDATE', $this->db->statements[1]);
        $this->assertStringContainsString('FROM `ps_suawave_payment` WHERE `id_cart` = 1001 ORDER BY `id_payment` ASC FOR UPDATE', $this->db->statements[2]);
        $this->assertStringContainsString("`uuid` = 'uuid-77'", $this->db->statements[3]);
        $this->assertStringNotContainsString('txid_in', $this->db->statements[3]);
    }

    public function testRepeatedUuidWritesNothingAfterTheClaim(): void
    {
        $this->db->affected = 0;

        $outcome = $this->processor->process($this->signedCallback('uuid-1', '60.000000', '0'));

        $this->assertSame('duplicate', $outcome->reason);
        $this->assertSame(['START TRANSACTION', 'SELECT', 'SELECT FOR UPDATE', 'INSERT IGNORE ps_suawave_event', 'COMMIT'], $this->db->kinds());
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testPartialDepositWritesTheSumWithoutPaying(): void
    {
        $this->processor->process($this->signedCallback('uuid-1', '10.500000', '0'));

        $this->assertStringContainsString("`received` = '50.500000'", $this->db->statements[4]);
        $this->assertStringContainsString("`status` = 'detected'", $this->db->statements[4]);
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testPendingCallbackUsesTheSameLockedSequenceAndNeverPays(): void
    {
        $this->db->row['status'] = 'awaiting';
        $this->db->row['received'] = '0';

        $this->processor->process($this->signedCallback('uuid-p', '', '1'));

        $this->assertSame(
            ['START TRANSACTION', 'SELECT', 'SELECT FOR UPDATE', 'INSERT IGNORE ps_suawave_event', 'INSERT ps_suawave_payment', 'COMMIT'],
            $this->db->kinds()
        );
        $this->assertStringContainsString("`status` = 'detected'", $this->db->statements[4]);
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testFailureWhileMarkingPaidRollsBackAndNeverCommits(): void
    {
        $this->orders->failNext = true;

        $outcome = $this->processor->process($this->signedCallback('uuid-1', '60.000000', '0'));

        $this->assertSame(500, $outcome->httpStatus);
        $this->assertSame(['START TRANSACTION', 'SELECT', 'SELECT FOR UPDATE', 'INSERT IGNORE ps_suawave_event', 'ROLLBACK'], $this->db->kinds());
    }

    public function testRejectedSignatureTouchesNoStatement(): void
    {
        $uri = self::PATH . '&cart=1001&nonce=' . self::NONCE . '&uuid=u&address_in=' . self::ADDRESS;

        $outcome = $this->processor->process(new CallbackRequest('GET', $uri, '', '', null));

        $this->assertSame(401, $outcome->httpStatus);
        $this->assertSame([], $this->db->statements);
    }

    public function testOrderAndCartLookupsReturnThePayingAttemptFirst(): void
    {
        $store = new SqlPaymentStore($this->db, 'ps_');

        $store->findByCartId(1001);
        $store->findByOrderId(2001);

        $this->assertStringEndsWith("WHERE `p`.`id_cart` = 1001 ORDER BY `p`.`status` = 'paid' DESC, `p`.`id_payment` DESC LIMIT 1", $this->db->statements[0]);
        $this->assertStringEndsWith("WHERE `p`.`id_order` = 2001 ORDER BY `p`.`status` = 'paid' DESC, `p`.`id_payment` DESC LIMIT 1", $this->db->statements[1]);
    }

    public function testPlainReadsTakeNoLock(): void
    {
        $store = new SqlPaymentStore($this->db, 'ps_');

        $store->findByCartId(1001);
        $store->findByOrderId(2001);
        $store->findByAddressIn(self::ADDRESS);

        foreach ($this->db->statements as $statement) {
            $this->assertStringNotContainsString('FOR UPDATE', $statement);
        }
    }

    public function testValuesAreEscapedBeforeTheyReachTheStatement(): void
    {
        $store = new SqlPaymentStore($this->db, 'ps_');

        $store->findByAddressIn("x' OR '1'='1");

        $this->assertStringContainsString("x\\' OR \\'1\\'=\\'1", $this->db->statements[0]);
    }

    private function signedCallback(string $uuid, string $value, string $pending): CallbackRequest
    {
        $fields = [
            'cart' => '1001', 'nonce' => self::NONCE,
            'uuid' => $uuid, 'address_in' => self::ADDRESS, 'address_out' => self::PAYOUT,
            'coin' => 'trc20_usdt', 'pending' => $pending,
        ];
        if ($pending === '0') {
            $fields['value_coin'] = $value;
        }
        $uri = self::PATH . '&' . http_build_query($fields);
        return new CallbackRequest('GET', $uri, '', '', $this->pair->sign(self::ORIGIN . $uri));
    }
}

final class FakeSqlConnection implements SqlConnection
{
    public array $statements = [];
    public ?array $row = null;
    public ?array $rows = null;
    public array $answers = [];
    public int $affected = 1;

    public function execute(string $sql): void
    {
        $this->statements[] = $sql;
    }

    public function fetchFirstRow(string $sql): ?array
    {
        $this->statements[] = $sql;
        foreach ($this->answers as $needle => $answer) {
            if (str_contains($sql, $needle)) {
                return $answer;
            }
        }
        return $this->row;
    }

    public function fetchRows(string $sql): array
    {
        $this->statements[] = $sql;
        return $this->rows ?? ($this->row === null ? [] : [$this->row]);
    }

    public function affectedRows(): int
    {
        return $this->affected;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function kinds(): array
    {
        return array_map(static function (string $sql): string {
            if (str_starts_with($sql, 'SELECT')) {
                return str_contains($sql, 'FOR UPDATE') ? 'SELECT FOR UPDATE' : 'SELECT';
            }
            if (str_starts_with($sql, 'INSERT')) {
                preg_match('/`(ps_[a-z_]+)`/', $sql, $table);
                return (str_starts_with($sql, 'INSERT IGNORE') ? 'INSERT IGNORE ' : 'INSERT ') . $table[1];
            }
            return $sql;
        }, $this->statements);
    }
}
