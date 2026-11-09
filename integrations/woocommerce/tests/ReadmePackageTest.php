<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReadmePackageTest extends TestCase
{
    public function testReadmeNamesEveryShippedAsset(): void
    {
        $root = dirname(__DIR__);
        $readme = (string) file_get_contents($root . '/README.md');
        $files = glob($root . '/assets/*') ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertStringContainsString('`assets/' . basename($file) . '`', $readme);
        }
        $this->assertStringContainsString('`includes/`', $readme);
        $this->assertStringNotContainsString('`includes/` only', $readme);
    }
}
