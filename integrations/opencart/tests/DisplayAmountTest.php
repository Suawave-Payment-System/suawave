<?php

declare(strict_types=1);

namespace Suawave\Tests;

use DateTimeZone;
use Suawave\Decimal;
use Suawave\PaymentPage;
use Suawave\PaymentRecord;
use PHPUnit\Framework\TestCase;

final class DisplayAmountTest extends TestCase
{
    public function testDisplayRoundsUpToTheDisplayDecimalsAndNeverBelowTheValue(): void
    {
        $this->assertSame('12.011184', Decimal::display('12.011183118544993788', 6));
        $this->assertSame('12.011183', Decimal::display('12.011183000000000000', 6));
        $this->assertSame('13.00', Decimal::display('12.9999999', 6));
        $this->assertSame('0.000002', Decimal::display('0.0000010004', 6));
        $this->assertSame('25.00', Decimal::display('25.00', 6));
        $this->assertSame('12.011183118544993788', Decimal::display('12.011183118544993788', null));
        $this->assertSame(6, Decimal::displayPlaces('bep20/usdt'));
        $this->assertNull(Decimal::displayPlaces('eth/eth'));
        $this->assertSame('12.00', Decimal::plain('12.000000000000000000'));
        for ($i = 0; $i < 500; $i++) {
            $stored = random_int(10, 99) . '.' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT) . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            $shown = Decimal::display($stored, 6);
            $this->assertLessThanOrEqual(6, strlen($shown) - strpos($shown, '.') - 1, $stored);
            $this->assertGreaterThanOrEqual(0, Decimal::compare($shown, $stored), $stored);
            $this->assertSame(-1, bccomp(bcsub($shown, $stored, 18), '0.000001', 18), $stored);
        }
    }

    public function testConfirmPayloadShowsTheRoundedUpAmountAndKeepsTheStoredOne(): void
    {
        $record = new PaymentRecord(55, 'n', 'bep20/usdt', '0xDeposit', '0xPayout', '12.011183118544993788', '10.000000000000000000', '0', PaymentRecord::AWAITING, time() + 3600);
        $payload = (new PaymentPage([], new DateTimeZone('UTC')))->confirmPayload($record, time());

        $this->assertSame('12.011184', $payload['amount']);
        $this->assertSame('0xDeposit', $payload['qr_text']);
        $this->assertSame(1, Decimal::compare($payload['amount'], $record->amount));
        $this->assertSame('12.011183118544993788', $record->amount);
    }

    public function testTwoPlaceOrderAmountsAreShownUnchanged(): void
    {
        $record = new PaymentRecord(55, 'n', 'bep20/usdt', '0xDeposit', '0xPayout', '12.00', '10.000000000000000000', '0', PaymentRecord::AWAITING, time() + 3600);
        $this->assertSame('12.00', (new PaymentPage([], new DateTimeZone('UTC')))->confirmPayload($record, time())['amount']);
    }
}
