<?php

declare(strict_types=1);

namespace {
    if (!function_exists('add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $args = 1): void
        {
            $GLOBALS['suawave_test_actions'][] = [$hook, $callback, $priority];
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use Suawave\CallbackEndpoint;

    final class CallbackEndpointTest extends TestCase
    {
        private const WOOCOMMERCE_REGISTER_TYPES_PRIORITY = 5;

        public function testHandlerRunsAfterWooCommerceRegistersOrderTypes(): void
        {
            $GLOBALS['suawave_test_actions'] = [];
            CallbackEndpoint::register();
            $found = [];
            foreach ($GLOBALS['suawave_test_actions'] as [$hook, $callback, $priority]) {
                if ($callback === [CallbackEndpoint::class, 'maybeHandle']) {
                    $found[] = [$hook, $priority];
                }
            }
            self::assertCount(1, $found);
            self::assertSame('init', $found[0][0]);
            self::assertGreaterThan(self::WOOCOMMERCE_REGISTER_TYPES_PRIORITY, $found[0][1]);
        }
    }
}
