<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testUpgradeAddsTheExpiryColumnsAndIndexToAnExistingTable(): void
    {
        $db = new FakeDb();

        (new Schema($db, 'oc_'))->upgrade();

        $alters = array_values(array_filter($db->queries, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE')));
        $this->assertSame([
            'ALTER TABLE `oc_suawave_payment` ADD COLUMN `expires_at` INT UNSIGNED NULL DEFAULT NULL',
            'ALTER TABLE `oc_suawave_payment` ADD COLUMN `expiry_noted_at` INT UNSIGNED NULL DEFAULT NULL',
            'ALTER TABLE `oc_suawave_payment` ADD KEY `expires_at` (`expires_at`)',
        ], $alters);
        foreach (array_diff($db->queries, $alters) as $check) {
            $this->assertStringContainsString('`information_schema`', $check);
            $this->assertStringContainsString('`TABLE_SCHEMA` = DATABASE()', $check);
            $this->assertStringContainsString('`TABLE_NAME` = "oc_suawave_payment"', $check);
        }
    }

    public function testUpgradeIsANoOpWhenEverythingExists(): void
    {
        $db = new FakeDb();
        $db->found = ['expires_at', 'expiry_noted_at'];

        (new Schema($db, 'oc_'))->upgrade();

        $this->assertCount(3, $db->queries);
        $this->assertSame([], array_filter($db->queries, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE')));
    }

    public function testUpgradeAddsOnlyWhatIsMissing(): void
    {
        $db = new FakeDb();
        $db->found = ['expires_at'];

        (new Schema($db, 'oc_'))->upgrade();

        $alters = array_values(array_filter($db->queries, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE')));
        $this->assertSame(['ALTER TABLE `oc_suawave_payment` ADD COLUMN `expiry_noted_at` INT UNSIGNED NULL DEFAULT NULL'], $alters);
    }

    public function testInstallCreatesBothTablesWithTheNewColumnsAndThenChecksThem(): void
    {
        $db = new FakeDb();
        $db->found = ['expires_at', 'expiry_noted_at'];

        (new Schema($db, 'oc_'))->install();

        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `oc_suawave_payment`', $db->queries[0]);
        $this->assertStringContainsString('`expires_at` INT UNSIGNED NULL DEFAULT NULL', $db->queries[0]);
        $this->assertStringContainsString('`expiry_noted_at` INT UNSIGNED NULL DEFAULT NULL', $db->queries[0]);
        $this->assertStringContainsString('ENGINE=InnoDB', $db->queries[0]);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `oc_suawave_event`', $db->queries[1]);
        $this->assertCount(5, $db->queries);
    }

    public function testAdminInstallAndSettingsSaveRunTheSchemaRoutine(): void
    {
        $base = dirname(__DIR__) . '/upload/extension/suawave/admin/';
        $model = (string) file_get_contents($base . 'model/payment/suawave.php');
        $controller = (string) file_get_contents($base . 'controller/payment/suawave.php');

        $this->assertStringContainsString('(new Schema($this->db, DB_PREFIX))->install();', $model);
        $save = substr($controller, strpos($controller, 'public function save()'));
        $save = substr($save, 0, strpos($save, 'public function install()'));
        $this->assertStringContainsString('$this->model_extension_suawave_payment_suawave->install();', $save);
    }
}
