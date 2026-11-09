<?php

declare(strict_types=1);

namespace Suawave\Tests;

use PHPUnit\Framework\TestCase;
use Suawave\CallbackProcessor;
use Suawave\CallbackResult;
use Suawave\GatewayClient;
use Suawave\OrderLock;
use Suawave\Tests\Support\FakeDb;
use Suawave\Tests\Support\FakeWoo;
use Suawave\WooOrderStore;
use Suawave\WooPayments;
use Suawave\WpLedger;
use Suawave\WpOrderLock;

final class PaymentAttemptTest extends TestCase
{
    private const ORDER = 55;
    private const OLD_NONCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OLD_ADDRESS = 'TOldAddress';
    private const NEW_ADDRESS = 'TNewAddress';
    private const DEADLINE = 1_800_000_000;

    private FakeDb $db;
    private WooOrderStore $store;
    private CallbackProcessor $processor;
    private int $qrCalls = 0;
    private array $qrAmounts = [];
    private array $urls = [];
    private ?\Closure $duringCreate = null;

    protected function setUp(): void
    {
        $this->db = new FakeDb();
        FakeWoo::reset($this->db);
        $this->store = new WooOrderStore();
        $this->processor = new CallbackProcessor($this->store, new WpLedger($this->db), new WpOrderLock($this->db));
        FakeWoo::seed(self::ORDER, 'on-hold', [
            '_suawave_nonce' => self::OLD_NONCE,
            '_suawave_address' => self::OLD_ADDRESS,
            '_suawave_coin' => 'trc20_usdt',
            '_suawave_ticker' => 'trc20/usdt',
            '_suawave_amount' => '100.00',
            '_suawave_minimum' => '10.000000',
            '_suawave_received' => '0',
            '_suawave_expires_at' => (string) self::DEADLINE,
            '_suawave_qr' => 'data:image/png;base64,OLD',
        ]);
        $this->db->log = [];
    }

    private function payments(): WooPayments
    {
        return new WooPayments($this->store, new WpOrderLock($this->db), function (string $ticker, string $address, string $amount): string {
            $this->qrCalls++;
            $this->qrAmounts[] = $amount;
            return 'data:image/png;base64,QR' . $address;
        });
    }

    private function client(): GatewayClient
    {
        return new GatewayClient('https://gw.example', function (string $url): array {
            $this->urls[] = $url;
            if ($this->duringCreate !== null) {
                ($this->duringCreate)();
            }
            return [200, (string) json_encode(['status' => 'success', 'address_in' => self::NEW_ADDRESS, 'minimum_transaction_coin' => '10.000000', 'expires_at' => '2027-01-20T08:00:00Z'])];
        });
    }

    private function confirmed(string $nonce, string $address, string $uuid, string $value = '100.000000'): CallbackResult
    {
        return $this->processor->process(['order' => (string) self::ORDER, 'nonce' => $nonce, 'src' => 'woo'], [
            'uuid' => $uuid, 'address_in' => $address, 'coin' => 'trc20_usdt', 'pending' => '0',
            'txid_in' => 'tx-' . $uuid, 'txid_out' => 'out', 'value_coin' => $value,
        ]);
    }

    private function pending(string $nonce, string $address, string $uuid): CallbackResult
    {
        return $this->processor->process(['order' => (string) self::ORDER, 'nonce' => $nonce, 'src' => 'woo'], [
            'uuid' => $uuid, 'address_in' => $address, 'coin' => 'trc20_usdt', 'pending' => '1', 'txid_in' => 'tx-' . $uuid,
        ]);
    }

    private function renew(): string
    {
        $this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE + 10);
        return $this->payments()->open(FakeWoo::order(self::ORDER), $this->client(), 'trc20/usdt', 'TPayout', '100.00');
    }

    private function notesLike(string $needle): array
    {
        return array_values(array_filter(FakeWoo::order(self::ORDER)->notes, static fn (string $note): bool => str_contains($note, $needle)));
    }

    public function testEighteenDecimalAmountRoundsUpOnEverySurfaceAndTheQrCarriesTheShownAmount(): void
    {
        $this->seedEighteenDecimals();
        $order = FakeWoo::order(self::ORDER);
        $html = $this->payments()->html($order, true, self::DEADLINE - 60, new \DateTimeZone('UTC'));
        $email = $this->payments()->html($order, true, self::DEADLINE - 60, new \DateTimeZone('UTC'), true);
        $text = $this->payments()->text($order, self::DEADLINE - 60, new \DateTimeZone('UTC'));

        foreach ([$html, $email] as $rendered) {
            $this->assertStringContainsString('<strong>12.011184 USDT</strong>', $rendered);
            $this->assertStringContainsString('Minimum deposit: 10.000001 USDT', $rendered);
            $this->assertStringContainsString('Received so far: 1.50 USDT', $rendered);
            $this->assertStringNotContainsString('12.011183', $rendered);
        }
        $this->assertStringContainsString('Send exactly: 12.011184 USDT', $text);
        $this->assertStringContainsString('Minimum deposit: 10.000001 USDT', $text);
        $this->assertSame(['12.011184'], $this->qrAmounts);
        $this->assertSame('12.011183118544993788', FakeWoo::order(self::ORDER)->meta['_suawave_amount']);
    }

    public function testPayingTheShownAmountPaysTheOrder(): void
    {
        $this->seedEighteenDecimals();
        $this->assertSame(200, $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '30000000-0000-0000-0000-000000000001', '12.011184')->status);
        $this->assertSame('processing', FakeWoo::order(self::ORDER)->status);
    }

    public function testTheMatchUsesTheExactStoredAmount(): void
    {
        $this->seedEighteenDecimals();
        $this->assertSame(200, $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '30000000-0000-0000-0000-000000000001', '12.011183')->status);
        $this->assertSame('on-hold', FakeWoo::order(self::ORDER)->status);
        $this->assertCount(1, $this->notesLike('Total received 12.011183 of 12.011183118544993788.'));

        $this->assertSame(200, $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '30000000-0000-0000-0000-000000000002', '0.000001')->status);
        $this->assertSame('processing', FakeWoo::order(self::ORDER)->status);
    }

    public function testDepositNotesShowAmountsWithoutTrailingZeros(): void
    {
        $this->assertSame(200, $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '30000000-0000-0000-0000-000000000003', '100.000000000000000000')->status);
        $this->assertCount(1, $this->notesLike('Confirmed deposit of 100.00 to ' . self::OLD_ADDRESS));
        $this->assertCount(1, $this->notesLike('Total received 100.00 of 100.00.'));
        $this->assertCount(0, $this->notesLike('100.000000'));
    }

    private function seedEighteenDecimals(): void
    {
        $order = FakeWoo::order(self::ORDER);
        $order->update_meta_data('_suawave_amount', '12.011183118544993788');
        $order->update_meta_data('_suawave_minimum', '10.000000000000000001');
        $order->update_meta_data('_suawave_received', '1.500000000000000000');
        $order->delete_meta_data('_suawave_qr');
        $order->save();
    }

    public function testClosedWindowMovesTheOrderToPendingWithOneNoteAndDropsTheQr(): void
    {
        $this->assertFalse($this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE - 1));
        $this->assertTrue($this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE));
        $this->assertFalse($this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE + 60));
        $this->payments()->closeFromSchedule((string) self::ORDER);
        $order = FakeWoo::order(self::ORDER);
        $this->assertSame('pending', $order->status);
        $this->assertCount(1, $this->notesLike('payment window closed'));
        $this->assertSame(self::OLD_NONCE, $order->meta[WooOrderStore::EXPIRY_NOTED]);
        $this->assertArrayNotHasKey('_suawave_qr', $order->meta);
        $this->assertSame(self::OLD_ADDRESS, $order->meta['_suawave_address']);
    }

    public function testExpiryCheckAndNoteRunInsideTheOrderLock(): void
    {
        $this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE);
        $lock = array_search('SELECT option_value FROM wp_options WHERE option_name = %s FOR UPDATE', $this->db->log, true);
        $save = array_search('order:save', $this->db->log, true);
        $commit = array_search('COMMIT', $this->db->log, true);
        $this->assertNotFalse($lock);
        $this->assertGreaterThan($lock, $save);
        $this->assertGreaterThan($save, $commit);
    }

    public function testNoExpiryNoteOnceTheOrderIsPaid(): void
    {
        $this->assertSame(200, $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '10000000-0000-0000-0000-000000000001')->status);
        $this->assertSame('processing', FakeWoo::order(self::ORDER)->status);
        $this->assertFalse($this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE + 10));
        $this->assertSame([], $this->notesLike('payment window closed'));
        $this->assertSame('processing', FakeWoo::order(self::ORDER)->status);
    }

    public function testDetectedDepositKeepsTheOrderOnHoldWhenTheWindowCloses(): void
    {
        $this->assertSame(200, $this->pending(self::OLD_NONCE, self::OLD_ADDRESS, '10000000-0000-0000-0000-000000000002')->status);
        $this->assertTrue($this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE));
        $this->assertSame('on-hold', FakeWoo::order(self::ORDER)->status);
        $this->assertCount(1, $this->notesLike('waiting for confirmation'));
    }

    public function testRenewalAfterExpiryIssuesANewNonceAndAddressAndSupersedesTheOldAttempt(): void
    {
        $this->assertSame(WooPayments::RENEWED, $this->renew());
        $order = FakeWoo::order(self::ORDER);
        $newNonce = $order->meta['_suawave_nonce'];
        $this->assertNotSame(self::OLD_NONCE, $newNonce);
        $this->assertSame(self::NEW_ADDRESS, $order->meta['_suawave_address']);
        $this->assertSame((string) strtotime('2027-01-20T08:00:00Z'), $order->meta['_suawave_expires_at']);
        $this->assertSame('on-hold', $order->status);
        $this->assertSame(self::OLD_NONCE, $order->meta[WooOrderStore::PREVIOUS_ATTEMPTS][0]['nonce']);
        $this->assertSame(self::OLD_ADDRESS, $order->meta[WooOrderStore::PREVIOUS_ATTEMPTS][0]['address']);
        $this->assertStringContainsString($newNonce, urldecode($this->urls[0]));
        $this->assertStringNotContainsString(self::OLD_NONCE, urldecode($this->urls[0]));
        $this->assertSame([[strtotime('2027-01-20T08:00:00Z') + 1, WooOrderStore::EXPIRY_HOOK, [self::ORDER]]], FakeWoo::$scheduled);
        $current = $this->store->load('55', $newNonce);
        $this->assertSame([self::NEW_ADDRESS, true], [$current->addressIn, $current->current]);
        $this->assertFalse($this->store->load('55', self::OLD_NONCE)->current);
    }

    public function testRenewalWritesHappenInsideTheOrderLock(): void
    {
        $this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE + 10);
        $this->db->log = [];
        $this->payments()->open(FakeWoo::order(self::ORDER), $this->client(), 'trc20/usdt', 'TPayout', '100.00');
        $lock = array_search('SELECT option_value FROM wp_options WHERE option_name = %s FOR UPDATE', $this->db->log, true);
        $save = array_search('order:save', $this->db->log, true);
        $commit = array_search('COMMIT', $this->db->log, true);
        $this->assertNotFalse($lock);
        $this->assertGreaterThan($lock, $save);
        $this->assertGreaterThan(max(array_keys($this->db->log, 'order:save', true)), $commit);
    }

    public function testSupersededAttemptCallbackAfterTheNewAttemptPaidAddsOneNoteAndKeepsTheStatus(): void
    {
        $this->renew();
        $newNonce = FakeWoo::order(self::ORDER)->meta['_suawave_nonce'];
        $this->assertSame(200, $this->confirmed($newNonce, self::NEW_ADDRESS, '20000000-0000-0000-0000-000000000001')->status);
        $this->assertSame('processing', FakeWoo::order(self::ORDER)->status);
        $this->assertSame(['tx-20000000-0000-0000-0000-000000000001'], FakeWoo::order(self::ORDER)->completedWith);

        $this->assertSame(200, $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '20000000-0000-0000-0000-000000000002')->status);
        $this->assertSame(200, $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '20000000-0000-0000-0000-000000000002')->status);
        $order = FakeWoo::order(self::ORDER);
        $this->assertSame('processing', $order->status);
        $this->assertCount(1, $order->completedWith);
        $this->assertCount(1, $this->notesLike('payment received on a previous attempt, reconcile'));
        $this->assertSame('100.000000', $order->meta['_suawave_received']);
        $this->assertSame($newNonce, $order->meta[WooOrderStore::PAID_NONCE]);
        $this->assertSame(self::NEW_ADDRESS, $order->meta[WooOrderStore::PAID_ADDRESS]);

        $this->assertSame(200, $this->confirmed($newNonce, self::NEW_ADDRESS, '20000000-0000-0000-0000-000000000003')->status);
        $order = FakeWoo::order(self::ORDER);
        $this->assertSame('processing', $order->status);
        $this->assertCount(1, $order->completedWith);
        $this->assertCount(2, $this->notesLike('payment received on a previous attempt, reconcile'));
        $this->assertSame($newNonce, $order->meta[WooOrderStore::PAID_NONCE]);
    }

    public function testSupersededAttemptCallbackOnAnUnpaidOrderPaysItAndRecordsThePayingAttempt(): void
    {
        $this->renew();
        $newNonce = FakeWoo::order(self::ORDER)->meta['_suawave_nonce'];
        $this->assertSame(200, $this->pending(self::OLD_NONCE, self::OLD_ADDRESS, '30000000-0000-0000-0000-000000000002')->status);
        $this->assertCount(1, $this->notesLike('Deposit detected'));
        $this->assertSame(200, $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '30000000-0000-0000-0000-000000000001')->status);
        $order = FakeWoo::order(self::ORDER);
        $this->assertSame('processing', $order->status);
        $this->assertCount(1, $order->completedWith);
        $this->assertSame(self::OLD_NONCE, $order->meta[WooOrderStore::PAID_NONCE]);
        $this->assertSame(self::OLD_ADDRESS, $order->meta[WooOrderStore::PAID_ADDRESS]);
        $this->assertSame($newNonce, $order->meta['_suawave_nonce']);
        $this->assertSame([], $this->notesLike('reconcile'));

        $this->assertSame(200, $this->confirmed($newNonce, self::NEW_ADDRESS, '30000000-0000-0000-0000-000000000003')->status);
        $order = FakeWoo::order(self::ORDER);
        $this->assertSame('processing', $order->status);
        $this->assertCount(1, $order->completedWith);
        $this->assertCount(1, $this->notesLike('payment received on a previous attempt, reconcile'));
        $this->assertSame(self::OLD_NONCE, $order->meta[WooOrderStore::PAID_NONCE]);
    }

    public function testOldNonceCannotCreditTheReplacementAddress(): void
    {
        $this->renew();
        $this->assertSame(400, $this->confirmed(self::OLD_NONCE, self::NEW_ADDRESS, '40000000-0000-0000-0000-000000000001')->status);
        $this->assertSame('on-hold', FakeWoo::order(self::ORDER)->status);
        $this->assertSame([], $this->notesLike('reconcile'));
    }

    public function testOldCallbackPayingDuringTheCreateRequestStopsTheRenewal(): void
    {
        $this->duringCreate = function (): void {
            $this->assertSame(200, $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '50000000-0000-0000-0000-000000000001')->status);
        };
        $this->assertSame(WooPayments::ALREADY_PAID, $this->payments()->open(FakeWoo::order(self::ORDER), $this->client(), 'trc20/usdt', 'TPayout', '100.00'));
        $order = FakeWoo::order(self::ORDER);
        $this->assertSame('processing', $order->status);
        $this->assertSame(self::OLD_NONCE, $order->meta['_suawave_nonce']);
        $this->assertSame(self::OLD_ADDRESS, $order->meta['_suawave_address']);
        $this->assertArrayNotHasKey(WooOrderStore::PREVIOUS_ATTEMPTS, $order->meta);
        $this->assertSame([], FakeWoo::$scheduled);
        $this->assertCount(1, $order->completedWith);
        $this->assertSame(self::OLD_NONCE, $order->meta[WooOrderStore::PAID_NONCE]);
    }

    public function testRenewalThatTakesTheLockBeforeAnOldCallbackStillLetsTheSupersededAttemptPayOnce(): void
    {
        $this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE + 10);
        $inner = new WpOrderLock($this->db);
        $renewFirst = new class ($inner, fn (): string => $this->payments()->open(FakeWoo::order(self::ORDER), $this->client(), 'trc20/usdt', 'TPayout', '100.00')) implements OrderLock {
            private ?\Closure $before;

            public function __construct(private OrderLock $inner, \Closure $before)
            {
                $this->before = $before;
            }

            public function transactional(string $key, callable $work): mixed
            {
                if ($this->before !== null) {
                    $before = $this->before;
                    $this->before = null;
                    $before();
                }
                return $this->inner->transactional($key, $work);
            }
        };
        $processor = new CallbackProcessor($this->store, new WpLedger($this->db), $renewFirst);
        $result = $processor->process(['order' => (string) self::ORDER, 'nonce' => self::OLD_NONCE, 'src' => 'woo'], [
            'uuid' => '80000000-0000-0000-0000-000000000001', 'address_in' => self::OLD_ADDRESS, 'coin' => 'trc20_usdt', 'pending' => '0',
            'txid_in' => 'tx-race', 'txid_out' => 'out', 'value_coin' => '100.000000',
        ]);
        $this->assertSame(200, $result->status);
        $order = FakeWoo::order(self::ORDER);
        $this->assertSame(self::NEW_ADDRESS, $order->meta['_suawave_address']);
        $this->assertSame('processing', $order->status);
        $this->assertCount(1, $order->completedWith);
        $this->assertSame(self::OLD_NONCE, $order->meta[WooOrderStore::PAID_NONCE]);
        $this->assertSame([], $this->notesLike('reconcile'));
        $this->assertSame(WooPayments::ALREADY_PAID, $this->payments()->open(FakeWoo::order(self::ORDER), $this->client(), 'trc20/usdt', 'TPayout', '100.00'));
        $this->assertSame('processing', FakeWoo::order(self::ORDER)->status);
    }

    public function testTwoRequestsThatCachedAnUnnotedOrderAddOnlyOneExpiryNote(): void
    {
        FakeWoo::inRequest('a', fn () => wc_get_order(self::ORDER));
        FakeWoo::inRequest('b', fn () => wc_get_order(self::ORDER));
        $this->assertTrue(FakeWoo::inRequest('a', fn (): bool => $this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE + 5)));
        $this->assertSame('on-hold', FakeWoo::inRequest('b', fn (): string => wc_get_order(self::ORDER)->status));
        $this->assertFalse(FakeWoo::inRequest('b', fn (): bool => $this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE + 6)));
        $this->assertCount(1, $this->notesLike('payment window closed'));
        $this->assertSame('pending', FakeWoo::order(self::ORDER)->status);
    }

    public function testStaleMetaCacheWithoutAnOrdersDataEntryIsClearedToo(): void
    {
        FakeWoo::inRequest('a', fn () => wc_get_order(self::ORDER));
        FakeWoo::inRequest('b', fn () => wc_get_order(self::ORDER));
        FakeWoo::inRequest('b', fn (): bool => FakeWoo::forgetOrdersData(self::ORDER));
        $this->assertTrue(FakeWoo::inRequest('a', fn (): bool => $this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE + 5)));
        $this->assertFalse(FakeWoo::inRequest('b', fn (): bool => $this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE + 6)));
        $this->assertCount(1, $this->notesLike('payment window closed'));
    }

    public function testCallbackOnAStaleSnapshotValidatesAgainstTheRenewedAttemptAfterTheForcedRefresh(): void
    {
        FakeWoo::inRequest('callback', fn () => wc_get_order(self::ORDER));
        FakeWoo::inRequest('customer', fn (): string => $this->renew());
        $newNonce = FakeWoo::order(self::ORDER)->meta['_suawave_nonce'];
        $this->assertSame(self::OLD_NONCE, FakeWoo::inRequest('callback', fn (): string => wc_get_order(self::ORDER)->get_meta('_suawave_nonce')));

        $result = FakeWoo::inRequest('callback', fn (): CallbackResult => $this->confirmed($newNonce, self::NEW_ADDRESS, '90000000-0000-0000-0000-000000000001'));
        $this->assertSame(200, $result->status);
        $order = FakeWoo::order(self::ORDER);
        $this->assertSame('processing', $order->status);
        $this->assertSame($newNonce, $order->meta[WooOrderStore::PAID_NONCE]);
        $this->assertCount(1, $this->notesLike('(current attempt'));
    }

    public function testStaleSnapshotDoesNotTreatTheSupersededAttemptAsCurrent(): void
    {
        FakeWoo::inRequest('callback', fn () => wc_get_order(self::ORDER));
        FakeWoo::inRequest('customer', fn (): string => $this->renew());
        $result = FakeWoo::inRequest('callback', fn (): CallbackResult => $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '90000000-0000-0000-0000-000000000002'));
        $this->assertSame(200, $result->status);
        $order = FakeWoo::order(self::ORDER);
        $this->assertSame(self::NEW_ADDRESS, $order->meta['_suawave_address']);
        $this->assertSame(self::OLD_NONCE, $order->meta[WooOrderStore::PAID_NONCE]);
        $this->assertCount(1, $this->notesLike('(superseded attempt'));
    }

    public function testCallbackForAnAttemptCommittedWhileItWaitsForTheLockIsApplied(): void
    {
        $knownNonce = 'cccccccccccccccccccccccccccccccc';
        $inner = new WpOrderLock($this->db);
        $attempt = ['nonce' => $knownNonce, 'address' => self::NEW_ADDRESS, 'coin' => 'trc20_usdt', 'ticker' => 'trc20/usdt', 'amount' => '100.00', 'minimum' => '10.000000', 'expires_at' => '1900000000'];
        $renewal = fn () => FakeWoo::inRequest('customer', fn () => $inner->transactional('55', fn (\Suawave\Totals $totals): array => [$this->store->renew('55', $attempt, $totals), $totals]));
        $waiting = new class ($inner, $renewal) implements OrderLock {
            private ?\Closure $before;

            public function __construct(private OrderLock $inner, \Closure $before)
            {
                $this->before = $before;
            }

            public function transactional(string $key, callable $work): mixed
            {
                if ($this->before !== null) {
                    $before = $this->before;
                    $this->before = null;
                    $before();
                }
                return $this->inner->transactional($key, $work);
            }
        };
        $processor = new CallbackProcessor($this->store, new WpLedger($this->db), $waiting);
        $result = FakeWoo::inRequest('callback', fn (): CallbackResult => $processor->process(['order' => '55', 'nonce' => $knownNonce, 'src' => 'woo'], [
            'uuid' => 'a0000000-0000-0000-0000-000000000001', 'address_in' => self::NEW_ADDRESS, 'coin' => 'trc20_usdt', 'pending' => '0',
            'txid_in' => 'tx-wait', 'txid_out' => 'out', 'value_coin' => '100.000000',
        ]));
        $this->assertSame(200, $result->status);
        $order = FakeWoo::order(self::ORDER);
        $this->assertSame('processing', $order->status);
        $this->assertSame($knownNonce, $order->meta[WooOrderStore::PAID_NONCE]);
    }

    public function testCallbackReadsTheOrderOnlyAfterTakingTheLock(): void
    {
        $this->db->log = [];
        FakeWoo::inRequest('callback', fn (): CallbackResult => $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, 'a0000000-0000-0000-0000-000000000002'));
        $lock = array_search('SELECT option_value FROM wp_options WHERE option_name = %s FOR UPDATE', $this->db->log, true);
        $firstRead = array_search('order:read', $this->db->log, true);
        $this->assertNotFalse($lock);
        $this->assertNotFalse($firstRead);
        $this->assertGreaterThan($lock, $firstRead);
    }

    public function testPaidOrderIsNeverRenewedOrReturnedToOnHold(): void
    {
        $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '60000000-0000-0000-0000-000000000001');
        $this->assertSame(WooPayments::ALREADY_PAID, $this->payments()->open(FakeWoo::order(self::ORDER), $this->client(), 'trc20/usdt', 'TPayout', '100.00'));
        $this->assertSame([], $this->urls);
        $this->assertSame('processing', FakeWoo::order(self::ORDER)->status);
    }

    public function testEachAttemptGetsItsOwnSingleExpiryNote(): void
    {
        $this->renew();
        $this->payments()->closeIfExpired((string) self::ORDER, self::DEADLINE + 20);
        $second = (int) strtotime('2027-01-20T08:00:00Z');
        $this->assertTrue($this->payments()->closeIfExpired((string) self::ORDER, $second));
        $this->assertFalse($this->payments()->closeIfExpired((string) self::ORDER, $second + 5));
        $this->assertCount(2, $this->notesLike('payment window closed'));
        $this->assertSame('pending', FakeWoo::order(self::ORDER)->status);
    }

    public function testOpenWindowRendersAddressQrAndDeadline(): void
    {
        $html = $this->payments()->html(FakeWoo::order(self::ORDER), true, self::DEADLINE - 60, new \DateTimeZone('UTC'));
        $this->assertStringContainsString(self::OLD_ADDRESS, $html);
        $this->assertStringContainsString('data:image/png;base64,OLD', $html);
        $this->assertStringContainsString('Pay before:', $html);
        $text = $this->payments()->text(FakeWoo::order(self::ORDER), self::DEADLINE - 60, new \DateTimeZone('UTC'));
        $this->assertStringContainsString(self::OLD_ADDRESS, $text);
        $this->assertStringContainsString('Pay before:', $text);
    }

    public function testExpiredUnpaidOrderRendersTheClosedWindowWithoutAddressOrQr(): void
    {
        $now = self::DEADLINE + 30;
        $html = $this->payments()->html(FakeWoo::order(self::ORDER), true, $now, new \DateTimeZone('UTC'));
        $text = $this->payments()->text(FakeWoo::order(self::ORDER), $now, new \DateTimeZone('UTC'));
        foreach ([$html, $text] as $output) {
            $this->assertStringNotContainsString(self::OLD_ADDRESS, $output);
            $this->assertStringNotContainsString('data:image', $output);
            $this->assertStringContainsString('payment window closed', $output);
            $this->assertStringContainsString('order 55', $output);
        }
        $this->assertStringNotContainsString('<img', $html);
        $this->assertSame(0, $this->qrCalls);
        $this->assertSame('pending', FakeWoo::order(self::ORDER)->status);
        $this->assertCount(1, $this->notesLike('payment window closed'));
    }

    public function testExpiredPaidOrderRendersPaidWithoutAddressOrQr(): void
    {
        $this->confirmed(self::OLD_NONCE, self::OLD_ADDRESS, '70000000-0000-0000-0000-000000000001');
        $now = self::DEADLINE + 30;
        $html = $this->payments()->html(FakeWoo::order(self::ORDER), true, $now, new \DateTimeZone('UTC'));
        $text = $this->payments()->text(FakeWoo::order(self::ORDER), $now, new \DateTimeZone('UTC'));
        foreach ([$html, $text] as $output) {
            $this->assertStringContainsString('Payment received', $output);
            $this->assertStringNotContainsString(self::OLD_ADDRESS, $output);
            $this->assertStringNotContainsString('data:image', $output);
        }
        $this->assertSame(0, $this->qrCalls);
    }

    public function testRenewedOrderRendersTheNewAddressAndAFreshQr(): void
    {
        $this->renew();
        $html = $this->payments()->html(FakeWoo::order(self::ORDER), true, self::DEADLINE + 60, new \DateTimeZone('UTC'));
        $this->assertStringContainsString(self::NEW_ADDRESS, $html);
        $this->assertStringNotContainsString(self::OLD_ADDRESS, $html);
        $this->assertStringContainsString('QR' . self::NEW_ADDRESS, $html);
        $this->assertSame(1, $this->qrCalls);
    }

    public function testOrderMetaKeysAreProtected(): void
    {
        $this->renew();
        foreach (array_keys(FakeWoo::order(self::ORDER)->meta) as $key) {
            $this->assertStringStartsWith('_', $key);
        }
    }
}
