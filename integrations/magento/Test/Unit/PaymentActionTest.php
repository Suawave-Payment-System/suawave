<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use PHPUnit\Framework\TestCase;

final class PaymentActionTest extends TestCase
{
    public function testConfigDoesNotRequestAnOrderAction(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/config.xml');
        $method = $xml->default->payment->suawave;

        self::assertCount(0, $method->payment_action);
        self::assertSame('pending', (string) $method->order_status);
    }

    public function testMethodDoesNotDeclareTheOrderCapability(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/Model/Payment/Suawave.php');

        self::assertStringNotContainsString('_canOrder', $source);
        self::assertStringNotContainsString('function order(', $source);
    }
}
