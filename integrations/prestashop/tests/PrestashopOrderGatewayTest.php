<?php

declare(strict_types=1);

namespace Suawave\Tests;

use PHPUnit\Framework\TestCase;

final class PrestashopOrderGatewayTest extends TestCase
{
    private const PAID_MESSAGE = [55, 'Suawave payment confirmed, received 12.00 of 12.00 to TA', 1];

    public function testFailedPaidEmailStillPaysTheOrderOnceAndIsLogged(): void
    {
        $result = $this->scenario('mail_false');

        $this->assertSame([[200, 'paid'], [200, 'duplicate']], $result['outcomes']);
        $this->assertSame(2, $result['state']);
        $this->assertSame([[55, 2]], $result['history']);
        $this->assertSame([[1, 55]], $result['emails']);
        $this->assertSame([self::PAID_MESSAGE], $result['messages']);
        $this->assertSame(['paid', '12.00'], $result['record']);
        $this->assertSame(['u-1'], $result['events']);
        $this->assertSame(1, $result['cache_cleaned']);
        $this->assertCount(1, $result['log']);
        $this->assertSame(3, $result['log'][0][0]);
        $this->assertStringContainsString('the paid order email for order 55 could not be sent, the order is paid', $result['log'][0][1]);
        $this->assertStringStartsWith('suawave mail failed: Suawave\\MailFailure: ', $result['log'][0][1]);
        $this->assertStringNotContainsString('callback failed', $result['log'][0][1]);
    }

    public function testThrowingMailTransportStillPaysTheOrderAndLogsTheCause(): void
    {
        $result = $this->scenario('mail_throws');

        $this->assertSame([[200, 'paid'], [200, 'duplicate']], $result['outcomes']);
        $this->assertSame(2, $result['state']);
        $this->assertSame([[55, 2]], $result['history']);
        $this->assertSame(['paid', '12.00'], $result['record']);
        $this->assertCount(1, $result['log']);
        $this->assertStringContainsString('could not be sent, the order is paid: Swift Error: Expected response code 220', $result['log'][0][1]);
        $this->assertStringStartsWith('suawave mail failed: ', $result['log'][0][1]);
    }

    public function testSentPaidEmailLogsNothing(): void
    {
        $result = $this->scenario('mail_ok');

        $this->assertSame([[200, 'paid'], [200, 'duplicate']], $result['outcomes']);
        $this->assertSame([[55, 2]], $result['history']);
        $this->assertSame([[1, 55]], $result['emails']);
        $this->assertSame([], $result['log']);
    }

    public function testHistorySaveFailureStillAnswers500AndCommitsNothing(): void
    {
        $result = $this->scenario('history_fails');

        $this->assertSame([[500, 'processing_failed'], [500, 'processing_failed']], $result['outcomes']);
        $this->assertSame(9, $result['state']);
        $this->assertSame([], $result['history']);
        $this->assertSame([], $result['emails']);
        $this->assertSame(['awaiting', '0'], $result['record']);
        $this->assertSame([], $result['events']);
        $this->assertCount(2, $result['log']);
        $this->assertStringContainsString('the order history could not be saved', $result['log'][0][1]);
        $this->assertStringStartsWith('suawave callback failed: ', $result['log'][0][1]);
    }

    private function scenario(string $scenario): array
    {
        $root = dirname(__DIR__);
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/order_gateway_scenario.php', $root, $scenario],
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
