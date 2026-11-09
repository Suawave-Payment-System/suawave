<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\CallbackProcessor;
use Suawave\CallbackRequest;
use Suawave\CheckoutService;
use Suawave\ExpiryNotifier;
use Suawave\GatewayClient;
use Suawave\HttpResponse;
use Suawave\HttpTransport;
use Suawave\PaymentLabel;
use Suawave\PaymentRecord;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AttemptLifecycleTest extends TestCase
{
    private const ORIGIN = 'https://shop.example';
    private const PATH = '/index.php?fc=module&module=suawave&controller=callback';
    private const FIRST = '2026-11-09T12:00:00Z';
    private const SECOND = '2026-11-10T12:00:00Z';

    private TestKeyPair $pair;
    private InMemoryPaymentStore $store;
    private RecordingOrderGateway $orders;
    private CallbackProcessor $processor;
    private int $now;

    protected function setUp(): void
    {
        $this->pair = TestKeyPair::generate();
        $this->store = new InMemoryPaymentStore();
        $this->orders = new RecordingOrderGateway();
        $this->processor = new CallbackProcessor(
            new StaticKeyProvider($this->pair->publicPem, $this->pair->publicPem),
            self::ORIGIN,
            $this->store,
            $this->orders
        );
        $this->now = strtotime(self::FIRST) - 3600;
    }

    public function testRenewalOfAnExpiredAttemptSupersedesItUnderTheCartLock(): void
    {
        $service = $this->service(new ScriptedTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]));
        $first = $service->attachOrder($service->begin(7, '25.00'), 55);
        $this->now = strtotime(self::FIRST) + 1;
        $this->store->operations = [];

        $second = $service->begin(7, '25.00');

        $this->assertNotSame($first->nonce, $second->nonce);
        $this->assertSame('TB', $second->addressIn);
        $this->assertSame(55, $second->orderId);
        $this->assertSame(PaymentRecord::SUPERSEDED, $this->store->findByAddressIn('TA')->status);
        $this->assertSame(['begin', 'lock:7', 'save:superseded:0', 'save:awaiting:0', 'commit'], $this->store->operations);
        $this->assertSame($second, $service->resume(7));
    }

    public function testSupersededAttemptConfirmedAfterTheNewOnePaidOnlyAddsOneNote(): void
    {
        [$first, $second] = $this->renewedOrder();

        $this->assertSame('paid', $this->processor->process($this->confirmed($second, 'u-new', '25.00'))->reason);
        $late = $this->processor->process($this->confirmed($first, 'u-old', '25.00'));
        $again = $this->processor->process($this->confirmed($first, 'u-old', '25.00'));

        $this->assertSame('already_paid', $late->reason);
        $this->assertSame('duplicate', $again->reason);
        $this->assertSame([55], $this->orders->paidOrders);
        $this->assertSame([[55, CallbackProcessor::RECONCILE_NOTE . ': 25.00 TRC20/USDT to TA']], $this->orders->notes);
        $this->assertSame(PaymentRecord::SUPERSEDED, $this->store->findByAddressIn('TA')->status);
        $this->assertSame('25.00', $this->store->findByAddressIn('TA')->received);
        $this->assertSame(PaymentRecord::PAID, $this->store->findByAddressIn('TB')->status);
    }

    public function testSupersededAttemptConfirmedOnAnUnpaidOrderPaysItOnceAndRecordsTheAttempt(): void
    {
        [$first, $second] = $this->renewedOrder();

        $this->assertSame('pending', $this->processor->process($this->pending($first, 'u-old-p'))->reason);
        $this->assertSame('paid_previous_attempt', $this->processor->process($this->confirmed($first, 'u-old', '25.00'))->reason);
        $this->assertSame([55], $this->orders->paidOrders);
        $this->assertSame(PaymentRecord::PAID, $this->store->findByAddressIn('TA')->status);
        $this->assertSame('TA', $this->store->findByOrderId(55)->addressIn);
        $this->assertSame('TA', $this->store->findByCartId(7)->addressIn);

        $this->assertSame('already_paid', $this->processor->process($this->confirmed($second, 'u-new', '25.00'))->reason);
        $this->assertSame([55], $this->orders->paidOrders);
        $this->assertSame([[55, CallbackProcessor::RECONCILE_NOTE . ': 25.00 TRC20/USDT to TB']], $this->orders->notes);
        $this->assertSame(PaymentRecord::AWAITING, $this->store->findByAddressIn('TB')->status);

        $notifier = new ExpiryNotifier($this->store, $this->orders, 'window closed');
        $this->assertSame(0, $notifier->sweep(strtotime(self::SECOND) + 1));
        $this->assertFalse($notifier->noteIfDue($this->store->findByAddressIn('TB'), strtotime(self::SECOND) + 1));
        $this->assertCount(1, $this->orders->notes);
    }

    public function testSupersededAttemptUnderpaidOnAnUnpaidOrderKeepsWaiting(): void
    {
        [$first] = $this->renewedOrder();

        $this->assertSame('underpaid', $this->processor->process($this->confirmed($first, 'u-old', '10.00'))->reason);

        $this->assertSame([], $this->orders->paidOrders);
        $this->assertSame(PaymentRecord::SUPERSEDED, $this->store->findByAddressIn('TA')->status);
        $this->assertSame('10.00', $this->store->findByAddressIn('TA')->received);
    }

    public function testOldAttemptPayingDuringTheCreateRequestStopsTheRenewal(): void
    {
        $transport = new HookedTransport([$this->answer('TA', self::FIRST)]);
        $service = $this->service($transport);
        $first = $service->attachOrder($service->begin(7, '25.00'), 55);
        $this->now = strtotime(self::FIRST) + 1;
        $renewal = new HookedTransport([$this->answer('TB', self::SECOND)], function () use ($first): void {
            $this->assertSame('paid', $this->processor->process($this->confirmed($first, 'u-old', '25.00'))->reason);
        });

        $result = $this->service($renewal)->begin(7, '25.00');

        $this->assertSame('TA', $result->addressIn);
        $this->assertSame(PaymentRecord::PAID, $result->status);
        $this->assertNull($this->store->findByAddressIn('TB'));
        $this->assertSame([55], $this->orders->paidOrders);
        $this->assertCount(1, $renewal->requested);

        $stray = new PaymentRecord(7, 55, 'n', 'trc20/usdt', 'TB', 'TPayout', '25.00', '10', '0', PaymentRecord::AWAITING);
        $this->assertSame('unknown_address', $this->processor->process($this->confirmed($stray, 'u-new', '25.00'))->reason);
        $this->assertSame([55], $this->orders->paidOrders);
    }

    public function testCallbackWaitsForTheCartLockAndSeesTheCompletedRenewal(): void
    {
        $service = $this->service(new ScriptedTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]));
        $first = $service->attachOrder($service->begin(7, '25.00'), 55);
        $this->now = strtotime(self::FIRST) + 1;
        $seen = null;
        $this->store->onLock = function () use (&$seen): void {
            $seen = $this->store->findByCartId(7)->addressIn;
        };
        $service->begin(7, '25.00');
        $this->store->onLock = null;
        $this->store->operations = [];

        $outcome = $this->processor->process($this->confirmed($first, 'u-old', '25.00'));

        $this->assertSame('TA', $seen);
        $this->assertSame('paid_previous_attempt', $outcome->reason);
        $this->assertSame(['begin', 'lock:7', 'claim:u-old', 'save:paid:25.00', 'commit'], $this->store->operations);
        $this->assertSame([55], $this->orders->paidOrders);
    }

    public function testPaidOrderIsNeverRenewed(): void
    {
        $transport = new ScriptedTransport([$this->answer('TA', self::FIRST)]);
        $service = $this->service($transport);
        $first = $service->attachOrder($service->begin(7, '25.00'), 55);
        $this->processor->process($this->confirmed($first, 'u-1', '25.00'));
        $this->now = strtotime(self::FIRST) + 99;

        $again = $service->resume(7);

        $this->assertSame(PaymentRecord::PAID, $again->status);
        $this->assertSame('TA', $again->addressIn);
        $this->assertCount(1, $transport->requested);
    }

    public function testExpiryNoteOncePerAttemptAndNeverForASupersededAttemptOfAPaidOrder(): void
    {
        $service = $this->service(new ScriptedTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]));
        $service->attachOrder($service->begin(7, '25.00'), 55);
        $notifier = new ExpiryNotifier($this->store, $this->orders, 'window closed');

        $this->now = strtotime(self::FIRST) + 1;
        $this->assertSame(1, $notifier->sweep($this->now));
        $this->assertSame(0, $notifier->sweep($this->now));
        $second = $service->begin(7, '25.00');
        $this->assertSame(0, $notifier->sweep($this->now));

        $this->processor->process($this->confirmed($second, 'u-new', '25.00'));
        $this->assertSame(0, $notifier->sweep(strtotime(self::SECOND) + 1));
        $this->assertSame([[55, 'window closed']], $this->orders->notes);
    }

    public function testUnnotedAttemptSupersededBeforeItsNoteIsSkippedOnceTheReplacementPaid(): void
    {
        $service = $this->service(new ScriptedTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]));
        $service->attachOrder($service->begin(7, '25.00'), 55);
        $this->now = strtotime(self::FIRST) + 1;
        $second = $service->begin(7, '25.00');
        $this->processor->process($this->confirmed($second, 'u-new', '25.00'));

        $notifier = new ExpiryNotifier($this->store, $this->orders, 'window closed');

        $this->assertSame(0, $notifier->sweep(strtotime(self::SECOND) + 1));
        $this->assertSame([], $this->orders->notes);
        $this->assertSame([55], $this->orders->paidOrders);
    }

    public function testExpiryNoteChecksPaymentUnderTheLock(): void
    {
        $service = $this->service(new ScriptedTransport([$this->answer('TA', self::FIRST)]));
        $first = $service->attachOrder($service->begin(7, '25.00'), 55);
        $this->now = strtotime(self::FIRST) + 1;
        $stale = $this->store->findOverdue($this->now);
        $this->processor->process($this->confirmed($first, 'u-1', '25.00'));
        $this->store->operations = [];

        $noted = (new ExpiryNotifier($this->store, $this->orders, 'window closed'))->noteIfDue($stale, $this->now);

        $this->assertFalse($noted);
        $this->assertSame(['begin', 'lock:7', 'commit'], $this->store->operations);
        $this->assertSame([], $this->orders->notes);
        $this->assertSame(PaymentRecord::PAID, $this->store->findByAddressIn('TA')->status);
    }

    public function testPaymentLabelFollowsTheAttemptUnderTheCartLock(): void
    {
        $labels = new PaymentLabel('Suawave', new DateTimeZone('UTC'));
        $locked = [];
        $this->orders->probe = function (string $label) use (&$locked): void {
            $begins = array_keys($this->store->operations, 'begin', true);
            $open = array_slice($this->store->operations, (int) end($begins));
            $locked[$label] = in_array('lock:7', $open, true) && !in_array('commit', $open, true);
        };
        $service = $this->service(new ScriptedTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]), $labels);
        $first = $service->attachOrder($service->begin(7, '25.00'), 55);
        $notifier = new ExpiryNotifier($this->store, $this->orders, 'window closed', $labels);

        $this->now = strtotime(self::FIRST) + 1;
        $this->assertSame(1, $notifier->sweep($this->now));
        $second = $service->begin(7, '25.00');
        $processor = new CallbackProcessor(new StaticKeyProvider($this->pair->publicPem, $this->pair->publicPem), self::ORIGIN, $this->store, $this->orders, null, $labels);
        $this->assertSame('paid', $processor->process($this->confirmed($second, 'u-new', '25.00'))->reason);
        $this->assertSame('already_paid', $processor->process($this->confirmed($first, 'u-old', '25.00'))->reason);
        $this->assertSame(0, $notifier->sweep(strtotime(self::SECOND) + 1));
        $this->assertSame(PaymentRecord::PAID, $service->resume(7)->status);

        $this->assertSame([
            'label:Suawave - payment window closed',
            'label:Suawave - pay before 2026-11-10 12:00 (UTC)',
            'label:Suawave - paid',
            'paid:55',
        ], $this->orders->sequence);
        $this->assertSame(array_fill_keys(['Suawave - payment window closed', 'Suawave - pay before 2026-11-10 12:00 (UTC)', 'Suawave - paid'], true), $locked);
        $this->assertSame([55, 55, 55], array_column($this->orders->labels, 0));
    }

    public function testRenewalBeforeTheOrderExistsWritesNoLabel(): void
    {
        $labels = new PaymentLabel('Suawave', new DateTimeZone('UTC'));
        $service = $this->service(new ScriptedTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]), $labels);
        $service->begin(7, '25.00');
        $this->now = strtotime(self::FIRST) + 1;

        $service->begin(7, '25.00');

        $this->assertSame([], $this->orders->labels);
    }

    private function renewedOrder(): array
    {
        $service = $this->service(new ScriptedTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]));
        $first = $service->attachOrder($service->begin(7, '25.00'), 55);
        $this->now = strtotime(self::FIRST) + 1;
        return [$first, $service->begin(7, '25.00')];
    }

    private function service(HttpTransport $transport, ?PaymentLabel $labels = null): CheckoutService
    {
        return new CheckoutService(
            new GatewayClient('https://api.example', $transport),
            $this->store,
            'trc20/usdt',
            'TPayout',
            'https://shop.example/cb',
            fn (): int => $this->now,
            $this->orders,
            $labels
        );
    }

    private function answer(string $address, string $expiresAt): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'status' => 'success', 'address_in' => $address, 'address_out' => 'TPayout',
            'callback_url' => 'u', 'minimum_transaction_coin' => '10.000000', 'expires_at' => $expiresAt,
        ]));
    }

    private function confirmed(PaymentRecord $record, string $uuid, string $value): CallbackRequest
    {
        return $this->signed($record, ['uuid' => $uuid, 'pending' => '0', 'value_coin' => $value]);
    }

    private function pending(PaymentRecord $record, string $uuid): CallbackRequest
    {
        return $this->signed($record, ['uuid' => $uuid, 'pending' => '1']);
    }

    private function signed(PaymentRecord $record, array $event): CallbackRequest
    {
        $query = http_build_query([
            'cart' => (string) $record->cartId, 'nonce' => $record->nonce,
            'address_in' => $record->addressIn, 'address_out' => $record->payoutAddress, 'coin' => 'trc20_usdt',
        ] + $event);
        $uri = self::PATH . '&' . $query;
        return new CallbackRequest('GET', $uri, '', '', $this->pair->sign(self::ORIGIN . $uri));
    }
}
