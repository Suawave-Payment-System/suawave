<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\CallbackProcessor;
use Suawave\CallbackRequest;
use Suawave\PaymentRecord;
use Suawave\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class CallbackProcessorTest extends TestCase
{
    private const ORIGIN = 'https://shop.example';
    private const PATH = '/index.php?route=extension/suawave/payment/suawave.callback';
    private const ADDRESS = 'TDepositAddressExample0000000000';
    private const PAYOUT = 'TXyzPayoutAddressExample0000000000000';
    private const NONCE = '7f3a9c0d1e2b4a5c8d9e0f1a2b3c4d5e';

    private TestKeyPair $pair;
    private InMemoryPaymentStore $store;
    private RecordingOrderGateway $orders;
    private CallbackProcessor $processor;

    protected function setUp(): void
    {
        $this->pair = TestKeyPair::generate();
        $this->store = new InMemoryPaymentStore();
        $this->orders = new RecordingOrderGateway();
        $this->orders->quotes[1001] = ['amount' => '100.00', 'ticker' => 'trc20/usdt'];
        $this->store->save(new PaymentRecord(1001, self::NONCE, 'trc20/usdt', self::ADDRESS, self::PAYOUT, '100.00', '10.000000', '0', PaymentRecord::AWAITING));
        $this->processor = new CallbackProcessor(new SignatureVerifier($this->pair->publicPem), self::ORIGIN, $this->store, $this->orders);
    }

    public function testConfirmedCallbackMarksTheOrderPaid(): void
    {
        $outcome = $this->processor->process($this->signedGet($this->confirmed('uuid-1', '100.000000')));

        $this->assertSame(200, $outcome->httpStatus);
        $this->assertSame('*ok*', $outcome->body);
        $this->assertSame([1001], $this->orders->paidOrders);
        $this->assertSame(PaymentRecord::PAID, $this->store->findByAddressIn(self::ADDRESS)->status);
    }

    public function testRepeatedDeliveryIsIdempotent(): void
    {
        $request = $this->signedGet($this->confirmed('uuid-1', '100.000000'));

        $first = $this->processor->process($request);
        $second = $this->processor->process($request);

        $this->assertSame('paid', $first->reason);
        $this->assertSame('duplicate', $second->reason);
        $this->assertSame(200, $second->httpStatus);
        $this->assertSame([1001], $this->orders->paidOrders);
        $this->assertSame('100.000000', $this->store->findByAddressIn(self::ADDRESS)->received);
    }

    public function testNewUuidIsANewEvent(): void
    {
        $this->processor->process($this->signedGet($this->confirmed('uuid-1', '100.000000')));
        $second = $this->processor->process($this->signedGet($this->confirmed('uuid-2', '5.000000')));

        $this->assertSame('already_paid', $second->reason);
        $this->assertSame([1001], $this->orders->paidOrders);
        $this->assertSame('105.000000', $this->store->findByAddressIn(self::ADDRESS)->received);
    }

    public function testPendingCallbackNeverMarksPaid(): void
    {
        $outcome = $this->processor->process($this->signedGet($this->pending('uuid-p')));

        $this->assertSame('pending', $outcome->reason);
        $this->assertSame([], $this->orders->paidOrders);
        $this->assertSame(PaymentRecord::DETECTED, $this->store->findByAddressIn(self::ADDRESS)->status);
    }

    public function testPendingAfterConfirmedKeepsPaid(): void
    {
        $this->processor->process($this->signedGet($this->confirmed('uuid-1', '100.000000')));
        $this->processor->process($this->signedGet($this->pending('uuid-p')));

        $this->assertSame(PaymentRecord::PAID, $this->store->findByAddressIn(self::ADDRESS)->status);
        $this->assertSame([1001], $this->orders->paidOrders);
    }

    public function testUnderpaymentDoesNotMarkPaidAndPartialsAdd(): void
    {
        $first = $this->processor->process($this->signedGet($this->confirmed('uuid-1', '40.000000')));
        $this->assertSame('underpaid', $first->reason);
        $this->assertSame([], $this->orders->paidOrders);

        $this->processor->process($this->signedGet($this->confirmed('uuid-2', '60.000000')));
        $this->assertSame([1001], $this->orders->paidOrders);
    }

    public function testPartialDepositAddsOneHistoryEntryAndCompletingOneAddsTheFinalEntry(): void
    {
        $this->processor->process($this->signedGet($this->confirmed('uuid-1', '40.000000') . '&txid_in=abc'));
        $this->assertCount(1, $this->orders->notes);
        $this->assertSame(1001, $this->orders->notes[0][0]);
        $this->assertStringContainsString('received 40.000000', $this->orders->notes[0][1]);
        $this->assertStringContainsString('outstanding 60.000000', $this->orders->notes[0][1]);
        $this->assertStringContainsString('transaction abc', $this->orders->notes[0][1]);
        $this->assertSame([], $this->orders->paidOrders);
        $this->assertSame([], $this->orders->paidComments);

        $this->processor->process($this->signedGet($this->confirmed('uuid-2', '60.000000') . '&txid_in=def'));
        $this->assertCount(1, $this->orders->notes);
        $this->assertSame([1001], $this->orders->paidOrders);
        $this->assertStringContainsString('total received 100.000000 of 100.00', $this->orders->paidComments[0]);
        $this->assertStringContainsString('transaction def', $this->orders->paidComments[0]);
    }

    public function testOverpaymentMarksPaid(): void
    {
        $this->processor->process($this->signedGet($this->confirmed('uuid-1', '120.500000')));

        $this->assertSame([1001], $this->orders->paidOrders);
    }

    public function testBadSignatureLeavesTheOrderUntouched(): void
    {
        $request = new CallbackRequest('GET', self::PATH . '&' . $this->confirmed('uuid-1', '100.000000'), '', '', base64_encode(random_bytes(256)));

        $outcome = $this->processor->process($request);

        $this->assertSame(401, $outcome->httpStatus);
        $this->assertNotSame('*ok*', $outcome->body);
        $this->assertSame([], $this->orders->paidOrders);
        $this->assertSame([], $this->store->events);
        $this->assertSame('0', $this->store->findByAddressIn(self::ADDRESS)->received);
    }

    public function testMissingSignatureHeader(): void
    {
        $request = new CallbackRequest('GET', self::PATH . '&' . $this->confirmed('uuid-1', '100.000000'), '', '', null);

        $outcome = $this->processor->process($request);

        $this->assertSame(401, $outcome->httpStatus);
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testSignatureOverDifferentBytes(): void
    {
        $signedQuery = $this->confirmed('uuid-1', '1.000000');
        $tamperedQuery = $this->confirmed('uuid-1', '100.000000');
        $request = new CallbackRequest(
            'GET',
            self::PATH . '&' . $tamperedQuery,
            '',
            '',
            $this->pair->sign(self::ORIGIN . self::PATH . '&' . $signedQuery)
        );

        $outcome = $this->processor->process($request);

        $this->assertSame(401, $outcome->httpStatus);
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testSignedBodyCannotBeReplacedByAnotherBody(): void
    {
        $signedBody = $this->jsonBody('uuid-1', '1.000000');
        $request = new CallbackRequest('POST', self::PATH, 'application/json', $this->jsonBody('uuid-1', '100.000000'), $this->pair->sign($signedBody));

        $outcome = $this->processor->process($request);

        $this->assertSame(401, $outcome->httpStatus);
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testJsonPostMarksPaid(): void
    {
        $body = $this->jsonBody('uuid-1', '100.000000');
        $request = new CallbackRequest('POST', self::PATH . '&order=1001&nonce=' . self::NONCE, 'application/json', $body, $this->pair->sign($body));

        $outcome = $this->processor->process($request);

        $this->assertSame('paid', $outcome->reason);
        $this->assertSame([1001], $this->orders->paidOrders);
    }

    public function testFormPostMarksPaid(): void
    {
        $body = http_build_query([
            'uuid' => 'uuid-1', 'address_in' => self::ADDRESS, 'address_out' => self::PAYOUT,
            'coin' => 'trc20_usdt', 'pending' => '0', 'value_coin' => '100.000000',
        ]);
        $request = new CallbackRequest('POST', self::PATH, 'application/x-www-form-urlencoded', $body, $this->pair->sign($body));

        $outcome = $this->processor->process($request);

        $this->assertSame('paid', $outcome->reason);
    }

    public function testWrongNonceIsRefused(): void
    {
        $query = http_build_query([
            'order' => '1001', 'nonce' => 'wrong',
            'uuid' => 'uuid-1', 'address_in' => self::ADDRESS, 'address_out' => self::PAYOUT,
            'coin' => 'trc20_usdt', 'pending' => '0', 'value_coin' => '100.000000',
        ]);

        $outcome = $this->processor->process($this->signedGet($query));

        $this->assertSame(400, $outcome->httpStatus);
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testWrongCoinIsRefused(): void
    {
        $query = str_replace('trc20_usdt', 'bep20_usdt', $this->confirmed('uuid-1', '100.000000'));

        $outcome = $this->processor->process($this->signedGet($query));

        $this->assertSame(400, $outcome->httpStatus);
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testUnknownAddressIsAcknowledgedWithoutEffect(): void
    {
        $query = str_replace(self::ADDRESS, 'TSomeoneElse', $this->confirmed('uuid-1', '100.000000'));

        $outcome = $this->processor->process($this->signedGet($query));

        $this->assertSame('unknown_address', $outcome->reason);
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testInvalidAmountFormatIsRefused(): void
    {
        foreach (['1e2', '-5', '', 'abc', '0', '100,5'] as $bad) {
            $outcome = $this->processor->process($this->signedGet($this->confirmed('uuid-' . $bad, $bad)));
            $this->assertSame(400, $outcome->httpStatus, 'value ' . $bad);
        }
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testFailureWhileMarkingPaidRollsBackSoRetryWorks(): void
    {
        $request = $this->signedGet($this->confirmed('uuid-1', '100.000000'));
        $this->orders->failNext = true;

        $failed = $this->processor->process($request);
        $this->assertSame(500, $failed->httpStatus);
        $this->assertSame([], $this->store->events);
        $this->assertSame(PaymentRecord::AWAITING, $this->store->findByAddressIn(self::ADDRESS)->status);

        $retry = $this->processor->process($request);
        $this->assertSame('paid', $retry->reason);
        $this->assertSame([1001], $this->orders->paidOrders);
    }

    public function testAmountsStayDecimalStrings(): void
    {
        $this->orders->quotes[1002] = ['amount' => '0.30', 'ticker' => 'trc20/usdt'];
        $this->store->save(new PaymentRecord(1002, self::NONCE, 'trc20/usdt', 'TOther', self::PAYOUT, '0.30', '0.1', '0', PaymentRecord::AWAITING));
        $query = http_build_query([
            'order' => '1002', 'nonce' => self::NONCE,
            'uuid' => 'uuid-a', 'address_in' => 'TOther', 'address_out' => self::PAYOUT,
            'coin' => 'trc20_usdt', 'pending' => '0', 'value_coin' => '0.1',
        ]);
        $this->processor->process($this->signedGet($query));
        $query = http_build_query([
            'order' => '1002', 'nonce' => self::NONCE,
            'uuid' => 'uuid-b', 'address_in' => 'TOther', 'address_out' => self::PAYOUT,
            'coin' => 'trc20_usdt', 'pending' => '0', 'value_coin' => '0.2',
        ]);
        $this->processor->process($this->signedGet($query));

        $this->assertSame([1002], $this->orders->paidOrders);
        $this->assertSame('0.3', $this->store->findByAddressIn('TOther')->received);
    }

    public function testReadSumAndDecideHappenInOneTransactionUnderTheRowLock(): void
    {
        $this->store->operations = [];

        $this->processor->process($this->signedGet($this->confirmed('uuid-1', '100.000000')));

        $this->assertSame(
            ['begin', 'lock:' . self::ADDRESS, 'claim:uuid-1', 'save:paid:100.000000', 'commit'],
            $this->store->operations
        );
    }

    public function testDuplicateUuidStillTakesTheLockAndChangesNothing(): void
    {
        $request = $this->signedGet($this->confirmed('uuid-1', '40.000000'));
        $this->processor->process($request);
        $this->store->operations = [];

        $this->processor->process($request);

        $this->assertSame(['begin', 'lock:' . self::ADDRESS, 'claim:uuid-1', 'commit'], $this->store->operations);
        $this->assertSame('40.000000', $this->store->findByAddressIn(self::ADDRESS)->received);
    }

    public function testTwoDifferentUuidsCreditExactlyOnceEachAndPayOnce(): void
    {
        $this->processor->process($this->signedGet($this->confirmed('uuid-1', '60.000000')));
        $this->processor->process($this->signedGet($this->confirmed('uuid-2', '60.000000')));
        $this->processor->process($this->signedGet($this->confirmed('uuid-2', '60.000000')));
        $this->processor->process($this->signedGet($this->confirmed('uuid-3', '10.000000')));

        $this->assertSame([1001], $this->orders->paidOrders);
        $this->assertSame('130.000000', $this->store->findByAddressIn(self::ADDRESS)->received);
        $this->assertSame(['uuid-1', 'uuid-2', 'uuid-3'], array_keys($this->store->events));
    }

    public function testNoStoreAccessOutsideTheLockedTransaction(): void
    {
        $this->expectException(\LogicException::class);

        $this->store->lockByAddressIn(self::ADDRESS);
    }

    public function testSameTransactionHashUnderTwoUuidsCountsAsTwoDeposits(): void
    {
        $first = $this->confirmed('uuid-1', '60.000000') . '&txid_in=abc';
        $second = $this->confirmed('uuid-2', '60.000000') . '&txid_in=abc';

        $this->processor->process($this->signedGet($first));
        $this->processor->process($this->signedGet($second));

        $this->assertSame('120.000000', $this->store->findByAddressIn(self::ADDRESS)->received);
    }

    public function testChangedAmountOrderPaidAtTheOldAmountIsNotMarkedPaid(): void
    {
        $this->supersedeWithNewAddress('150.00');

        $outcome = $this->processor->process($this->signedGet($this->confirmed('uuid-old', '100.000000')));

        $this->assertSame(200, $outcome->httpStatus);
        $this->assertSame('superseded', $outcome->reason);
        $this->assertSame([], $this->orders->paidOrders);
        $old = $this->store->findByAddressIn(self::ADDRESS);
        $this->assertSame(PaymentRecord::SUPERSEDED, $old->status);
        $this->assertSame('100.000000', $old->received);
        $this->assertCount(1, $this->orders->notes);
        $this->assertSame(1001, $this->orders->notes[0][0]);
        $this->assertSame(PaymentRecord::AWAITING, $this->store->findByAddressIn('TNewAddress')->status);
    }

    public function testNewAddressStillWorksAfterTheAmountChanged(): void
    {
        $this->supersedeWithNewAddress('150.00');

        $outcome = $this->processor->process($this->signedGet($this->confirmedFor('TNewAddress', 'uuid-new', '150.000000')));

        $this->assertSame('paid', $outcome->reason);
        $this->assertSame([1001], $this->orders->paidOrders);
        $this->assertSame(PaymentRecord::PAID, $this->store->findByAddressIn('TNewAddress')->status);
        $this->assertSame([], $this->orders->notes);
    }

    public function testSupersededAddressNeverPaysEvenWhenTheOldAmountIsLaterRestored(): void
    {
        $this->supersedeWithNewAddress('150.00');
        $this->orders->quotes[1001] = ['amount' => '100.00', 'ticker' => 'trc20/usdt'];

        $outcome = $this->processor->process($this->signedGet($this->confirmed('uuid-old', '100.000000')));

        $this->assertSame('superseded', $outcome->reason);
        $this->assertSame([], $this->orders->paidOrders);
    }

    public function testCurrentRecordWhoseQuoteNoLongerMatchesTheOrderIsNotMarkedPaid(): void
    {
        $this->orders->quotes[1001] = ['amount' => '120.00', 'ticker' => 'trc20/usdt'];

        $outcome = $this->processor->process($this->signedGet($this->confirmed('uuid-1', '100.000000')));

        $this->assertSame('order_changed', $outcome->reason);
        $this->assertSame([], $this->orders->paidOrders);
        $this->assertCount(1, $this->orders->notes);
        $this->assertSame('100.000000', $this->store->findByAddressIn(self::ADDRESS)->received);
        $this->assertNotSame(PaymentRecord::PAID, $this->store->findByAddressIn(self::ADDRESS)->status);
    }

    public function testCurrentRecordWithAnotherTickerOrUnreadableOrderIsNotMarkedPaid(): void
    {
        $this->orders->quotes[1001] = ['amount' => '100.00', 'ticker' => 'bep20/usdt'];
        $first = $this->processor->process($this->signedGet($this->confirmed('uuid-1', '100.000000')));

        unset($this->orders->quotes[1001]);
        $second = $this->processor->process($this->signedGet($this->confirmed('uuid-2', '1.000000')));

        $this->assertSame('order_changed', $first->reason);
        $this->assertSame('order_changed', $second->reason);
        $this->assertSame([], $this->orders->paidOrders);
    }

    private function supersedeWithNewAddress(string $amount): void
    {
        $old = $this->store->findByAddressIn(self::ADDRESS);
        $this->store->save($old->with($old->received, PaymentRecord::SUPERSEDED));
        $this->store->save(new PaymentRecord(1001, self::NONCE, 'trc20/usdt', 'TNewAddress', self::PAYOUT, $amount, '10.000000', '0', PaymentRecord::AWAITING));
        $this->orders->quotes[1001] = ['amount' => $amount, 'ticker' => 'trc20/usdt'];
    }

    private function confirmedFor(string $address, string $uuid, string $value): string
    {
        return http_build_query([
            'order' => '1001', 'nonce' => self::NONCE,
            'uuid' => $uuid, 'address_in' => $address, 'address_out' => self::PAYOUT,
            'coin' => 'trc20_usdt', 'pending' => '0', 'value_coin' => $value,
        ]);
    }

    private function confirmed(string $uuid, string $value): string
    {
        return http_build_query([
            'order' => '1001', 'nonce' => self::NONCE,
            'uuid' => $uuid, 'address_in' => self::ADDRESS, 'address_out' => self::PAYOUT,
            'coin' => 'trc20_usdt', 'pending' => '0', 'value_coin' => $value,
        ]);
    }

    private function pending(string $uuid): string
    {
        return http_build_query([
            'order' => '1001', 'nonce' => self::NONCE,
            'uuid' => $uuid, 'address_in' => self::ADDRESS, 'address_out' => self::PAYOUT,
            'coin' => 'trc20_usdt', 'pending' => '1',
        ]);
    }

    private function jsonBody(string $uuid, string $value): string
    {
        return json_encode([
            'uuid' => $uuid, 'address_in' => self::ADDRESS, 'address_out' => self::PAYOUT,
            'coin' => 'trc20_usdt', 'pending' => '0', 'value_coin' => $value,
        ]);
    }

    private function signedGet(string $query): CallbackRequest
    {
        $uri = self::PATH . '&' . $query;
        return new CallbackRequest('GET', $uri, '', '', $this->pair->sign(self::ORIGIN . $uri));
    }
}
