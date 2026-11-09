<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use PHPUnit\Framework\TestCase;

final class LayoutCacheabilityTest extends TestCase
{
    private const PER_ORDER_CLASS = 'Suawave\Payment\Block\Payment\Instructions';

    private function layoutFiles(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/view/frontend/layout/*.xml');
        $this->assertNotEmpty($files);
        return $files;
    }

    public function testEveryPerOrderBlockIsNotCacheable(): void
    {
        $found = 0;
        foreach ($this->layoutFiles() as $file) {
            $xml = simplexml_load_file($file);
            $this->assertNotFalse($xml, $file);
            foreach ($xml->xpath('//block') as $block) {
                if ((string) $block['class'] !== self::PER_ORDER_CLASS) {
                    continue;
                }
                $found++;
                $this->assertSame('false', (string) $block['cacheable'], basename($file) . ' ' . (string) $block['name']);
            }
        }
        $this->assertGreaterThanOrEqual(2, $found);
    }

    public function testPaymentIndexLayoutBlockIsNotCacheable(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/view/frontend/layout/suawave_payment_index.xml');
        $blocks = $xml->xpath('//block[@name="suawave.payment.instructions"]');
        $this->assertCount(1, $blocks);
        $this->assertSame('false', (string) $blocks[0]['cacheable']);
    }

    public function testNoSuawaveBlockInAnyLayoutIsCacheable(): void
    {
        foreach ($this->layoutFiles() as $file) {
            $xml = simplexml_load_file($file);
            foreach ($xml->xpath('//block[starts-with(@name,"suawave.")]') as $block) {
                $this->assertSame('false', (string) $block['cacheable'], basename($file));
            }
        }
    }
}
