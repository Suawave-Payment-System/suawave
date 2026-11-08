<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/BlocksStubs.php';

use PHPUnit\Framework\TestCase;
use Suawave\WooBlocks;
use Suawave\WooGateway;

final class WooBlocksTest extends TestCase
{
    private function method(array $settings): WooBlocks
    {
        $GLOBALS['suawave_test_options']['woocommerce_suawave_settings'] = $settings;
        $GLOBALS['suawave_test_scripts'] = [];
        $method = new WooBlocks(dirname(__DIR__) . '/suawave-payment.php');
        $method->initialize();
        return $method;
    }

    public function testIsActiveFollowsEnabledFlag(): void
    {
        self::assertTrue($this->method(['enabled' => 'yes'])->is_active());
        self::assertFalse($this->method(['enabled' => 'no'])->is_active());
        self::assertFalse($this->method([])->is_active());
    }

    public function testNameMatchesGatewayId(): void
    {
        self::assertSame('suawave', $this->method([])->get_name());
    }

    public function testPaymentMethodDataCarriesTitleDescriptionAndSupports(): void
    {
        $data = $this->method(['enabled' => 'yes', 'title' => 'Pay in USDT', 'description' => 'Deposit address follows.'])->get_payment_method_data();
        self::assertSame('Pay in USDT', $data['title']);
        self::assertSame('Deposit address follows.', $data['description']);
        self::assertSame(WooGateway::SUPPORTS, $data['supports']);
    }

    public function testScriptHandleIsRegistered(): void
    {
        $handles = $this->method(['enabled' => 'yes'])->get_payment_method_script_handles();
        self::assertSame([WooBlocks::HANDLE], $handles);
        self::assertArrayHasKey(WooBlocks::HANDLE, $GLOBALS['suawave_test_scripts']);
        self::assertStringEndsWith('assets/blocks.js', $GLOBALS['suawave_test_scripts'][WooBlocks::HANDLE]['src']);
        self::assertContains('wc-blocks-registry', $GLOBALS['suawave_test_scripts'][WooBlocks::HANDLE]['deps']);
    }
}
