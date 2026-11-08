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
            'https://shop.example/index.php?fc=module&module=suawave&controller=callback'
        );

        $first = $service->begin(7, '25.00');
        $second = $service->begin(7, '25.00');
        $this->assertSame(0, $first->orderId);
        $attached = $service->attachOrder($first, 55);
        $this->assertSame(55, $store->findByCartId(7)->orderId);
        $this->assertSame(55, $attached->orderId);

        $this->assertSame($first, $second);
        $this->assertCount(1, $transport->requested);
        $this->assertSame(PaymentRecord::AWAITING, $first->status);
        $this->assertSame('0', $first->received);
        $this->assertStringContainsString(rawurlencode('cart=7&nonce=' . $first->nonce), $transport->requested[0]);
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

    public function testQrCodeRequestShapeAndPngDataUri(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgo=';
        $transport = new ScriptedTransport([new HttpResponse(200, json_encode(['status' => 'success', 'qr_code' => $png, 'payment_uri' => 'TDeposit']))]);

        $image = (new GatewayClient('https://api.example/', $transport))->qrCode('trc20/usdt', 'TDeposit', '25.00');

        $this->assertSame($png, $image);
        $this->assertSame('https://api.example/trc20/usdt/qrcode/?address=TDeposit&value=25.00&size=256', $transport->requested[0]);
    }

    public function testQrCodeRefusesAnythingButAPngDataUri(): void
    {
        foreach (['http://evil.example/x.png', 'data:text/html;base64,PGI+', 'data:image/png;base64,<script>'] as $bad) {
            $transport = new ScriptedTransport([new HttpResponse(200, json_encode(['status' => 'success', 'qr_code' => $bad]))]);
            try {
                (new GatewayClient('https://api.example', $transport))->qrCode('trc20/usdt', 'TDeposit', '25.00');
                $this->fail('accepted ' . $bad);
            } catch (GatewayException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testQrCodeRefusesANonDecimalAmount(): void
    {
        $this->expectException(GatewayException::class);

        (new GatewayClient('https://api.example', new ScriptedTransport([new HttpResponse(200, '{}')])))->qrCode('trc20/usdt', 'TDeposit', '1e2');
    }

    public function testPublicKeyIsReadFromThePubkeyEndpoint(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, json_encode(['status' => 'success', 'pubkey' => 'PEM']))]);

        $this->assertSame('PEM', (new GatewayClient('https://api.example', $transport))->publicKey());
        $this->assertSame('https://api.example/pubkey/', $transport->requested[0]);
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
