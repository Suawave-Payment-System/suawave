<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Closure;
use DateTimeZone;
use Suawave\CallbackProcessor;
use Suawave\CallbackRequest;
use Suawave\CheckoutService;
use Suawave\ExpiryNotifier;
use Suawave\GatewayClient;
use Suawave\HttpResponse;
use Suawave\HttpTransport;
use Suawave\PaymentPage;
use Suawave\PaymentRecord;
use Suawave\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class ExpiryFlowTest extends TestCase
{
    private const ORIGIN = 'https://shop.example';
    private const PATH = '/index.php?route=extension/suawave/payment/suawave.callback';
    private const FIRST = '2026-11-09T12:00:00Z';
    private const SECOND = '2026-11-10T12:00:00Z';
    private const THIRD = '2026-11-11T12:00:00Z';

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
        $this->orders->quotes[55] = ['amount' => '25.00', 'ticker' => 'trc20/usdt'];
        $this->processor = new CallbackProcessor(new SignatureVerifier($this->pair->publicPem), self::ORIGIN, $this->store, $this->orders);
        $this->now = strtotime(self::FIRST) - 3600;
    }

    public function testRenewalAfterExpirySupersedesTheOldAttemptUnderTheOrderLock(): void
    {
        $transport = new InterleavingTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]);
        $service = $this->service($transport);
        $first = $service->begin(55, '25.00');

        $this->now = strtotime(self::FIRST);
        $this->store->operations = [];
        $second = $service->begin(55, '25.00');

        $this->assertNotSame($first->nonce, $second->nonce);
        $this->assertSame('TB', $second->addressIn);
        $this->assertSame(PaymentRecord::SUPERSEDED, $this->store->records['TA']->status);
        $this->assertSame($second, $this->store->findByOrderId(55));
        $this->assertStringContainsString(rawurlencode('nonce=' . $second->nonce), $transport->requested[1]);
        $this->assertSame(
            ['order-lock:55', 'begin', 'lock:TA', 'save:superseded:0', 'lock:TB', 'save:awaiting:0', 'commit', 'order-unlock:55'],
            $this->store->operations
        );
    }

    public function testSupersededAttemptConfirmedAfterTheNewOnePaidAddsOneNoteAndKeepsTheStatus(): void
    {
        $service = $this->service(new InterleavingTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]));
        $old = $service->begin(55, '25.00');
        $this->now = strtotime(self::FIRST) + 60;
        $new = $service->begin(55, '25.00');

        $this->assertSame('paid', $this->processor->process($this->confirmed($new, 'uuid-new', '25.000000'))->reason);
        $late = $this->processor->process($this->confirmed($old, 'uuid-old', '25.000000'));
        $again = $this->processor->process($this->confirmed($old, 'uuid-old', '25.000000'));

        $this->assertSame('already_paid', $late->reason);
        $this->assertSame('duplicate', $again->reason);
        $this->assertSame([55], $this->orders->paidOrders);
        $this->assertCount(1, $this->orders->notes);
        $this->assertStringContainsString('payment received on a previous attempt, reconcile', $this->orders->notes[0][1]);
        $this->assertSame(PaymentRecord::SUPERSEDED, $this->store->records['TA']->status);
        $this->assertSame('25.000000', $this->store->records['TA']->received);
        $this->assertSame(PaymentRecord::PAID, $this->store->records['TB']->status);
    }

    public function testSupersededAttemptConfirmedOnAnUnpaidOrderPaysItOnceAndIsRecordedAsThePayingAttempt(): void
    {
        $transport = new InterleavingTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]);
        $service = $this->service($transport);
        $old = $service->begin(55, '25.00');
        $this->now = strtotime(self::FIRST) + 60;
        $new = $service->begin(55, '25.00');
        $this->store->operations = [];

        $paid = $this->processor->process($this->confirmed($old, 'uuid-old', '25.000000'));
        $this->assertSame('order-lock:55', $this->store->operations[0]);
        $second = $this->processor->process($this->confirmed($new, 'uuid-new', '25.000000'));

        $this->assertSame('paid', $paid->reason);
        $this->assertSame('already_paid', $second->reason);
        $this->assertSame([55], $this->orders->paidOrders);
        $this->assertStringContainsString('completed on TA', $this->orders->paidComments[0]);
        $this->assertSame(PaymentRecord::PAID, $this->store->records['TA']->status);
        $this->assertSame('TA', $this->store->findPaidByOrderId(55)->addressIn);
        $this->assertCount(1, $this->orders->notes);
        $this->assertStringContainsString('payment received on a previous attempt, reconcile', $this->orders->notes[0][1]);

        $again = $service->begin(55, '25.00');
        $this->assertSame('TA', $again->addressIn);
        $this->assertCount(2, $transport->requested);
        $this->assertSame(['status' => 'paid', 'window_closed' => false], $this->page()->confirmPayload($again, $this->now));
        $this->assertSame(0, $this->notifier()->sweep(strtotime(self::SECOND) + 60));
        $this->assertSame([], $this->orders->customerNotes);
    }

    public function testExtraDepositOnAPaidAttemptAddsOneNoteAndNeverPaysAgain(): void
    {
        $record = $this->service(new InterleavingTransport([$this->answer('TA', self::FIRST)]))->begin(55, '25.00');

        $this->processor->process($this->confirmed($record, 'uuid-1', '25.000000'));
        $extra = $this->processor->process($this->confirmed($record, 'uuid-2', '5.000000'));

        $this->assertSame('already_paid', $extra->reason);
        $this->assertSame([55], $this->orders->paidOrders);
        $this->assertCount(1, $this->orders->notes);
        $this->assertStringContainsString('previous attempt, reconcile', $this->orders->notes[0][1]);
    }

    public function testOldCallbackPayingDuringTheCreateRequestKeepsThePaidAttempt(): void
    {
        $transport = new InterleavingTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]);
        $service = $this->service($transport);
        $old = $service->begin(55, '25.00');
        $this->now = strtotime(self::FIRST) + 60;
        $outcomes = [];
        $transport->during[1] = function () use ($old, &$outcomes): void {
            $outcomes[] = $this->processor->process($this->confirmed($old, 'uuid-old', '25.000000'))->reason;
        };

        $result = $service->begin(55, '25.00');

        $this->assertSame(['paid'], $outcomes);
        $this->assertSame('TA', $result->addressIn);
        $this->assertSame(PaymentRecord::PAID, $this->store->records['TA']->status);
        $this->assertArrayNotHasKey('TB', $this->store->records);
        $fresh = new PaymentRecord(55, 'x', 'trc20/usdt', 'TB', 'TPayout', '25.00', '10.000000', '0', PaymentRecord::AWAITING);
        $this->assertSame('unknown_address', $this->processor->process($this->confirmed($fresh, 'uuid-new', '25.000000'))->reason);
        $this->assertSame([55], $this->orders->paidOrders);
        $this->assertSame(['status' => 'paid', 'window_closed' => false], $this->page()->confirmPayload($result, $this->now));
    }

    public function testPaidAttemptPastItsDeadlineIsNeitherRenewedNorShownAgain(): void
    {
        $transport = new InterleavingTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]);
        $service = $this->service($transport);
        $record = $service->begin(55, '25.00');
        $this->processor->process($this->confirmed($record, 'uuid-1', '25.000000'));
        $this->now = strtotime(self::FIRST) + 3600;

        $again = $service->begin(55, '25.00');
        $payload = $this->page()->confirmPayload($again, $this->now);

        $this->assertCount(1, $transport->requested);
        $this->assertSame(PaymentRecord::PAID, $again->status);
        $this->assertSame(['status' => 'paid', 'window_closed' => false], $payload);
        $this->assertStringNotContainsString('TA', (string) json_encode($payload));
    }

    public function testDetectedDepositPastTheDeadlineIsNotRenewedAndTheAddressIsHidden(): void
    {
        $transport = new InterleavingTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]);
        $service = $this->service($transport);
        $record = $service->begin(55, '25.00');
        $this->processor->process($this->pending($record, 'uuid-p'));
        $this->now = strtotime(self::FIRST) + 60;

        $again = $service->begin(55, '25.00');
        $payload = $this->page()->confirmPayload($again, $this->now);

        $this->assertCount(1, $transport->requested);
        $this->assertSame(PaymentRecord::DETECTED, $again->status);
        $this->assertSame(['status' => 'detected', 'window_closed' => true], $payload);
        $this->assertSame('paid', $this->processor->process($this->confirmed($record, 'uuid-c', '25.000000'))->reason);
    }

    public function testPayloadsShowTheAddressInsideTheWindowAndOnlyTheClosedTextWithTheOrderIdAfterIt(): void
    {
        $record = $this->service(new InterleavingTransport([$this->answer('TA', self::FIRST)]))->begin(55, '25.00');
        $page = $this->page();

        $open = $page->confirmPayload($record, $this->now);
        $this->assertSame('TA', $open['address']);
        $this->assertSame('TA', $open['qr_text']);
        $this->assertSame('2026-11-09 15:00 (Europe/Istanbul)', $open['deadline']);
        $this->assertSame('Pay before 2026-11-09 15:00 (Europe/Istanbul).', $page->historyComment($record));

        foreach ([$page->confirmPayload($record, strtotime(self::FIRST)), $page->statusPayload($record, strtotime(self::FIRST))] as $closed) {
            $this->assertSame('closed', $closed['status']);
            $this->assertTrue($closed['window_closed']);
            $this->assertSame(55, $closed['order_id']);
            $this->assertSame('Order 55 closed on 2026-11-09 15:00 (Europe/Istanbul).', $closed['closed_text']);
            $this->assertArrayNotHasKey('address', $closed);
            $this->assertArrayNotHasKey('qr_text', $closed);
            $this->assertStringNotContainsString('TA', (string) json_encode($closed));
        }
        $this->assertSame(['status' => 'waiting', 'window_closed' => false], $page->statusPayload($record, $this->now));
        $this->assertSame(['status' => 'unknown', 'window_closed' => false], $page->statusPayload(null, $this->now));
    }

    public function testCheckoutTemplateDropsTheAddressAndReEnablesThePayButtonWhenTheWindowCloses(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__) . '/upload/extension/suawave/catalog/view/template/payment/suawave.twig');
        $close = substr($template, strpos($template, 'function closeWindow'));
        $close = substr($close, 0, strpos($close, "\n  }\n"));

        $this->assertStringContainsString('hideDestination();', $close);
        $this->assertStringContainsString('button.disabled = false;', $close);
        $this->assertStringContainsString("if (data.status === 'closed') {\n      closeWindow(data.closed_text);", $template);
        $this->assertStringContainsString("if (data.window_closed) {\n      hideDestination();", $template);
        $this->assertStringContainsString("document.getElementById('suawave-address').textContent = '';", $template);
        $this->assertStringContainsString("document.getElementById('suawave-qr').innerHTML = '';", $template);
        $this->assertSame(1, substr_count($template, 'renderQr(data.qr_text)'));
        $this->assertStringContainsString("if (data.address) {\n          document.getElementById('suawave-amount')", $template);
    }

    public function testOneExpiryNotePerAttemptUnderTheOrderLockAndNoneOncePaid(): void
    {
        $service = $this->service(new InterleavingTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND), $this->answer('TC', self::THIRD)]));
        $notifier = $this->notifier();
        $service->begin(55, '25.00');

        $this->assertSame(0, $notifier->sweep($this->now));
        $this->store->operations = [];
        $this->assertSame(1, $notifier->sweep(strtotime(self::FIRST)));
        $this->assertSame('order-lock:55', $this->store->operations[0]);
        $this->assertSame(0, $notifier->sweep(strtotime(self::FIRST) + 600));
        $this->assertSame(strtotime(self::FIRST), $this->store->records['TA']->expiryNotedAt);
        $this->assertSame(PaymentRecord::AWAITING, $this->store->records['TA']->status);

        $this->now = strtotime(self::FIRST) + 600;
        $second = $service->begin(55, '25.00');
        $this->assertSame(0, $notifier->sweep(strtotime(self::SECOND) - 1));
        $this->assertSame(1, $notifier->sweep(strtotime(self::SECOND)));
        $this->assertSame(0, $notifier->sweep(strtotime(self::SECOND) + 600));
        $this->assertSame([[55, 'Order 55 closed on 2026-11-09 15:00 (Europe/Istanbul).'], [55, 'Order 55 closed on 2026-11-10 15:00 (Europe/Istanbul).']], $this->orders->customerNotes);

        $this->now = strtotime(self::SECOND) + 600;
        $third = $service->begin(55, '25.00');
        $this->processor->process($this->confirmed($third, 'uuid-3', '25.000000'));
        $this->assertSame(0, $notifier->sweep(strtotime(self::THIRD) + 600));
        $this->assertCount(2, $this->orders->customerNotes);
        $this->assertSame(PaymentRecord::SUPERSEDED, $this->store->records[$second->addressIn]->status);
    }

    public function testNoNoteWhenTheOrderWasPaidBetweenTheReadAndTheNote(): void
    {
        $record = $this->service(new InterleavingTransport([$this->answer('TA', self::FIRST)]))->begin(55, '25.00');
        $this->processor->process($this->confirmed($record, 'uuid-1', '25.000000'));

        $this->assertFalse($this->notifier()->noteIfDue($record, strtotime(self::FIRST) + 60));
        $this->assertSame([], $this->orders->customerNotes);
    }

    public function testSupersededUnpaidAttemptIsNotNotedAfterTheReplacementPaid(): void
    {
        $service = $this->service(new InterleavingTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]));
        $service->begin(55, '25.00');
        $this->now = strtotime(self::FIRST) + 60;
        $new = $service->begin(55, '25.00');
        $this->processor->process($this->confirmed($new, 'uuid-new', '25.000000'));

        $this->assertSame(0, $this->notifier()->sweep(strtotime(self::THIRD)));
        $this->assertSame([], $this->orders->customerNotes);
    }

    public function testStaleUnpaidRowThatIsNotTheCurrentAttemptLeavesTheSweepWithoutANote(): void
    {
        $stale = new PaymentRecord(56, 'n1', 'trc20/usdt', 'TS1', 'TPayout', '25.00', '10', '0', PaymentRecord::AWAITING, strtotime(self::FIRST));
        $current = new PaymentRecord(56, 'n2', 'trc20/usdt', 'TS2', 'TPayout', '25.00', '10', '0', PaymentRecord::PAID, strtotime(self::FIRST));
        $due = new PaymentRecord(57, 'n3', 'trc20/usdt', 'TS3', 'TPayout', '25.00', '10', '0', PaymentRecord::AWAITING, strtotime(self::FIRST));
        foreach ([$stale, $current, $due] as $record) {
            $this->store->save($record);
        }

        $this->assertSame(1, $this->notifier()->sweep(strtotime(self::FIRST) + 60));

        $this->assertSame([57], array_column($this->orders->customerNotes, 0));
        $this->assertNotNull($this->store->records['TS1']->expiryNotedAt);
        $this->assertNull($this->store->findOverdue(strtotime(self::FIRST) + 60));
    }

    private function service(InterleavingTransport $transport): CheckoutService
    {
        return new CheckoutService(
            new GatewayClient('https://api.example', $transport),
            $this->store,
            'trc20/usdt',
            'TPayout',
            self::ORIGIN . self::PATH,
            fn (): int => $this->now
        );
    }

    private function notifier(): ExpiryNotifier
    {
        $page = $this->page();
        return new ExpiryNotifier($this->store, $this->orders, fn (PaymentRecord $record): string => $page->closedText($record));
    }

    private function page(): PaymentPage
    {
        return new PaymentPage(['text_pay_before' => 'Pay before %s.', 'text_closed' => 'Order %s closed on %s.'], new DateTimeZone('Europe/Istanbul'));
    }

    private function answer(string $address, string $expiresAt): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode([
            'status' => 'success', 'address_in' => $address, 'address_out' => 'TPayout',
            'callback_url' => 'u', 'minimum_transaction_coin' => '10.000000', 'expires_at' => $expiresAt,
        ]));
    }

    private function confirmed(PaymentRecord $record, string $uuid, string $value): CallbackRequest
    {
        return $this->signedGet($record, ['uuid' => $uuid, 'pending' => '0', 'value_coin' => $value]);
    }

    private function pending(PaymentRecord $record, string $uuid): CallbackRequest
    {
        return $this->signedGet($record, ['uuid' => $uuid, 'pending' => '1']);
    }

    private function signedGet(PaymentRecord $record, array $event): CallbackRequest
    {
        $uri = self::PATH . '&' . http_build_query([
            'order' => (string) $record->orderId, 'nonce' => $record->nonce,
            'address_in' => $record->addressIn, 'address_out' => $record->payoutAddress, 'coin' => 'trc20_usdt',
        ] + $event);
        return new CallbackRequest('GET', $uri, '', '', $this->pair->sign(self::ORIGIN . $uri));
    }
}

final class InterleavingTransport implements HttpTransport
{
    public array $requested = [];
    public array $during = [];

    public function __construct(private array $responses)
    {
    }

    public function get(string $url): HttpResponse
    {
        $index = count($this->requested);
        $this->requested[] = $url;
        if (isset($this->during[$index]) && $this->during[$index] instanceof Closure) {
            ($this->during[$index])();
        }
        return $this->responses[$index];
    }
}
