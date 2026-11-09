<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use Suawave\Payment\Core\BelowMinimumException;
use Suawave\Payment\Core\Checkout;
use Suawave\Payment\Core\GatewayClient;
use Suawave\Payment\Core\GatewayException;
use Suawave\Payment\Core\PaymentWindow;
use PHPUnit\Framework\TestCase;

final class PaymentWindowTest extends TestCase
{
    private const FIRST = '2026-11-09T12:00:00Z';
    private const SECOND = '2026-11-10T12:00:00Z';

    private function convert(): array
    {
        return ['status' => 'success', 'value_coin' => '25.000000'];
    }

    private function created(string $address, string $expiresAt, string $minimum = '10.000000'): array
    {
        return ['status' => 'success', 'address_in' => $address, 'minimum_transaction_coin' => $minimum, 'expires_at' => $expiresAt];
    }

    private function open(GatewayClient $client): array
    {
        return Checkout::open($client, 'trc20/usdt', 'TOut', '25.00', 'USD', fn (string $nonce): string => 'https://shop.example/cb?nonce=' . $nonce);
    }

    public function testClientParsesTheDeadline(): void
    {
        $transport = new ScriptedGateway([$this->created('TA', self::FIRST)]);
        $created = (new GatewayClient('https://gw.example', $transport))->createAddress('trc20/usdt', 'TOut', 'https://shop.example/cb');
        self::assertSame(strtotime(self::FIRST), $created['expires_at']);
    }

    public function testClientRefusesAnUnreadableDeadline(): void
    {
        $transport = new ScriptedGateway([$this->created('TA', 'later')]);
        $this->expectException(GatewayException::class);
        (new GatewayClient('https://gw.example', $transport))->createAddress('trc20/usdt', 'TOut', 'https://shop.example/cb');
    }

    public function testCheckoutKeepsTheDeadlineWithThePayment(): void
    {
        $transport = new ScriptedGateway([$this->convert(), $this->created('TA', self::FIRST)]);
        $opened = $this->open(new GatewayClient('https://gw.example', $transport));
        self::assertSame((string) strtotime(self::FIRST), $opened['expires_at']);
        self::assertSame('TA', $opened['address_in']);
        self::assertSame('25.000000', $opened['expected']);
    }

    public function testCheckoutRefusesATotalBelowTheMinimum(): void
    {
        $transport = new ScriptedGateway([$this->convert(), $this->created('TA', self::FIRST, '30.000000')]);
        $this->expectException(BelowMinimumException::class);
        $this->open(new GatewayClient('https://gw.example', $transport));
    }

    public function testWindowClosesAtTheDeadlineAndTheNoteIsDueOnce(): void
    {
        $deadline = (int) strtotime(self::FIRST);
        self::assertFalse(PaymentWindow::isClosed($deadline, $deadline - 1));
        self::assertTrue(PaymentWindow::isClosed($deadline, $deadline));
        self::assertFalse(PaymentWindow::isClosed(null, $deadline + 99));
        self::assertTrue(PaymentWindow::noteDue($deadline, false, false, $deadline));
        self::assertFalse(PaymentWindow::noteDue($deadline, false, true, $deadline + 5));
        self::assertFalse(PaymentWindow::noteDue($deadline, true, false, $deadline + 5));
        self::assertFalse(PaymentWindow::noteDue(null, false, false, $deadline + 5));
        self::assertSame($deadline, PaymentWindow::fromStored((string) $deadline));
        self::assertNull(PaymentWindow::fromStored(''));
    }
}
