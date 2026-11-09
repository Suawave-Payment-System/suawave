<?php

declare(strict_types=1);

namespace Suawave\Tests;

use PHPUnit\Framework\TestCase;
use Suawave\Tests\Support\Config;
use Suawave\Tests\Support\Loader;
use Suawave\Tests\Support\Registry;

if (!defined('DIR_EXTENSION')) {
    define('DIR_EXTENSION', dirname(__DIR__) . '/upload/extension/');
}
require_once __DIR__ . '/Support/OpenCartStubs.php';
require_once DIR_EXTENSION . 'suawave/admin/controller/payment/suawave.php';

final class TestableAdminController extends \Opencart\Admin\Controller\Extension\Suawave\Payment\Suawave
{
    public function defaults(): array
    {
        return $this->statusDefaults();
    }
}

final class AdminStatusDefaultsTest extends TestCase
{
    private const STOCK = [
        ['order_status_id' => '7', 'name' => 'Canceled'],
        ['order_status_id' => '5', 'name' => 'Complete'],
        ['order_status_id' => '1', 'name' => 'Pending'],
        ['order_status_id' => '2', 'name' => 'Processing'],
        ['order_status_id' => '3', 'name' => 'Shipped'],
    ];

    private function registry(array $config, array $statuses, array $stored = [], ?object $setting = null): Registry
    {
        $registry = new Registry();
        $registry->set('config', new Config($config));
        $models = [
            'localisation/order_status' => new class ($statuses) {
                public function __construct(private readonly array $statuses)
                {
                }

                public function getOrderStatuses(): array
                {
                    return $this->statuses;
                }
            },
            'extension/suawave/payment/suawave' => new class {
                public int $installs = 0;

                public function install(): void
                {
                    $this->installs++;
                }
            },
            'setting/setting' => $setting ?? new class ($stored) {
                public array $saved = [];

                public function __construct(private readonly array $stored)
                {
                }

                public function getSetting(string $code): array
                {
                    return $this->stored;
                }

                public function editSetting(string $code, array $data): void
                {
                    $this->saved[] = $data;
                }
            },
        ];
        $registry->set('load', new Loader($registry, $models));
        $registry->set('user', new class {
            public function hasPermission(string $action, string $route): bool
            {
                return true;
            }
        });
        return $registry;
    }

    private function defaults(array $config, array $statuses = self::STOCK): array
    {
        return (new TestableAdminController($this->registry($config, $statuses)))->defaults();
    }

    public function testStockStatusListResolvesPaidToProcessingNotComplete(): void
    {
        $resolved = $this->defaults(['config_order_status_id' => '1', 'config_processing_status' => ['5', '1', '2', '12', '3']]);

        $this->assertSame('2', $resolved['paid_status_id']);
        $this->assertSame('1', $resolved['pending_status_id']);
    }

    public function testWithoutAProcessingNamedStatusTheFirstConfiguredProcessingStatusIsUsed(): void
    {
        $resolved = $this->defaults(
            ['config_order_status_id' => '1', 'config_processing_status' => ['5', '1']],
            [['order_status_id' => '5', 'name' => 'Complete'], ['order_status_id' => '1', 'name' => 'Pending']]
        );

        $this->assertSame('5', $resolved['paid_status_id']);
    }

    public function testInstallKeepsExistingSettingsAndWritesNothingWhenComplete(): void
    {
        $stored = ['payment_suawave_payout_address' => 'T1', 'payment_suawave_pending_status_id' => '1', 'payment_suawave_paid_status_id' => '3'];
        $registry = $this->registry(['config_order_status_id' => '1'], self::STOCK, $stored);
        $controller = new TestableAdminController($registry);

        $controller->install();
        $controller->install();

        $this->assertSame([], $registry->get('model_setting_setting')->saved);
        $this->assertSame(2, $registry->get('model_extension_suawave_payment_suawave')->installs);
    }

    public function testInstallFillsOnlyEmptyStatusesAndKeepsTheRest(): void
    {
        $stored = ['payment_suawave_payout_address' => 'T1', 'payment_suawave_paid_status_id' => '3', 'payment_suawave_pending_status_id' => ''];
        $registry = $this->registry(['config_order_status_id' => '1'], self::STOCK, $stored);

        (new TestableAdminController($registry))->install();

        $saved = $registry->get('model_setting_setting')->saved;
        $this->assertCount(1, $saved);
        $this->assertSame('T1', $saved[0]['payment_suawave_payout_address']);
        $this->assertSame('3', $saved[0]['payment_suawave_paid_status_id']);
        $this->assertSame('1', $saved[0]['payment_suawave_pending_status_id']);
    }

    public function testUnsetSettingsResolveToTheStorePendingAndFirstProcessingStatus(): void
    {
        $resolved = $this->defaults(['config_order_status_id' => '1', 'config_processing_status' => ['2', '15']]);

        $this->assertSame('1', $resolved['pending_status_id']);
        $this->assertSame('2', $resolved['paid_status_id']);
    }

    public function testSavedValuesWin(): void
    {
        $resolved = $this->defaults([
            'payment_suawave_pending_status_id' => '3',
            'payment_suawave_paid_status_id' => '5',
            'config_order_status_id' => '1',
            'config_processing_status' => ['2'],
        ]);

        $this->assertSame('3', $resolved['pending_status_id']);
        $this->assertSame('5', $resolved['paid_status_id']);
    }

    public function testInstallAndSaveWriteTheDefaults(): void
    {
        $source = (string) file_get_contents(DIR_EXTENSION . 'suawave/admin/controller/payment/suawave.php');

        $this->assertSame(3, substr_count($source, '$this->statusDefaults()'));
        $this->assertStringContainsString('processingStatusId', $source);
    }
}
