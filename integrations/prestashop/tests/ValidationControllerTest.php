<?php

declare(strict_types=1);

namespace Suawave\Tests;

use PHPUnit\Framework\TestCase;

final class ValidationControllerTest extends TestCase
{
    private const CONFIRMATION = 'order-confirmation?id_cart=7&id_module=3&id_order=55&key=K';
    private const RENEWED = 'Suawave - pay before 2026-09-11 00:00 (UTC)';

    public function testCartThatAlreadyHasAnOrderRenewsTheAttemptInsteadOfValidatingAgain(): void
    {
        $result = $this->scenario('ordered_cart');

        $this->assertSame([], $result['validated']);
        $this->assertSame(self::CONFIRMATION, $result['redirect']);
        $this->assertSame(1, $result['requests']);
        $this->assertSame('superseded', $result['records']['TA'][0]);
        $this->assertSame(['awaiting', 55], array_slice($result['records']['TB'], 0, 2));
        $this->assertNotSame('old-nonce', $result['records']['TB'][2]);
        $this->assertSame([
            ['orders.payment', 55, self::RENEWED],
            ['order_payment.payment_method', 55, self::RENEWED],
        ], $result['writes']);
        $this->assertSame(9, $result['orders']['55']['current_state']);
    }

    public function testRetryFormOnTheClosedWindowRenewsTheExistingOrder(): void
    {
        $result = $this->scenario('retry_form');

        $this->assertSame([], $result['validated']);
        $this->assertSame(self::CONFIRMATION, $result['redirect']);
        $this->assertSame('superseded', $result['records']['TA'][0]);
        $this->assertSame('awaiting', $result['records']['TB'][0]);
    }

    public function testOldCallbackPayingAfterTheRenewalKeepsThePaidStateAndLabel(): void
    {
        $result = $this->scenario('paid_between');

        $this->assertSame('paid_previous_attempt', $result['callback']);
        $this->assertSame(self::CONFIRMATION, $result['redirect']);
        $this->assertSame(2, $result['orders']['55']['current_state']);
        $this->assertSame('Suawave - paid', $result['orders']['55']['payment']);
        $this->assertSame([
            ['orders.payment', 55, self::RENEWED],
            ['order_payment.payment_method', 55, self::RENEWED],
            ['orders.payment', 55, 'Suawave - paid'],
            ['order_payment.payment_method', 55, 'Suawave - paid'],
            ['message', 55, 'Suawave payment confirmed, received 25.00 of 25.00 to TA'],
            ['orders.*', 55, ['current_state' => 2, 'payment' => 'Suawave - paid']],
        ], $result['writes']);
        $this->assertSame('paid', $result['records']['TA'][0]);
    }

    public function testRetryWithAWrongKeyChangesNothing(): void
    {
        $result = $this->scenario('wrong_key');

        $this->assertSame('index', $result['redirect']);
        $this->assertSame(0, $result['requests']);
        $this->assertSame(['TA'], array_keys($result['records']));
        $this->assertSame([], $result['writes']);
    }

    public function testOrderThatLeftTheAwaitingStateIsNotRenewed(): void
    {
        $result = $this->scenario('paid_order');

        $this->assertSame(self::CONFIRMATION, $result['redirect']);
        $this->assertSame(0, $result['requests']);
        $this->assertSame([], $result['validated']);
    }

    public function testNewCartValidatesTheOrderOnceAndAttachesTheAttempt(): void
    {
        $result = $this->scenario('new_cart');

        $this->assertCount(1, $result['validated']);
        $this->assertSame(7, $result['validated'][0][0]);
        $this->assertSame(self::RENEWED, $result['validated'][0][2]);
        $this->assertSame([], $result['writes']);
        $this->assertSame('order-confirmation?id_cart=7&id_module=3&id_order=56&key=K', $result['redirect']);
        $this->assertSame(['awaiting', 56], array_slice($result['records']['TB'], 0, 2));
    }

    private function scenario(string $scenario): array
    {
        $root = dirname(__DIR__);
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/validation_scenario.php', $root, $scenario],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $lines = array_values(array_filter(explode("\n", (string) $output), static fn (string $line): bool => str_starts_with($line, '{')));
        $result = json_decode((string) end($lines), true);
        $this->assertIsArray($result, $output . $errors);
        return $result;
    }
}
