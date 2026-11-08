<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\CallbackHandler;
use Suawave\CallbackProcessor;
use Suawave\Tests\Support\FakeDb;
use Suawave\Tests\Support\MemoryOrders;
use Suawave\WpLedger;
use Suawave\WpOrderLock;
use PHPUnit\Framework\TestCase;

final class CallbackFlowTest extends TestCase
{
    private const ORIGIN = 'https://shop.example';
    private const NONCE = '7f3a9c0000000000000000000000aaaa';
    private const ADDRESS = 'TDepositAddressExample0000000000';
    private const COIN = 'trc20_usdt';

    private $privateKey;
    private string $publicPem;
    private FakeDb $db;
    private MemoryOrders $orders;
    private CallbackHandler $handler;

    protected function setUp(): void
    {
        $this->privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->publicPem = openssl_pkey_get_details($this->privateKey)['key'];
        $this->db = new FakeDb();
        $this->orders = new MemoryOrders($this->db);
        $this->orders->add('1001', self::NONCE, self::ADDRESS, self::COIN, '100.00');
        $this->handler = new CallbackHandler($this->publicPem, self::ORIGIN, new CallbackProcessor($this->orders, new WpLedger($this->db), new WpOrderLock($this->db)));
    }

    private function confirmedFields(string $uuid, string $txid, string $value): array
    {
        return [
            'uuid' => $uuid, 'address_in' => self::ADDRESS, 'address_out' => 'TPayout', 'txid_in' => $txid, 'coin' => self::COIN,
            'pending' => '0', 'txid_out' => 'out-' . $txid, 'confirmations' => '21', 'value_coin' => $value,
            'value_forwarded_coin' => $value, 'fee_coin' => '0.000000',
        ];
    }

    private function sign(string $bytes): string
    {
        openssl_sign($bytes, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    private function deliverGet(array $fields, ?string $signature = null, string $order = '1001', string $nonce = self::NONCE): \Suawave\CallbackResult
    {
        $uri = '/?suawave_callback=1&src=woo&order=' . $order . '&nonce=' . $nonce . '&' . http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $signature ??= $this->sign(self::ORIGIN . $uri);
        return $this->handler->handle('GET', $uri, '', '', $signature);
    }

    private function deliverJson(array $fields): \Suawave\CallbackResult
    {
        $body = (string) json_encode($fields);
        return $this->handler->handle('POST', '/?suawave_callback=1&src=woo&order=1001&nonce=' . self::NONCE, $body, 'application/json', $this->sign($body));
    }

    public function testConfirmedFullAmountMarksOrderPaidOnce(): void
    {
        $result = $this->deliverGet($this->confirmedFields('11111111-aaaa-bbbb-cccc-000000000001', 'tx1', '100.000000'));
        $this->assertSame(200, $result->status);
        $this->assertSame('*ok*', $result->body);
        $this->assertSame(['1001'], $this->orders->paidCalls);
        $this->assertSame('100.000000', $this->orders->orders['1001']['received']);
    }

    public function testRepeatedDeliveryIsIdempotent(): void
    {
        $fields = $this->confirmedFields('11111111-aaaa-bbbb-cccc-000000000001', 'tx1', '100.000000');
        $first = $this->deliverGet($fields);
        $writesAfterFirst = $this->orders->writes;
        $second = $this->deliverGet($fields);
        $third = $this->deliverJson($fields);
        $this->assertSame(200, $first->status);
        $this->assertSame(200, $second->status);
        $this->assertSame(200, $third->status);
        $this->assertSame($writesAfterFirst, $this->orders->writes);
        $this->assertSame(['1001'], $this->orders->paidCalls);
        $this->assertSame('100.000000', $this->orders->orders['1001']['received']);
    }

    public function testBadSignatureChangesNothing(): void
    {
        $before = $this->orders->orders;
        $fields = $this->confirmedFields('22222222-aaaa-bbbb-cccc-000000000002', 'tx2', '100.000000');
        $result = $this->deliverGet($fields, base64_encode(str_repeat('x', 256)));
        $this->assertSame(401, $result->status);
        $this->assertSame($before, $this->orders->orders);
        $this->assertSame([], $this->orders->paidCalls);
        $this->assertSame(0, $this->orders->writes);
        $this->assertSame([], $this->db->log);
        $this->assertSame([], $this->db->rows);
    }

    public function testMissingSignatureChangesNothing(): void
    {
        $fields = $this->confirmedFields('22222222-aaaa-bbbb-cccc-000000000002', 'tx2', '100.000000');
        $result = $this->deliverGet($fields, '');
        $this->assertSame(401, $result->status);
        $this->assertSame([], $this->orders->paidCalls);
        $this->assertSame(0, $this->orders->writes);
    }

    public function testSignatureOverDifferentAmountChangesNothing(): void
    {
        $cheap = $this->confirmedFields('33333333-aaaa-bbbb-cccc-000000000003', 'tx3', '1.000000');
        $uri = '/?suawave_callback=1&src=woo&order=1001&nonce=' . self::NONCE . '&' . http_build_query($cheap, '', '&', PHP_QUERY_RFC3986);
        $signatureOfCheap = $this->sign(self::ORIGIN . $uri);
        $forged = $this->confirmedFields('33333333-aaaa-bbbb-cccc-000000000003', 'tx3', '100.000000');
        $result = $this->deliverGet($forged, $signatureOfCheap);
        $this->assertSame(401, $result->status);
        $this->assertSame([], $this->orders->paidCalls);
        $this->assertSame(0, $this->orders->writes);
    }

    public function testPendingCallbackNeverPaysTheOrder(): void
    {
        $fields = ['uuid' => '44444444-aaaa-bbbb-cccc-000000000004', 'address_in' => self::ADDRESS, 'address_out' => 'TPayout', 'txid_in' => 'tx4', 'coin' => self::COIN, 'pending' => '1'];
        $result = $this->deliverGet($fields);
        $this->assertSame(200, $result->status);
        $this->assertSame([], $this->orders->paidCalls);
        $this->assertSame('0', $this->orders->orders['1001']['received']);
        $this->assertSame(['tx4'], $this->orders->orders['1001']['detected']);
    }

    public function testPartialThenSecondDepositWithNewUuidPays(): void
    {
        $this->deliverGet($this->confirmedFields('55555555-aaaa-bbbb-cccc-000000000005', 'tx5', '60.000000'));
        $this->assertSame([], $this->orders->paidCalls);
        $this->assertSame('60.000000', $this->orders->orders['1001']['received']);
        $this->deliverGet($this->confirmedFields('66666666-aaaa-bbbb-cccc-000000000006', 'tx6', '40.000000'));
        $this->assertSame(['1001'], $this->orders->paidCalls);
        $this->assertSame('100.000000', $this->orders->orders['1001']['received']);
    }

    public function testUnknownNonceAndUnknownOrderAreNotFound(): void
    {
        $fields = $this->confirmedFields('77777777-aaaa-bbbb-cccc-000000000007', 'tx7', '100.000000');
        $this->assertSame(404, $this->deliverGet($fields, null, '1001', 'ffffffffffffffffffffffffffffffff')->status);
        $this->assertSame(404, $this->deliverGet($fields, null, '9999')->status);
        $this->assertSame([], $this->orders->paidCalls);
    }

    public function testCallbackForAnotherAddressOrCoinIsRefused(): void
    {
        $other = $this->confirmedFields('88888888-aaaa-bbbb-cccc-000000000008', 'tx8', '100.000000');
        $other['address_in'] = 'TSomeoneElse';
        $this->assertSame(400, $this->deliverGet($other)->status);
        $wrongCoin = $this->confirmedFields('88888888-aaaa-bbbb-cccc-000000000009', 'tx9', '100.000000');
        $wrongCoin['coin'] = 'bep20_usdt';
        $this->assertSame(400, $this->deliverGet($wrongCoin)->status);
        $this->assertSame([], $this->orders->paidCalls);
    }

    public function testInvalidAmountIsRefused(): void
    {
        foreach (['1e2', '-5', '100,00', '0', '0.000000', ''] as $value) {
            $fields = $this->confirmedFields('99999999-aaaa-bbbb-cccc-000000000001', 'txa', $value);
            $this->assertSame(400, $this->deliverGet($fields)->status, 'value ' . $value);
        }
        $this->assertSame([], $this->orders->paidCalls);
    }

    public function testFailedStorageAnswers500AndLetsRetryApply(): void
    {
        $fields = $this->confirmedFields('aaaaaaaa-aaaa-bbbb-cccc-000000000001', 'txb', '100.000000');
        $this->orders->failNextDeposit = true;
        $this->assertSame(500, $this->deliverGet($fields)->status);
        $this->assertSame([], $this->orders->paidCalls);
        $this->assertSame(200, $this->deliverGet($fields)->status);
        $this->assertSame(['1001'], $this->orders->paidCalls);
    }

    public function testSameTxidUnderANewUuidIsAnotherDepositAndIsCredited(): void
    {
        $this->deliverGet($this->confirmedFields('bbbbbbbb-aaaa-bbbb-cccc-000000000001', 'txc', '60.000000'));
        $this->deliverGet($this->confirmedFields('bbbbbbbb-aaaa-bbbb-cccc-000000000002', 'txc', '60.000000'));
        $this->assertSame('120.000000', $this->orders->orders['1001']['received']);
        $this->assertSame(['1001'], $this->orders->paidCalls);
    }
}
