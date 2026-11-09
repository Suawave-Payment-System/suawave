<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\Checkout;
use Suawave\GatewayClient;
use Suawave\GatewayException;
use Suawave\InstructionsView;
use PHPUnit\Framework\TestCase;

final class PaymentWindowTest extends TestCase
{
    private const DEADLINE = '2026-11-09T12:00:00Z';

    private function client(array &$urls, array $answers): GatewayClient
    {
        return new GatewayClient('https://gw.example', function (string $url) use (&$urls, &$answers): array {
            $urls[] = $url;
            return [200, (string) json_encode(array_shift($answers))];
        });
    }

    private function created(string $address, string $expiresAt): array
    {
        return ['status' => 'success', 'address_in' => $address, 'minimum_transaction_coin' => '10.000000', 'expires_at' => $expiresAt];
    }

    public function testClientParsesTheDeadlineIntoAUnixTime(): void
    {
        $urls = [];
        $created = $this->client($urls, [$this->created('TA', self::DEADLINE)])->createAddress('trc20/usdt', 'TOut', 'https://shop.example/');
        $this->assertSame(strtotime(self::DEADLINE), $created['expires_at']);
    }

    public function testClientRefusesAnUnreadableDeadline(): void
    {
        $urls = [];
        $this->expectException(GatewayException::class);
        $this->client($urls, [$this->created('TA', 'tomorrow')])->createAddress('trc20/usdt', 'TOut', 'https://shop.example/');
    }

    public function testCheckoutKeepsTheDeadlineWithTheOrderRecord(): void
    {
        $urls = [];
        $meta = Checkout::open($this->client($urls, [$this->created('TA', self::DEADLINE)]), 'trc20/usdt', 'TOut', '25.00', fn (string $nonce): string => 'https://shop.example/?nonce=' . $nonce);
        $this->assertSame((string) strtotime(self::DEADLINE), $meta['expires_at']);
        $this->assertSame('TA', $meta['address']);
    }

    public function testBelowMinimumMessageShowsTheMinimumRoundedUp(): void
    {
        $urls = [];
        $answer = ['status' => 'success', 'address_in' => 'TA', 'minimum_transaction_coin' => '10.000000000000000001', 'expires_at' => self::DEADLINE];
        try {
            Checkout::open($this->client($urls, [$answer]), 'bep20/usdt', 'TOut', '9.500000000000000000', fn (string $nonce): string => 'https://shop.example/?nonce=' . $nonce);
            $this->fail('a below-minimum amount was accepted');
        } catch (GatewayException $failure) {
            $this->assertSame('The amount 9.50 is below the minimum deposit of 10.000001.', $failure->getMessage());
        }
    }

    public function testOpenWindowShowsTheDeadlineInTheShopTimezone(): void
    {
        $deadline = (int) strtotime(self::DEADLINE);
        $meta = ['address' => 'TAddress', 'amount' => '25.00', 'ticker' => 'trc20/usdt', 'minimum' => '10', 'received' => '0', 'expires_at' => (string) $deadline];
        $html = InstructionsView::html($meta, false, $deadline - 60, new \DateTimeZone('Europe/Istanbul'), '1001', fn (): string => 'data:image/png;base64,AAAA');
        $this->assertStringContainsString('2026-11-09 15:00 (Europe/Istanbul)', $html);
        $this->assertStringContainsString('TAddress', $html);
        $this->assertStringContainsString('data:image/png', $html);
    }

    public function testClosedWindowHidesAddressAndQrAndNamesTheOrder(): void
    {
        $deadline = (int) strtotime(self::DEADLINE);
        $meta = ['address' => 'TAddress', 'amount' => '25.00', 'ticker' => 'trc20/usdt', 'minimum' => '10', 'received' => '0', 'expires_at' => (string) $deadline];
        $qrCalls = 0;
        $html = InstructionsView::html($meta, false, $deadline, new \DateTimeZone('UTC'), '1001', function () use (&$qrCalls): string {
            $qrCalls++;
            return 'data:image/png;base64,AAAA';
        });
        $this->assertStringNotContainsString('TAddress', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('payment window closed', $html);
        $this->assertStringContainsString('order 1001', $html);
        $this->assertSame(0, $qrCalls);
    }

    public function testEmailRenderingOmitsThePageRefreshSentence(): void
    {
        $meta = ['address' => 'TAddress', 'amount' => '25.00', 'ticker' => 'trc20/usdt', 'minimum' => '10', 'received' => '0'];
        $zone = new \DateTimeZone('UTC');
        $page = InstructionsView::html($meta, false, 1, $zone, '1001', null);
        $email = InstructionsView::html($meta, false, 1, $zone, '1001', null, true);
        $this->assertStringContainsString('does not update by itself', $page);
        $this->assertStringNotContainsString('does not update by itself', $email);
        $this->assertStringContainsString('TAddress', $email);
    }
}
