<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\GatewayClient;
use Suawave\GatewayException;
use Suawave\Money;
use PHPUnit\Framework\TestCase;

final class MoneyAndClientTest extends TestCase
{
    public function testDecimalStringsAreComparedWithoutFloats(): void
    {
        $this->assertSame(0, Money::compare('100', '100.000000'));
        $this->assertSame(1, Money::compare('0.1000000000000001', '0.1'));
        $this->assertSame(-1, Money::compare('99.999999999999999999', '100'));
        $this->assertSame('0.300000000000000000', Money::add('0.100000000000000000', '0.200000000000000000'));
        $this->assertSame('100.50', Money::add('60.25', '40.25'));
    }

    public function testDisplayRoundsUpToTheDisplayDecimalsAndNeverBelowTheValue(): void
    {
        $this->assertSame('12.011184', Money::display('12.011183118544993788', 6));
        $this->assertSame('12.011183', Money::display('12.011183000000000000', 6));
        $this->assertSame('13.00', Money::display('12.9999999', 6));
        $this->assertSame('0.000002', Money::display('0.0000010004', 6));
        $this->assertSame('100.00', Money::display('100.00', 6));
        $this->assertSame(6, Money::displayPlaces('bep20/usdt'));
        $this->assertNull(Money::displayPlaces('eth/eth'));
        $this->assertSame('12.00', Money::plain('12.000000000000000000'));
        for ($i = 0; $i < 500; $i++) {
            $stored = random_int(10, 99) . '.' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT) . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            $shown = Money::display($stored, 6);
            $this->assertLessThanOrEqual(6, strlen($shown) - strpos($shown, '.') - 1, $stored);
            $this->assertGreaterThanOrEqual(0, Money::compare($shown, $stored), $stored);
            $this->assertSame(-1, bccomp(bcsub($shown, $stored, 18), '0.000001', 18), $stored);
        }
    }

    public function testMalformedAmountsAreRefused(): void
    {
        foreach (['1e5', '-1', '+1', ' 1', '1.', '.5', '1,5', '', '0x10', '1.0000000000000000001'] as $value) {
            $this->assertFalse(Money::isDecimal($value), $value);
        }
        $this->expectException(\InvalidArgumentException::class);
        Money::add('1', '1e3');
    }

    public function testCreateAddressBuildsTheRequestAndKeepsStrings(): void
    {
        $seen = [];
        $client = new GatewayClient('https://gw.example/', function (string $url) use (&$seen): array {
            $seen[] = $url;
            return [200, '{"status":"success","address_in":"TAddr","address_out":"TOut","callback_url":"x","minimum_transaction_coin":"10.000000"}'];
        });
        $created = $client->createAddress('trc20/usdt', 'TOut', 'https://shop.example/?a=1&b=2');
        $this->assertSame(['address_in' => 'TAddr', 'minimum' => '10.000000', 'expires_at' => null], $created);
        $this->assertSame('https://gw.example/trc20/usdt/create/?address=TOut&callback=https%3A%2F%2Fshop.example%2F%3Fa%3D1%26b%3D2&pending=1', $seen[0]);
    }

    public function testGatewayErrorsAreSurfaced(): void
    {
        $client = new GatewayClient('https://gw.example', static fn (string $url): array => [400, '{"status":"error","error":"Address is invalid"}']);
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Address is invalid');
        $client->createAddress('trc20/usdt', 'bad', 'https://shop.example/');
    }

    public function testMalformedAnswersAndTickersAreRefused(): void
    {
        $client = new GatewayClient('https://gw.example', static fn (string $url): array => [200, '{"status":"success","address_in":"A","minimum_transaction_coin":"1e3"}']);
        try {
            $client->createAddress('trc20/usdt', 'x', 'https://shop.example/');
            $this->fail('expected an exception');
        } catch (GatewayException $failure) {
            $this->assertStringContainsString('minimum', $failure->getMessage());
        }
        $this->assertFalse(GatewayClient::isValidTicker('../etc/passwd'));
        $this->assertFalse(GatewayClient::isValidTicker('TRC20/USDT'));
        $this->assertTrue(GatewayClient::isValidTicker('bep20/usdt'));
    }

    public function testQrCodeAcceptsOnlyPngDataUris(): void
    {
        $good = new GatewayClient('https://gw.example', static fn (string $url): array => [200, '{"status":"success","qr_code":"data:image/png;base64,iVBORw0KGgo=","payment_uri":"TAddr"}']);
        $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $good->qrCode('trc20/usdt', 'TAddr', '10'));
        $bad = new GatewayClient('https://gw.example', static fn (string $url): array => [200, '{"status":"success","qr_code":"javascript:alert(1)","payment_uri":"x"}']);
        $this->assertNull($bad->qrCode('trc20/usdt', 'TAddr', '10'));
    }
}
