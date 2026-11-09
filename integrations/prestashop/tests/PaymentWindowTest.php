<?php

declare(strict_types=1);

namespace Suawave\Tests;

use DateTimeZone;
use Suawave\CheckoutService;
use Suawave\ExpiryNotifier;
use Suawave\GatewayClient;
use Suawave\GatewayException;
use Suawave\HttpResponse;
use Suawave\PaymentRecord;
use Suawave\PaymentWindow;
use Suawave\SqlPaymentStore;
use PHPUnit\Framework\TestCase;

final class PaymentWindowTest extends TestCase
{
    private const FIRST = '2026-11-09T12:00:00Z';
    private const SECOND = '2026-11-10T12:00:00Z';

    private function answer(string $address, string $expiresAt): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'status' => 'success', 'address_in' => $address, 'address_out' => 'TPayout',
            'callback_url' => 'u', 'minimum_transaction_coin' => '10.000000', 'expires_at' => $expiresAt,
        ]));
    }

    private function service(ScriptedTransport $transport, InMemoryPaymentStore $store, int &$now): CheckoutService
    {
        return new CheckoutService(
            new GatewayClient('https://api.example', $transport),
            $store,
            'trc20/usdt',
            'TPayout',
            'https://shop.example/cb',
            function () use (&$now): int {
                return $now;
            }
        );
    }

    public function testCreateAddressParsesTheDeadline(): void
    {
        $transport = new ScriptedTransport([$this->answer('TA', self::FIRST)]);
        $created = (new GatewayClient('https://api.example', $transport))->createAddress('trc20/usdt', 'TPayout', 'https://shop.example/h');
        $this->assertSame(strtotime(self::FIRST), $created->expiresAt);
    }

    public function testUnreadableDeadlineIsRefused(): void
    {
        $transport = new ScriptedTransport([$this->answer('TA', 'soon')]);
        $this->expectException(GatewayException::class);
        (new GatewayClient('https://api.example', $transport))->createAddress('trc20/usdt', 'TPayout', 'https://shop.example/h');
    }

    public function testCheckoutStoresTheDeadlineAndReusesTheRecordInsideTheWindow(): void
    {
        $now = strtotime(self::FIRST) - 3600;
        $transport = new ScriptedTransport([$this->answer('TA', self::FIRST)]);
        $store = new InMemoryPaymentStore();
        $service = $this->service($transport, $store, $now);

        $first = $service->begin(7, '25.00');
        $again = $service->begin(7, '25.00');

        $this->assertSame(strtotime(self::FIRST), $first->expiresAt);
        $this->assertSame($first, $again);
        $this->assertCount(1, $transport->requested);
    }

    public function testRetryAfterTheDeadlineRequestsAFreshAddressWithANewNonce(): void
    {
        $now = strtotime(self::FIRST) - 3600;
        $transport = new ScriptedTransport([$this->answer('TA', self::FIRST), $this->answer('TB', self::SECOND)]);
        $store = new InMemoryPaymentStore();
        $service = $this->service($transport, $store, $now);
        $first = $service->attachOrder($service->begin(7, '25.00'), 55);

        $now = strtotime(self::FIRST) + 1;
        $second = $service->begin(7, '25.00');

        $this->assertNotSame($first->nonce, $second->nonce);
        $this->assertSame('TB', $second->addressIn);
        $this->assertSame(strtotime(self::SECOND), $second->expiresAt);
        $this->assertSame(55, $second->orderId);
        $this->assertCount(2, $transport->requested);
        $this->assertStringContainsString(rawurlencode('nonce=' . $second->nonce), $transport->requested[1]);
        $this->assertSame($second, $store->findByCartId(7));
    }

    public function testClosedWindowIsReportedFromTheDeadline(): void
    {
        $deadline = strtotime(self::FIRST);
        $this->assertFalse(PaymentWindow::isClosed($deadline, $deadline - 1));
        $this->assertTrue(PaymentWindow::isClosed($deadline, $deadline));
        $this->assertFalse(PaymentWindow::isClosed(null, $deadline + 99));
        $this->assertSame('2026-11-09 15:00 (Europe/Istanbul)', PaymentWindow::format($deadline, new DateTimeZone('Europe/Istanbul')));
    }

    public function testSqlStoreWritesAndReadsTheDeadline(): void
    {
        $db = new FakeSqlConnection();
        $store = new SqlPaymentStore($db, 'ps_');
        $record = new PaymentRecord(1, 2, 'n', 'trc20/usdt', 'TA', 'TP', '25.00', '10', '0', PaymentRecord::AWAITING, 1789000000);
        $store->save($record);
        $this->assertStringContainsString('`expires_at` = 1789000000', $db->statements[0]);

        $db->row = ['id_cart' => '1', 'id_order' => '2', 'nonce' => 'n', 'ticker' => 'trc20/usdt', 'address_in' => 'TA', 'payout_address' => 'TP', 'amount' => '25.00', 'minimum' => '10', 'received' => '0', 'status' => 'awaiting', 'expires_at' => '1789000000'];
        $this->assertSame(1789000000, $store->findByOrderId(2)->expiresAt);
        $store->findOverdue(1789000001);
        $this->assertStringContainsString('`expires_at` <= 1789000001', end($db->statements));
    }

    public function testExpiryAddsExactlyOneNoteAndNeverForPaidOrders(): void
    {
        $now = strtotime(self::FIRST) - 60;
        $store = new InMemoryPaymentStore();
        $service = $this->service(new ScriptedTransport([$this->answer('TA', self::FIRST)]), $store, $now);
        $service->attachOrder($service->begin(7, '25.00'), 55);
        $orders = new RecordingOrderGateway();
        $notifier = new ExpiryNotifier($store, $orders, 'window closed');

        $this->assertSame(0, $notifier->sweep($now));
        $this->assertSame(1, $notifier->sweep(strtotime(self::FIRST)));
        $this->assertSame(0, $notifier->sweep(strtotime(self::FIRST) + 600));
        $this->assertSame([[55, 'window closed']], $orders->notes);
        $this->assertSame(PaymentRecord::EXPIRED, $store->findByOrderId(55)->status);

        $paid = new InMemoryPaymentStore();
        $paid->records['TP'] = new PaymentRecord(8, 56, 'n', 'trc20/usdt', 'TP', 'TO', '25.00', '10', '25.00', PaymentRecord::PAID, 1);
        $quiet = new RecordingOrderGateway();
        $this->assertSame(0, (new ExpiryNotifier($paid, $quiet, 'x'))->sweep(strtotime(self::FIRST)));
        $this->assertSame([], $quiet->notes);
    }
}
