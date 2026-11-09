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
use Suawave\OpenCartPaymentStore;
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

        $first = $service->begin(55, '25.00');
        $again = $service->begin(55, '25.00');

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
        $first = $service->begin(55, '25.00');

        $now = strtotime(self::FIRST) + 1;
        $second = $service->begin(55, '25.00');

        $this->assertNotSame($first->nonce, $second->nonce);
        $this->assertSame('TB', $second->addressIn);
        $this->assertSame(strtotime(self::SECOND), $second->expiresAt);
        $this->assertSame(PaymentRecord::SUPERSEDED, $store->records['TA']->status);
        $this->assertCount(2, $transport->requested);
        $this->assertStringContainsString(rawurlencode('order=55&nonce=' . $second->nonce), $transport->requested[1]);
        $this->assertSame($second, $store->findByOrderId(55));
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
        $db = new FakeDb();
        $store = new OpenCartPaymentStore($db, 'oc_');
        $store->save(new PaymentRecord(2, 'n', 'trc20/usdt', 'TA', 'TP', '25.00', '10', '0', PaymentRecord::AWAITING, 1789000000));
        $this->assertStringContainsString('`expires_at` = 1789000000', $db->queries[0]);

        $db->rows['SELECT'] = [
            'order_id' => '2', 'nonce' => 'n', 'ticker' => 'trc20/usdt', 'address_in' => 'TA', 'payout_address' => 'TP',
            'amount' => '25.00', 'minimum' => '10', 'received' => '0', 'status' => 'awaiting', 'expires_at' => '1789000000',
        ];
        $this->assertSame(1789000000, $store->findByOrderId(2)->expiresAt);
        $store->findOverdue(1789000001);
        $this->assertStringContainsString('`expires_at` <= 1789000001', end($db->queries));
    }

    public function testSqlStoreWritesTheExpiryMarkerAndSweepsOnlyUnnotedAttempts(): void
    {
        $db = new FakeDb();
        $store = new OpenCartPaymentStore($db, 'oc_');
        $record = new PaymentRecord(2, 'n', 'trc20/usdt', 'TA', 'TP', '25.00', '10', '0', PaymentRecord::AWAITING, 1789000000);
        $store->save($record->withExpiryNoted(1789000100));
        $this->assertStringContainsString('`expiry_noted_at` = 1789000100', $db->queries[0]);
        $this->assertStringContainsString('`expiry_noted_at` = VALUES(`expiry_noted_at`)', $db->queries[0]);

        $store->findOverdue(1789000001);
        $this->assertStringContainsString('`expiry_noted_at` IS NULL', end($db->queries));
        $this->assertStringNotContainsString('"detected"', end($db->queries));
    }
}
