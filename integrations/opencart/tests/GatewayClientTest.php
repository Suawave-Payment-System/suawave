<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\CheckoutService;
use Suawave\Decimal;
use Suawave\GatewayClient;
use Suawave\GatewayException;
use Suawave\HttpResponse;
use Suawave\HttpTransport;
use Suawave\PaymentRecord;
use PHPUnit\Framework\TestCase;

final class GatewayClientTest extends TestCase
{
    public function testCreateAddressBuildsTheDocumentedRequest(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, json_encode([
            'status' => 'success', 'address_in' => 'TDeposit', 'address_out' => 'TPayout',
            'callback_url' => 'https://shop.example/hook', 'minimum_transaction_coin' => '10.000000',
        ]))]);

        $created = (new GatewayClient('https://api.example/', $transport))
            ->createAddress('trc20/usdt', 'TPayout', 'https://shop.example/hook?order=1&nonce=ab');

        $this->assertSame('TDeposit', $created->addressIn);
        $this->assertSame('10.000000', $created->minimum);
        $this->assertSame(
            'https://api.example/trc20/usdt/create/?callback=https%3A%2F%2Fshop.example%2Fhook%3Forder%3D1%26nonce%3Dab&address=TPayout&pending=1',
            $transport->requested[0]
        );
    }

    public function testErrorEnvelopeBecomesAnException(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(400, json_encode(['status' => 'error', 'error' => 'Invalid ticker']))]);

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Invalid ticker');

        (new GatewayClient('https://api.example', $transport))->createAddress('x/y', 'T', 'https://shop.example/h');
    }

    public function testNonJsonAnswerIsRefused(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(502, '<html>bad gateway</html>')]);

        $this->expectException(GatewayException::class);

        (new GatewayClient('https://api.example', $transport))->publicKey();
    }

    public function testMinimumMustBeADecimalString(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, '{"status":"success","address_in":"A","address_out":"B","minimum_transaction_coin":1e1}')]);

        $this->expectException(GatewayException::class);

        (new GatewayClient('https://api.example', $transport))->createAddress('trc20/usdt', 'B', 'https://shop.example/h');
    }

    public function testCheckoutStoresTheRecordAndReusesItForTheSameAmount(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, json_encode([
            'status' => 'success', 'address_in' => 'TDeposit', 'address_out' => 'TPayout',
            'callback_url' => 'u', 'minimum_transaction_coin' => '10.000000',
        ]))]);
        $store = new InMemoryPaymentStore();
        $service = new CheckoutService(
            new GatewayClient('https://api.example', $transport),
            $store,
            'trc20/usdt',
            'TPayout',
            'https://shop.example/index.php?route=extension/suawave/payment/suawave.callback'
        );

        $first = $service->begin(7, '25.00');
        $second = $service->begin(7, '25.00');

        $this->assertSame($first, $second);
        $this->assertCount(1, $transport->requested);
        $this->assertSame(PaymentRecord::AWAITING, $first->status);
        $this->assertSame('0', $first->received);
        $this->assertStringContainsString(rawurlencode('order=7&nonce=' . $first->nonce), $transport->requested[0]);
    }

    public function testCheckoutSupersedesThePreviousRecordWhenTheAmountChanges(): void
    {
        $answer = static fn (string $address): HttpResponse => new HttpResponse(200, json_encode([
            'status' => 'success', 'address_in' => $address, 'address_out' => 'TPayout',
            'callback_url' => 'u', 'minimum_transaction_coin' => '10.000000',
        ]));
        $transport = new ScriptedTransport([$answer('TFirst'), $answer('TSecond')]);
        $store = new InMemoryPaymentStore();
        $service = new CheckoutService(new GatewayClient('https://api.example', $transport), $store, 'trc20/usdt', 'TPayout', 'https://shop.example/cb');

        $first = $service->begin(7, '25.00');
        $second = $service->begin(7, '40.00');

        $this->assertNotSame($first->addressIn, $second->addressIn);
        $this->assertSame(PaymentRecord::SUPERSEDED, $store->findByAddressIn('TFirst')->status);
        $this->assertSame(PaymentRecord::AWAITING, $store->findByAddressIn('TSecond')->status);
        $this->assertSame($second->addressIn, $store->findByOrderId(7)->addressIn);
    }

    public function testCheckoutRefusesAnAmountBelowTheMinimum(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, json_encode([
            'status' => 'success', 'address_in' => 'TDeposit', 'address_out' => 'TPayout',
            'callback_url' => 'u', 'minimum_transaction_coin' => '10.000000',
        ]))]);
        $service = new CheckoutService(new GatewayClient('https://api.example', $transport), new InMemoryPaymentStore(), 'trc20/usdt', 'TPayout', 'https://shop.example/cb');

        $this->expectException(GatewayException::class);

        $service->begin(7, '9.99');
    }

    public function testOrderTotalConversionUsesStringArithmetic(): void
    {
        $this->assertSame('100.00', Decimal::fromOrderTotal('100.0000', '1.00000000', 2));
        $this->assertSame('0.30', Decimal::fromOrderTotal('0.1000', '3.00000000', 2));
        $this->assertSame('10.01', Decimal::fromOrderTotal('9.9999', '1.00100000', 2));
        $this->assertSame('1.01', Decimal::fromOrderTotal('1.005', '1', 2));
        $this->assertSame('92.00', Decimal::fromOrderTotal('100.00', '0.92', 2));
    }

    public function testDecimalRejectsNonPlainStrings(): void
    {
        foreach (['1e5', '-1', '+1', ' 1', '1.', '.5', '', '1,5'] as $bad) {
            $this->assertFalse(Decimal::isValid($bad), $bad);
        }
        $this->assertTrue(Decimal::isValid('0.000000000000000001'));
        $this->assertSame(0, Decimal::compare('1.10', '1.1'));
        $this->assertSame(1, Decimal::compare('0.000000000000000002', '0.000000000000000001'));
    }
}

final class ScriptedTransport implements HttpTransport
{
    public array $requested = [];

    public function __construct(private array $responses)
    {
    }

    public function get(string $url): HttpResponse
    {
        $this->requested[] = $url;
        return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
    }
}
