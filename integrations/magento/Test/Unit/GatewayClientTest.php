<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use Suawave\Payment\Core\Decimal;
use Suawave\Payment\Core\GatewayClient;
use Suawave\Payment\Core\GatewayException;
use Suawave\Payment\Core\HttpResponse;
use Suawave\Payment\Core\HttpTransport;
use PHPUnit\Framework\TestCase;

final class RecordingTransport implements HttpTransport
{
    public array $urls = [];

    public function __construct(private HttpResponse $response)
    {
    }

    public function get(string $url): HttpResponse
    {
        $this->urls[] = $url;
        return $this->response;
    }
}

final class GatewayClientTest extends TestCase
{
    private function client(int $status, array $body, ?RecordingTransport &$transport = null): GatewayClient
    {
        $transport = new RecordingTransport(new HttpResponse($status, (string) json_encode($body)));
        return new GatewayClient('https://gw.example/', $transport);
    }

    public function testCreateAddressSendsEncodedCallbackAndReturnsStrings(): void
    {
        $client = $this->client(200, [
            'status' => 'success',
            'address_in' => 'TDeposit',
            'minimum_transaction_coin' => '10.000000',
        ], $transport);
        $created = $client->createAddress('trc20/usdt', 'TPayout', 'https://shop.example/suawave/callback/index?order=1&nonce=ab');
        self::assertSame(['address_in' => 'TDeposit', 'minimum_transaction_coin' => '10.000000'], $created);
        self::assertSame(
            'https://gw.example/trc20/usdt/create/?address=TPayout&callback=https%3A%2F%2Fshop.example%2Fsuawave%2Fcallback%2Findex%3Forder%3D1%26nonce%3Dab&json=1',
            $transport->urls[0]
        );
    }

    public function testConvertKeepsTheAmountAsAString(): void
    {
        $client = $this->client(200, ['status' => 'success', 'value_coin' => '108.695652', 'exchange_rate' => '1.086956521739'], $transport);
        self::assertSame('108.695652', $client->convertToTicker('trc20/usdt', '100.00', 'EUR'));
        self::assertSame('https://gw.example/trc20/usdt/convert/?value=100.00&from=eur', $transport->urls[0]);
    }

    public function testConvertRejectsNonDecimalAndZeroResults(): void
    {
        foreach (['1e3', '0', '0.000000', '-1', 12.5] as $value) {
            $client = $this->client(200, ['status' => 'success', 'value_coin' => $value]);
            try {
                $client->convertToTicker('trc20/usdt', '10.00', 'usd');
                self::fail('accepted ' . var_export($value, true));
            } catch (GatewayException $expected) {
                self::assertStringContainsString('unusable', $expected->getMessage());
            }
        }
    }

    public function testErrorEnvelopeBecomesAGatewayException(): void
    {
        $client = $this->client(400, ['status' => 'error', 'error' => 'Invalid ticker']);
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('gateway answered 400: Invalid ticker');
        $client->createAddress('trc20/usdt', 'TPayout', 'https://x.example/');
    }

    public function testTickerWithoutSlashIsRefused(): void
    {
        $client = $this->client(200, ['status' => 'success']);
        $this->expectException(GatewayException::class);
        $client->convertToTicker('usdt', '1.00', 'usd');
    }

    public function testQrCodeMustBeAPngDataUri(): void
    {
        $good = $this->client(200, ['status' => 'success', 'qr_code' => 'data:image/png;base64,iVBORw0KGgo=', 'payment_uri' => 'T']);
        self::assertSame('data:image/png;base64,iVBORw0KGgo=', $good->qrCode('trc20/usdt', 'TDeposit', '10.5'));

        $bad = $this->client(200, ['status' => 'success', 'qr_code' => 'javascript:alert(1)', 'payment_uri' => 'T']);
        $this->expectException(GatewayException::class);
        $bad->qrCode('trc20/usdt', 'TDeposit', '10.5');
    }

    public function testPublicKeyMustLookLikeAKey(): void
    {
        $good = $this->client(200, ['status' => 'success', 'pubkey' => "-----BEGIN PUBLIC KEY-----\nAA\n-----END PUBLIC KEY-----\n"]);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $good->publicKey());

        $bad = $this->client(200, ['status' => 'success', 'pubkey' => 'nope']);
        $this->expectException(GatewayException::class);
        $bad->publicKey();
    }

    public function testDecimalHelpers(): void
    {
        self::assertSame('100.00', Decimal::twoPlaces('99.995'));
        self::assertSame('10.00', Decimal::twoPlaces('10'));
        self::assertSame('0.30', Decimal::twoPlaces('0.299'));
        self::assertSame('12.34000000', Decimal::fromStorage(12.34));
        self::assertSame('7.5000', Decimal::fromStorage('7.5000'));
        self::assertTrue(Decimal::atLeast('0.30', '0.3'));
        self::assertFalse(Decimal::atLeast('0.29999999', '0.3'));
        $this->expectException(\InvalidArgumentException::class);
        Decimal::fromStorage('1e5');
    }
}
