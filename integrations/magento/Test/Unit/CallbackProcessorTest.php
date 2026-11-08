<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use Suawave\Payment\Core\CallbackProcessor;
use Suawave\Payment\Core\CallbackRequest;
use Suawave\Payment\Core\ExpectedPayment;
use Suawave\Payment\Core\OrderTransaction;
use Suawave\Payment\Core\PaymentLedger;
use Suawave\Payment\Core\ProcessedEventStore;
use Suawave\Payment\Core\PublicKeyProvider;
use Suawave\Payment\Core\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class MemoryKeys implements PublicKeyProvider
{
    public int $refreshes = 0;

    public function __construct(public string $cached, public string $fresh)
    {
    }

    public function current(): string
    {
        return $this->cached;
    }

    public function refresh(): string
    {
        $this->refreshes++;
        $this->cached = $this->fresh;
        return $this->fresh;
    }
}

final class MemoryLedger implements PaymentLedger
{
    public array $applied = [];
    public bool $failNext = false;
    public ?ExpectedPayment $payment;

    public function __construct(string $expectedAmount)
    {
        $this->payment = new ExpectedPayment(
            Fixtures::ORDER,
            Fixtures::NONCE,
            Fixtures::ADDRESS,
            Fixtures::TICKER,
            $expectedAmount,
            '0',
            false
        );
    }

    public function find(string $orderReference): ?ExpectedPayment
    {
        return $orderReference === Fixtures::ORDER ? $this->payment : null;
    }

    public function applyDeposit(
        string $orderReference,
        string $eventUuid,
        string $depositTransaction,
        string $depositAmount,
        string $newReceivedTotal,
        bool $fullyPaid
    ): void {
        if ($this->failNext) {
            $this->failNext = false;
            throw new \RuntimeException('storage down');
        }
        $this->applied[] = [$eventUuid, $depositAmount, $newReceivedTotal, $fullyPaid];
        $this->payment = new ExpectedPayment(
            $this->payment->orderReference,
            $this->payment->nonce,
            $this->payment->depositAddress,
            $this->payment->ticker,
            $this->payment->expectedAmount,
            $newReceivedTotal,
            $this->payment->paid || $fullyPaid
        );
    }
}

final class MemoryEvents implements ProcessedEventStore
{
    public array $seen = [];
    public array $deposits = [];
    public array $log = [];

    public function claim(string $eventUuid, string $orderReference, string $depositTransaction): bool
    {
        $this->log[] = 'claim:' . $eventUuid;
        if (isset($this->seen[$eventUuid])) {
            return false;
        }
        $this->seen[$eventUuid] = true;
        $this->deposits[$eventUuid] = $depositTransaction;
        return true;
    }
}

final class RecordingTransaction implements OrderTransaction
{
    public array $log = [];
    public int $depth = 0;

    public function __construct(private MemoryEvents $events, private MemoryLedger $ledger)
    {
    }

    public function runLocked(string $orderReference, callable $work): mixed
    {
        $seen = $this->events->seen;
        $deposits = $this->events->deposits;
        $payment = $this->ledger->payment;
        $applied = $this->ledger->applied;
        $this->log[] = 'begin';
        $this->log[] = 'lock:' . $orderReference;
        $this->depth++;
        try {
            $result = $work();
        } catch (\Throwable $failure) {
            $this->depth--;
            $this->log[] = 'rollback';
            $this->events->seen = $seen;
            $this->events->deposits = $deposits;
            $this->ledger->payment = $payment;
            $this->ledger->applied = $applied;
            throw $failure;
        }
        $this->depth--;
        $this->log[] = 'commit';
        return $result;
    }
}

final class CallbackProcessorTest extends TestCase
{
    private static KeyPair $pair;
    private MemoryKeys $keys;
    private MemoryLedger $ledger;
    private MemoryEvents $events;
    private RecordingTransaction $transaction;
    private CallbackProcessor $processor;

    public static function setUpBeforeClass(): void
    {
        self::$pair = KeyPair::generate(2048);
    }

    protected function setUp(): void
    {
        $this->keys = new MemoryKeys(self::$pair->publicPem, self::$pair->publicPem);
        $this->ledger = new MemoryLedger('100.000000');
        $this->events = new MemoryEvents();
        $this->transaction = new RecordingTransaction($this->events, $this->ledger);
        $this->processor = new CallbackProcessor(new SignatureVerifier(), $this->keys, $this->ledger, $this->events, $this->transaction);
    }

    public function testKnownGoodFixtureJsonPostMarksOrderPaid(): void
    {
        $keys = new MemoryKeys(Fixtures::publicKey(), Fixtures::publicKey());
        $processor = new CallbackProcessor(new SignatureVerifier(), $keys, $this->ledger, $this->events, $this->transaction);
        $response = $processor->handle(new CallbackRequest(
            'POST',
            $this->callbackUrl(),
            Fixtures::read('confirmed_body.json'),
            'application/json',
            Fixtures::read('confirmed_body.signature')
        ));
        self::assertTrue($response->isAcknowledged());
        self::assertSame([['8c4e2a61-5b3d-47f9-a0e2-1d6c9b7f3e45', '100.000000', '100.000000', true]], $this->ledger->applied);
    }

    public function testKnownGoodFixtureGetMarksOrderPaid(): void
    {
        $keys = new MemoryKeys(Fixtures::publicKey(), Fixtures::publicKey());
        $processor = new CallbackProcessor(new SignatureVerifier(), $keys, $this->ledger, $this->events, $this->transaction);
        $response = $processor->handle(new CallbackRequest(
            'GET',
            Fixtures::read('confirmed_get_url.txt'),
            '',
            '',
            Fixtures::read('confirmed_get_url.signature')
        ));
        self::assertTrue($response->isAcknowledged());
        self::assertTrue($this->ledger->payment->paid);
    }

    public function testMissingSignatureHeaderLeavesOrderUntouched(): void
    {
        $response = $this->processor->handle($this->post($this->confirmedBody(), 'ignored', true));
        self::assertSame(401, $response->status);
        self::assertSame([], $this->ledger->applied);
        self::assertSame([], $this->events->seen);
    }

    public function testBadSignatureLeavesOrderUntouched(): void
    {
        $body = $this->confirmedBody();
        $response = $this->processor->handle(new CallbackRequest(
            'POST',
            $this->callbackUrl(),
            $body,
            'application/json',
            self::$pair->sign($body . ' ')
        ));
        self::assertSame(401, $response->status);
        self::assertSame([], $this->ledger->applied);
        self::assertSame([], $this->events->seen);
    }

    public function testSignatureOverDifferentBytesLeavesOrderUntouched(): void
    {
        $signed = $this->confirmedBody();
        $sent = str_replace('"100.000000"', '"500.000000"', $signed);
        $response = $this->processor->handle(new CallbackRequest(
            'POST',
            $this->callbackUrl(),
            $sent,
            'application/json',
            self::$pair->sign($signed)
        ));
        self::assertSame(401, $response->status);
        self::assertSame([], $this->ledger->applied);
    }

    public function testReorderedGetQueryLeavesOrderUntouched(): void
    {
        $url = Fixtures::read('confirmed_get_url.txt');
        [$path, $query] = explode('?', $url, 2);
        $reordered = $path . '?' . implode('&', array_reverse(explode('&', $query)));
        $keys = new MemoryKeys(Fixtures::publicKey(), Fixtures::publicKey());
        $processor = new CallbackProcessor(new SignatureVerifier(), $keys, $this->ledger, $this->events, $this->transaction);
        $response = $processor->handle(new CallbackRequest(
            'GET',
            $reordered,
            '',
            '',
            Fixtures::read('confirmed_get_url.signature')
        ));
        self::assertSame(401, $response->status);
        self::assertSame([], $this->ledger->applied);
    }

    public function testUnknownSigningKeyIsRejectedAfterRefresh(): void
    {
        $stranger = KeyPair::generate(2048);
        $body = $this->confirmedBody();
        $response = $this->processor->handle(new CallbackRequest(
            'POST',
            $this->callbackUrl(),
            $body,
            'application/json',
            $stranger->sign($body)
        ));
        self::assertSame(401, $response->status);
        self::assertSame(1, $this->keys->refreshes);
        self::assertSame([], $this->ledger->applied);
    }

    public function testRotatedKeyIsPickedUpByRefresh(): void
    {
        $rotated = KeyPair::generate(2048);
        $this->keys->fresh = $rotated->publicPem;
        $body = $this->confirmedBody();
        $response = $this->processor->handle(new CallbackRequest(
            'POST',
            $this->callbackUrl(),
            $body,
            'application/json',
            $rotated->sign($body)
        ));
        self::assertTrue($response->isAcknowledged());
        self::assertCount(1, $this->ledger->applied);
    }

    public function testUnparseableKeyAnswers503AndLeavesOrderUntouched(): void
    {
        $this->keys->cached = 'garbage';
        $response = $this->processor->handle($this->post($this->confirmedBody()));
        self::assertSame(503, $response->status);
        self::assertSame([], $this->ledger->applied);
    }

    public function testTooShortKeyAnswers503AndLeavesOrderUntouched(): void
    {
        $this->keys->cached = KeyPair::generate(1024)->publicPem;
        $response = $this->processor->handle($this->post($this->confirmedBody()));
        self::assertSame(503, $response->status);
        self::assertSame([], $this->ledger->applied);
    }

    public function testRepeatedDeliveryOfTheSameUuidIsAppliedOnce(): void
    {
        $request = $this->post($this->confirmedBody());
        self::assertTrue($this->processor->handle($request)->isAcknowledged());
        self::assertTrue($this->processor->handle($request)->isAcknowledged());
        self::assertTrue($this->processor->handle($request)->isAcknowledged());
        self::assertCount(1, $this->ledger->applied);
        self::assertSame('100.000000', $this->ledger->payment->receivedAmount);
    }

    public function testPendingCallbackIsAcknowledgedButNeverPays(): void
    {
        $body = $this->confirmedBody(['pending' => '1', 'uuid' => 'pending-event-1']);
        $response = $this->processor->handle($this->post($body));
        self::assertTrue($response->isAcknowledged());
        self::assertSame([], $this->ledger->applied);
        self::assertFalse($this->ledger->payment->paid);
    }

    public function testUnderpaymentDoesNotPayAndLaterDepositsAccumulate(): void
    {
        self::assertTrue($this->processor->handle($this->post($this->confirmedBody([
            'uuid' => 'event-a', 'txid_in' => 'tx-event-a',
            'value_coin' => '60.5',
        ])))->isAcknowledged());
        self::assertFalse($this->ledger->payment->paid);
        self::assertSame('60.5', $this->ledger->payment->receivedAmount);

        self::assertTrue($this->processor->handle($this->post($this->confirmedBody([
            'uuid' => 'event-b', 'txid_in' => 'tx-event-b',
            'value_coin' => '39.500000',
        ])))->isAcknowledged());
        self::assertTrue($this->ledger->payment->paid);
        self::assertSame('100.000000', $this->ledger->payment->receivedAmount);
        self::assertCount(2, $this->ledger->applied);
    }

    public function testDecimalSumsAreExact(): void
    {
        $this->ledger->payment = new ExpectedPayment(
            Fixtures::ORDER,
            Fixtures::NONCE,
            Fixtures::ADDRESS,
            Fixtures::TICKER,
            '0.3',
            '0',
            false
        );
        $this->processor->handle($this->post($this->confirmedBody(['uuid' => 'e1', 'txid_in' => 'tx-e1', 'value_coin' => '0.1'])));
        self::assertFalse($this->ledger->payment->paid);
        $this->processor->handle($this->post($this->confirmedBody(['uuid' => 'e2', 'txid_in' => 'tx-e2', 'value_coin' => '0.2'])));
        self::assertTrue($this->ledger->payment->paid);
        self::assertSame('0.3', $this->ledger->payment->receivedAmount);
    }

    public function testStorageFailureRollsBackTheClaimSoTheRetryIsApplied(): void
    {
        $request = $this->post($this->confirmedBody());
        $this->ledger->failNext = true;
        try {
            $this->processor->handle($request);
            self::fail('expected the storage failure to surface');
        } catch (\RuntimeException $failure) {
            self::assertSame('storage down', $failure->getMessage());
        }
        self::assertSame('rollback', end($this->transaction->log));
        self::assertTrue($this->processor->handle($request)->isAcknowledged());
        self::assertCount(1, $this->ledger->applied);
    }

    public function testReadSumAndDecideRunInsideOneLockedTransaction(): void
    {
        $this->processor->handle($this->post($this->confirmedBody(['uuid' => 'first', 'txid_in' => 'tx-1', 'value_coin' => '40'])));
        self::assertSame(['begin', 'lock:' . Fixtures::ORDER, 'commit'], $this->transaction->log);
        self::assertSame(0, $this->transaction->depth);
        self::assertSame(['claim:first'], $this->events->log);
    }

    public function testNothingIsLockedForPendingOrRejectedCallbacks(): void
    {
        $this->processor->handle($this->post($this->confirmedBody(['pending' => '1'])));
        $this->processor->handle($this->post($this->confirmedBody(), '', true));
        self::assertSame([], $this->transaction->log);
    }

    public function testTwoDifferentUuidsSumInSequenceAndPayExactlyOnce(): void
    {
        $this->processor->handle($this->post($this->confirmedBody(['uuid' => 'a', 'txid_in' => 'tx-a', 'value_coin' => '60'])));
        $this->processor->handle($this->post($this->confirmedBody(['uuid' => 'b', 'txid_in' => 'tx-b', 'value_coin' => '60'])));
        $this->processor->handle($this->post($this->confirmedBody(['uuid' => 'c', 'txid_in' => 'tx-c', 'value_coin' => '60'])));
        $transitions = array_filter($this->ledger->applied, static fn (array $row): bool => $row[3]);
        self::assertCount(3, $this->ledger->applied);
        self::assertSame('180', $this->ledger->payment->receivedAmount);
        self::assertSame(['b', 'c'], array_column(array_values($transitions), 0));
        self::assertSame(3, count(array_keys($this->transaction->log, 'lock:' . Fixtures::ORDER, true)));
    }

    public function testTwoUuidsSharingOneTransactionHashAreBothCredited(): void
    {
        $first = $this->post($this->confirmedBody(['uuid' => 'one', 'txid_in' => 'tx-same', 'value_coin' => '60']));
        $second = $this->post($this->confirmedBody(['uuid' => 'two', 'txid_in' => 'tx-same', 'value_coin' => '60']));
        self::assertTrue($this->processor->handle($first)->isAcknowledged());
        self::assertTrue($this->processor->handle($second)->isAcknowledged());
        self::assertTrue($this->processor->handle($second)->isAcknowledged());
        self::assertTrue($this->processor->handle($first)->isAcknowledged());
        self::assertCount(2, $this->ledger->applied);
        self::assertSame('120', $this->ledger->payment->receivedAmount);
        self::assertSame([false, true], array_column($this->ledger->applied, 3));
        self::assertSame(['tx-same', 'tx-same'], array_values($this->events->deposits));
    }

    public function testWrongNonceIsForbidden(): void
    {
        $url = Fixtures::ORIGIN . '/suawave/callback/index?order=' . Fixtures::ORDER . '&nonce=ffffffffffffffffffffffffffffffff';
        $body = $this->confirmedBody();
        $response = $this->processor->handle(new CallbackRequest('POST', $url, $body, 'application/json', self::$pair->sign($body)));
        self::assertSame(403, $response->status);
        self::assertSame([], $this->ledger->applied);
    }

    public function testDepositAddressOfAnotherOrderIsForbidden(): void
    {
        $response = $this->processor->handle($this->post($this->confirmedBody(['address_in' => 'TSomeoneElse000000000000000000000'])));
        self::assertSame(403, $response->status);
        self::assertSame([], $this->ledger->applied);
    }

    public function testWrongCoinIsForbidden(): void
    {
        $response = $this->processor->handle($this->post($this->confirmedBody(['coin' => 'bep20_usdt'])));
        self::assertSame(403, $response->status);
    }

    public function testUnknownOrderAnswers404(): void
    {
        $url = Fixtures::ORIGIN . '/suawave/callback/index?order=999&nonce=' . Fixtures::NONCE;
        $body = $this->confirmedBody();
        $response = $this->processor->handle(new CallbackRequest('POST', $url, $body, 'application/json', self::$pair->sign($body)));
        self::assertSame(404, $response->status);
    }

    public function testFormPostIsAccepted(): void
    {
        $body = http_build_query([
            'uuid' => 'form-event',
            'address_in' => Fixtures::ADDRESS,
            'txid_in' => 'abc',
            'coin' => 'trc20_usdt',
            'pending' => '0',
            'value_coin' => '100.000000',
        ]);
        $response = $this->processor->handle(new CallbackRequest(
            'POST',
            $this->callbackUrl(),
            $body,
            'application/x-www-form-urlencoded',
            self::$pair->sign($body)
        ));
        self::assertTrue($response->isAcknowledged());
        self::assertTrue($this->ledger->payment->paid);
    }

    public function testNonStringValuesAreMalformed(): void
    {
        $body = (string) json_encode([
            'uuid' => 'num', 'address_in' => Fixtures::ADDRESS, 'txid_in' => 'a',
            'coin' => 'trc20_usdt', 'pending' => '0', 'value_coin' => 100,
        ]);
        $response = $this->processor->handle(new CallbackRequest(
            'POST',
            $this->callbackUrl(),
            $body,
            'application/json',
            self::$pair->sign($body)
        ));
        self::assertSame(400, $response->status);
        self::assertSame([], $this->ledger->applied);
    }

    public function testZeroAndMalformedAmountsAreRejected(): void
    {
        foreach (['0', '0.000', '-5', '1e3', 'abc', ''] as $amount) {
            $response = $this->processor->handle($this->post($this->confirmedBody(['uuid' => 'u' . $amount, 'value_coin' => $amount])));
            self::assertSame(400, $response->status, $amount);
        }
        self::assertSame([], $this->ledger->applied);
    }

    private function callbackUrl(): string
    {
        return Fixtures::ORIGIN . '/suawave/callback/index?order=' . Fixtures::ORDER . '&nonce=' . Fixtures::NONCE;
    }

    private function confirmedBody(array $overrides = []): string
    {
        return (string) json_encode($overrides + [
            'uuid' => 'event-1',
            'address_in' => Fixtures::ADDRESS,
            'address_out' => 'TXyzPayoutAddressExample0000000000000',
            'txid_in' => 'deposit-hash-1',
            'coin' => 'trc20_usdt',
            'pending' => '0',
            'value_coin' => '100.000000',
        ]);
    }

    private function post(string $body, string $signatureOverride = '', bool $withoutSignature = false): CallbackRequest
    {
        return new CallbackRequest(
            'POST',
            $this->callbackUrl(),
            $body,
            'application/json',
            $withoutSignature ? '' : self::$pair->sign($body)
        );
    }
}
