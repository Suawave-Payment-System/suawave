<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\CallbackProcessor;
use Suawave\CallbackRequest;
use Suawave\HttpResponse;
use Suawave\HttpTransport;
use Suawave\OpenCartOrderGateway;
use Suawave\PaymentRecord;
use Suawave\PaymentStore;
use Suawave\SignatureVerifier;
use Suawave\Tests\Support\CheckoutModel;
use Suawave\Tests\Support\Config;
use Suawave\Tests\Support\Language;
use Suawave\Tests\Support\Loader;
use Suawave\Tests\Support\Log;
use Suawave\Tests\Support\Order;
use Suawave\Tests\Support\OrderDb;
use Suawave\Tests\Support\Registry;
use Suawave\Tests\Support\Response;
use Suawave\Tests\Support\Session;
use PHPUnit\Framework\TestCase;

if (!defined('DIR_EXTENSION')) {
    define('DIR_EXTENSION', dirname(__DIR__) . '/upload/extension/');
}
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}
require_once __DIR__ . '/Support/OpenCartStubs.php';
require_once DIR_EXTENSION . 'suawave/catalog/controller/payment/suawave.php';

final class TestableCatalogController extends \Opencart\Catalog\Controller\Extension\Suawave\Payment\Suawave
{
    public static PaymentStore $store;
    public static HttpTransport $transport;

    protected function paymentStore(): PaymentStore
    {
        return self::$store;
    }

    protected function httpTransport(): HttpTransport
    {
        return self::$transport;
    }
}

final class ControllerTransport implements HttpTransport
{
    public array $requested = [];
    public ?\Closure $during = null;

    public function __construct(private readonly array $answers)
    {
    }

    public function get(string $url): HttpResponse
    {
        $this->requested[] = $url;
        $during = $this->during;
        $this->during = null;
        if ($during !== null) {
            $during();
        }
        return $this->answers[count($this->requested) - 1];
    }
}

final class CatalogControllerTest extends TestCase
{
    private const ORIGIN = 'https://shop.example';
    private const PATH = '/index.php?route=extension/suawave/payment/suawave.callback';
    private const PENDING = 1;
    private const PAID = 5;

    private TestKeyPair $pair;
    private InMemoryPaymentStore $store;
    private Order $order;
    private Response $response;
    private array $historyUnderLock = [];

    protected function setUp(): void
    {
        $this->pair = TestKeyPair::generate();
        $this->store = new InMemoryPaymentStore();
        $this->order = new Order(self::PENDING, '25.0000');
        $this->order->onHistory = function (): void {
            $this->historyUnderLock[] = $this->store->holdsOrderLock(55);
        };
        TestableCatalogController::$store = $this->store;
    }

    public function testPaymentArrivingRightAfterTheRenewalIsNotRevertedByTheDeadlineHistory(): void
    {
        $old = $this->expiredAttempt();
        $this->transport([$this->answer('TB', time() + 3600)]);
        $this->store->afterUnlock = function () use ($old): void {
            $this->assertSame('paid', $this->deliver($old, 'uuid-old'));
        };

        $json = $this->confirm();

        $this->assertSame(self::PAID, $this->order->statusId);
        $this->assertSame([self::PENDING, self::PAID], array_column($this->order->history, 'status'));
        $this->assertStringStartsWith('Pay before ', $this->order->history[0]['comment']);
        $this->assertSame([true, true], $this->historyUnderLock);
        $this->assertSame('TB', $json['address']);
        $this->assertSame(PaymentRecord::PAID, $this->store->records['TA']->status);
    }

    public function testPaymentDuringTheCreateRequestWritesNoHistoryForThePaidOrderAndShowsNoAddress(): void
    {
        $old = $this->expiredAttempt();
        $transport = $this->transport([$this->answer('TB', time() + 3600)]);
        $transport->during = function () use ($old): void {
            $this->assertSame('paid', $this->deliver($old, 'uuid-old'));
        };

        $json = $this->confirm();

        $this->assertSame(['status' => 'paid', 'window_closed' => false], $json);
        $this->assertSame(self::PAID, $this->order->statusId);
        $this->assertSame([self::PAID], array_column($this->order->history, 'status'));
        $this->assertArrayNotHasKey('TB', $this->store->records);
    }

    public function testConfirmOnAPaidOrderWritesNoHistoryEvenWhenTheOrderStatusIsStillZero(): void
    {
        $this->store->save(new PaymentRecord(55, 'n', 'trc20/usdt', 'TA', 'TPayout', '25.00', '10.000000', '25.000000', PaymentRecord::PAID, time() - 60));
        $this->order->statusId = 0;
        $transport = $this->transport([]);

        $json = $this->confirm();

        $this->assertSame(['status' => 'paid', 'window_closed' => false], $json);
        $this->assertSame([], $this->order->history);
        $this->assertSame([], $transport->requested);
    }

    public function testFirstConfirmSetsTheWaitingStatusWithTheDeadlineUnderTheLock(): void
    {
        $this->order->statusId = 0;
        $this->transport([$this->answer('TA', time() + 3600)]);

        $json = $this->confirm();

        $this->assertSame('TA', $json['address']);
        $this->assertSame('TA', $json['qr_text']);
        $this->assertSame([self::PENDING], array_column($this->order->history, 'status'));
        $this->assertTrue($this->order->history[0]['notify']);
        $this->assertSame([true], $this->historyUnderLock);
    }

    public function testStatusAfterTheDeadlineAddsOneClosedNoteKeepsTheStatusAndHidesTheAddress(): void
    {
        $this->expiredAttempt();

        $first = $this->poll();
        $second = $this->poll();

        foreach ([$first, $second] as $json) {
            $this->assertSame('closed', $json['status']);
            $this->assertSame(55, $json['order_id']);
            $this->assertStringContainsString('order 55', $json['closed_text']);
            $this->assertStringNotContainsString('TA', (string) json_encode($json));
        }
        $this->assertCount(1, $this->order->history);
        $this->assertSame(self::PENDING, $this->order->statusId);
        $this->assertTrue($this->order->history[0]['notify']);
        $this->assertSame([true], $this->historyUnderLock);
    }

    public function testStatusOfAnOrderPaidOnAPreviousAttemptReportsPaidWithoutANote(): void
    {
        $this->store->save(new PaymentRecord(55, 'n1', 'trc20/usdt', 'TA', 'TPayout', '25.00', '10.000000', '25.000000', PaymentRecord::PAID, time() - 60));
        $this->store->save(new PaymentRecord(55, 'n2', 'trc20/usdt', 'TB', 'TPayout', '25.00', '10.000000', '0', PaymentRecord::AWAITING, time() - 30));

        $json = $this->poll();

        $this->assertSame(['status' => 'paid', 'window_closed' => false], $json);
        $this->assertSame([], $this->order->history);
    }

    private function expiredAttempt(): PaymentRecord
    {
        $record = new PaymentRecord(55, bin2hex(random_bytes(16)), 'trc20/usdt', 'TA', 'TPayout', '25.00', '10.000000', '0', PaymentRecord::AWAITING, time() - 60);
        $this->store->save($record);
        return $record;
    }

    private function transport(array $answers): ControllerTransport
    {
        $transport = new ControllerTransport($answers);
        TestableCatalogController::$transport = $transport;
        return $transport;
    }

    private function confirm(): array
    {
        $this->controller()->confirm();
        return json_decode($this->response->output, true);
    }

    private function poll(): array
    {
        $this->controller()->status();
        return json_decode($this->response->output, true);
    }

    private function controller(): TestableCatalogController
    {
        $registry = new Registry();
        $this->response = new Response();
        $registry->set('load', new Loader($registry, [
            'extension/suawave/payment/suawave' => new CheckoutModel($this->order),
            'checkout/order' => $this->order,
        ]));
        $registry->set('config', new Config([
            'payment_suawave_order_currency' => 'USD',
            'payment_suawave_pending_status_id' => (string) self::PENDING,
            'payment_suawave_paid_status_id' => (string) self::PAID,
            'payment_suawave_ticker' => 'trc20/usdt',
            'payment_suawave_payout_address' => 'TPayout',
            'payment_suawave_external_origin' => self::ORIGIN,
            'payment_suawave_base_url' => 'https://api.example',
            'config_timezone' => 'UTC',
            'config_language' => 'en-gb',
        ]));
        $registry->set('language', new Language([
            'text_pay_before' => 'Pay before %s.',
            'text_closed' => 'The payment window for order %s closed on %s.',
        ]));
        $registry->set('session', new Session(['order_id' => 55, 'payment_method' => ['code' => 'suawave.suawave']]));
        $registry->set('response', $this->response);
        $registry->set('log', new Log());
        $registry->set('db', new OrderDb($this->order));
        return new TestableCatalogController($registry);
    }

    private function deliver(PaymentRecord $record, string $uuid): string
    {
        $processor = new CallbackProcessor(
            new SignatureVerifier($this->pair->publicPem),
            self::ORIGIN,
            $this->store,
            new OpenCartOrderGateway($this->order, self::PAID, new OrderDb($this->order), 'oc_', 'trc20/usdt', 'USD', 2)
        );
        $uri = self::PATH . '&' . http_build_query([
            'order' => '55', 'nonce' => $record->nonce, 'uuid' => $uuid,
            'address_in' => $record->addressIn, 'address_out' => 'TPayout', 'coin' => 'trc20_usdt',
            'pending' => '0', 'value_coin' => '25.000000',
        ]);
        return $processor->process(new CallbackRequest('GET', $uri, '', '', $this->pair->sign(self::ORIGIN . $uri)))->reason;
    }

    private function answer(string $address, int $expiresAt): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode([
            'status' => 'success', 'address_in' => $address, 'address_out' => 'TPayout',
            'callback_url' => 'u', 'minimum_transaction_coin' => '10.000000', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
        ]));
    }
}
