<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\Schema;
use Suawave\SqlPaymentStore;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testUpgradeAddsTheDeadlineColumnToAnExistingTable(): void
    {
        $db = new FakeSqlConnection();
        $db->answers = ['`TABLES`' => ['found' => '1'], '`COLUMNS`' => ['found' => '0']];

        $added = (new Schema($db, 'ps_'))->upgrade();

        $this->assertSame(1, $added);
        $this->assertStringContainsString("`TABLE_NAME` = 'ps_suawave_payment' AND `COLUMN_NAME` = 'expires_at'", $db->statements[1]);
        $this->assertSame('ALTER TABLE `ps_suawave_payment` ADD COLUMN `expires_at` INT UNSIGNED NULL DEFAULT NULL AFTER `status`, ADD KEY `expires_at` (`expires_at`)', $db->statements[2]);
    }

    public function testUpgradeIsIdempotent(): void
    {
        $db = new FakeSqlConnection();
        $db->answers = ['`TABLES`' => ['found' => '1'], '`COLUMNS`' => ['found' => '1']];

        $this->assertSame(0, (new Schema($db, 'ps_'))->upgrade());
        foreach ($db->statements as $statement) {
            $this->assertStringNotContainsString('ALTER', $statement);
        }
    }

    public function testUpgradeBeforeInstallTouchesNothing(): void
    {
        $db = new FakeSqlConnection();
        $db->answers = ['`TABLES`' => ['found' => '0']];

        $this->assertSame(0, (new Schema($db, 'ps_'))->upgrade());
        $this->assertCount(1, $db->statements);
    }

    public function testInstallCreatesBothTablesWithTheDeadlineThenChecksTheColumns(): void
    {
        $db = new FakeSqlConnection();
        $db->answers = ['`TABLES`' => ['found' => '1'], '`COLUMNS`' => ['found' => '1']];

        (new Schema($db, 'ps_'))->install();

        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_suawave_payment`', $db->statements[0]);
        $this->assertStringContainsString('`expires_at` INT UNSIGNED NULL DEFAULT NULL', $db->statements[0]);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_suawave_event`', $db->statements[1]);
        $this->assertCount(4, $db->statements);
    }

    public function testOverdueSweepSkipsSupersededRowsAndPaidCarts(): void
    {
        $db = new FakeSqlConnection();

        (new SqlPaymentStore($db, 'ps_'))->findOverdue(1789000001);

        $this->assertStringContainsString("`p`.`status` IN ('awaiting', 'detected')", $db->statements[0]);
        $this->assertStringContainsString("NOT EXISTS (SELECT 1 FROM `ps_suawave_payment` `n` WHERE `n`.`id_cart` = `p`.`id_cart` AND (`n`.`id_payment` > `p`.`id_payment` OR `n`.`status` = 'paid'))", $db->statements[0]);
    }
}
