<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\Decimal;
use Suawave\InstructionsView;
use Suawave\PaymentRecord;
use PHPUnit\Framework\TestCase;

final class InstructionsTemplateTest extends TestCase
{
    private const DEADLINE = 1789000000;
    private const QR = 'data:image/png;base64,UVJDT0RF';

    public function testOpenWindowShowsAddressQrAndDeadline(): void
    {
        $html = $this->render(PaymentRecord::AWAITING, self::DEADLINE - 1);

        $this->assertStringContainsString('<code>TDepositA</code>', $html);
        $this->assertStringContainsString(self::QR, $html);
        $this->assertStringContainsString('Pay before: <strong>2026-09-10 00:26 (UTC)</strong>', $html);
        $this->assertStringNotContainsString('Payment window closed', $html);
        $this->assertStringNotContainsString('id_order', $html);
    }

    public function testExpiredWindowHidesAddressAndQrAndShowsTheOrderId(): void
    {
        $qrCalls = 0;
        $html = $this->render(PaymentRecord::AWAITING, self::DEADLINE, $qrCalls);

        $this->assertSame(0, $qrCalls);
        $this->assertStringNotContainsString('TDepositA', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Payment window closed', $html);
        $this->assertStringContainsString('<strong>XKBKNABJK</strong> (#55)', $html);
        $this->assertStringContainsString('closed on 2026-09-10 00:26 (UTC)', $html);
        $this->assertStringContainsString('action="https://shop.example/module/suawave/validation?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('name="id_order" value="55"', $html);
        $this->assertStringContainsString('name="key" value="secret-key"', $html);
    }

    public function testExpiredOrSupersededStatusIsClosedEvenWithoutADeadline(): void
    {
        foreach ([PaymentRecord::EXPIRED, PaymentRecord::SUPERSEDED] as $status) {
            $vars = InstructionsView::variables($this->record($status, null), 55, 'XKBKNABJK', '', self::DEADLINE, fn (): string => self::QR, '', '');
            $html = TemplateRenderer::render($this->template(), $vars);
            $this->assertStringNotContainsString('TDepositA', $html, $status);
            $this->assertStringContainsString('(#55)', $html, $status);
        }
    }

    public function testPaidOrderAfterTheDeadlineShowsPaidAndNoAddress(): void
    {
        $qrCalls = 0;
        $html = $this->render(PaymentRecord::PAID, self::DEADLINE + 3600, $qrCalls);

        $this->assertSame(0, $qrCalls);
        $this->assertStringContainsString('Your crypto payment has been confirmed.', $html);
        $this->assertStringNotContainsString('TDepositA', $html);
        $this->assertStringNotContainsString('Payment window closed', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testClosedWindowWithoutRetryOffersNoForm(): void
    {
        $vars = InstructionsView::variables($this->record(PaymentRecord::AWAITING, self::DEADLINE), 55, 'XKBKNABJK', 'x', self::DEADLINE, fn (): string => self::QR, '', '');

        $html = TemplateRenderer::render($this->template(), $vars);

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('Please contact the shop.', $html);
    }

    private function render(string $status, int $now, int &$qrCalls = 0): string
    {
        $vars = InstructionsView::variables(
            $this->record($status, self::DEADLINE),
            55,
            'XKBKNABJK',
            '2026-09-10 00:26 (UTC)',
            $now,
            function () use (&$qrCalls): string {
                $qrCalls++;
                return self::QR;
            },
            'https://shop.example/module/suawave/validation?a=1&b=2',
            'secret-key'
        );
        return TemplateRenderer::render($this->template(), $vars);
    }

    public function testBscAmountAndMinimumRoundUpToSixDecimalsWithoutTrailingZeros(): void
    {
        $record = new PaymentRecord(7, 55, 'n', 'bep20/usdt', 'TDepositA', 'TPayout', '12.011260345365980608', '10.000000000000000000', '0', PaymentRecord::AWAITING, self::DEADLINE);
        $vars = InstructionsView::variables($record, 55, 'XKBKNABJK', '', self::DEADLINE - 1, fn (): string => self::QR, '', '');
        $html = TemplateRenderer::render($this->template(), $vars);

        $this->assertStringContainsString('<strong>12.011261</strong>', $html);
        $this->assertStringContainsString('Minimum accepted per transfer: 10.00', $html);
        $this->assertStringNotContainsString('000000000000', $html);
    }

    public function testShownAmountAndQrAmountRoundUpAndAreNeverBelowTheStoredAmount(): void
    {
        $record = new PaymentRecord(7, 55, 'n', 'bep20/usdt', 'TDepositA', 'TPayout', '12.011183118544993788', '10.000000000000000001', '5.100000000000000009', PaymentRecord::AWAITING, self::DEADLINE);
        $qrAmounts = [];
        $vars = InstructionsView::variables($record, 55, 'XKBKNABJK', '', self::DEADLINE - 1, function (PaymentRecord $shown, string $amount) use (&$qrAmounts): string {
            $qrAmounts[] = $amount;
            return self::QR;
        }, '', '');
        $html = TemplateRenderer::render($this->template(), $vars);

        $this->assertSame('12.011184', $vars['suawave_amount']);
        $this->assertSame(['12.011184'], $qrAmounts);
        $this->assertSame('10.000001', $vars['suawave_minimum']);
        $this->assertSame('5.100000000000000009', $vars['suawave_received']);
        $this->assertStringContainsString('<strong>12.011184</strong>', $html);
        $this->assertSame(1, Decimal::compare($vars['suawave_amount'], $record->amount));
        $this->assertSame('12.011183118544993788', $record->amount);
    }

    public function testDisplayRoundsUpToTheDisplayDecimalsAndNeverBelowTheValue(): void
    {
        $this->assertSame('12.011184', Decimal::display('12.011183118544993788', 6));
        $this->assertSame('12.011183', Decimal::display('12.011183000000000000', 6));
        $this->assertSame('13.00', Decimal::display('12.9999999', 6));
        $this->assertSame('0.000002', Decimal::display('0.0000010004', 6));
        $this->assertSame('25.00', Decimal::display('25.00', 6));
        $this->assertSame('12.00', Decimal::plain('12.000000000000000000'));
        for ($i = 0; $i < 500; $i++) {
            $stored = random_int(10, 99) . '.' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT) . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            $shown = Decimal::display($stored, 6);
            $this->assertLessThanOrEqual(6, strlen($shown) - strpos($shown, '.') - 1, $stored);
            $this->assertGreaterThanOrEqual(0, Decimal::compare($shown, $stored), $stored);
            $this->assertSame(-1, bccomp(bcsub($shown, $stored, 18), '0.000001', 18), $stored);
        }
    }

    private function record(string $status, ?int $expiresAt): PaymentRecord
    {
        return new PaymentRecord(7, 55, 'n', 'trc20/usdt', 'TDepositA', 'TPayout', '25.00', '10', '0', $status, $expiresAt);
    }

    private function template(): string
    {
        return dirname(__DIR__) . '/suawave/views/templates/hook/payment_return.tpl';
    }
}
