<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\CallbackVerifier;
use PHPUnit\Framework\TestCase;

final class CallbackVerifierTest extends TestCase
{
    private const ORIGIN = 'https://shop.example';

    private string $publicKey;
    private string $getUrl;
    private string $getSignature;
    private string $postBody;
    private string $postSignature;

    protected function setUp(): void
    {
        $fixtures = __DIR__ . '/fixtures/';
        $this->publicKey = (string) file_get_contents($fixtures . 'public.pem');
        $this->getUrl = (string) file_get_contents($fixtures . 'get-url.txt');
        $this->getSignature = (string) file_get_contents($fixtures . 'get-url.txt.sig');
        $this->postBody = (string) file_get_contents($fixtures . 'post-body.json');
        $this->postSignature = (string) file_get_contents($fixtures . 'post-body.json.sig');
    }

    private function requestUri(): string
    {
        return substr($this->getUrl, strlen(self::ORIGIN));
    }

    public function testKnownGoodGetVector(): void
    {
        $this->assertTrue(CallbackVerifier::verify('GET', $this->requestUri(), '', $this->getSignature, $this->publicKey, self::ORIGIN));
    }

    public function testKnownGoodPostVector(): void
    {
        $this->assertTrue(CallbackVerifier::verify('POST', '/?suawave_callback=1', $this->postBody, $this->postSignature, $this->publicKey, self::ORIGIN));
    }

    public function testTrailingSlashOnConfiguredOriginIsTolerated(): void
    {
        $this->assertTrue(CallbackVerifier::verify('GET', $this->requestUri(), '', $this->getSignature, $this->publicKey, self::ORIGIN . '/'));
    }

    public function testBadSignatureIsRejected(): void
    {
        $tampered = ($this->getSignature[10] === 'A' ? 'B' : 'A');
        $signature = substr_replace($this->getSignature, $tampered, 10, 1);
        $this->assertFalse(CallbackVerifier::verify('GET', $this->requestUri(), '', $signature, $this->publicKey, self::ORIGIN));
    }

    public function testSignatureOverDifferentBytesIsRejected(): void
    {
        $reordered = str_replace('order=1001&nonce=7f3a9c0000000000000000000000aaaa', 'nonce=7f3a9c0000000000000000000000aaaa&order=1001', $this->requestUri());
        $this->assertNotSame($this->requestUri(), $reordered);
        $this->assertFalse(CallbackVerifier::verify('GET', $reordered, '', $this->getSignature, $this->publicKey, self::ORIGIN));

        $reserialized = (string) json_encode(json_decode($this->postBody, true), JSON_PRETTY_PRINT);
        $this->assertFalse(CallbackVerifier::verify('POST', '/', $reserialized, $this->postSignature, $this->publicKey, self::ORIGIN));

        $changedAmount = str_replace('100.000000', '999.000000', $this->postBody);
        $this->assertFalse(CallbackVerifier::verify('POST', '/', $changedAmount, $this->postSignature, $this->publicKey, self::ORIGIN));
    }

    public function testOtherOriginIsRejected(): void
    {
        $this->assertFalse(CallbackVerifier::verify('GET', $this->requestUri(), '', $this->getSignature, $this->publicKey, 'https://evil.example'));
    }

    public function testMissingSignatureIsRejected(): void
    {
        $this->assertFalse(CallbackVerifier::verify('GET', $this->requestUri(), '', '', $this->publicKey, self::ORIGIN));
    }

    public function testNonBase64SignatureIsRejected(): void
    {
        $this->assertFalse(CallbackVerifier::verify('GET', $this->requestUri(), '', '!!!not base64!!!', $this->publicKey, self::ORIGIN));
    }

    public function testUnparseableKeyIsRejected(): void
    {
        $this->assertFalse(CallbackVerifier::verify('GET', $this->requestUri(), '', $this->getSignature, 'not a key', self::ORIGIN));
        $this->assertFalse(CallbackVerifier::verify('GET', $this->requestUri(), '', $this->getSignature, '', self::ORIGIN));
    }

    public function testTooShortKeyIsRejected(): void
    {
        $short = (string) file_get_contents(__DIR__ . '/fixtures/public-1024.pem');
        $this->assertFalse(CallbackVerifier::isUsableKey($short));
        $this->assertFalse(CallbackVerifier::verify('GET', $this->requestUri(), '', $this->getSignature, $short, self::ORIGIN));
    }

    public function testUnsupportedMethodAndOversizedBodyAreRejected(): void
    {
        $this->assertFalse(CallbackVerifier::verify('PUT', $this->requestUri(), '', $this->getSignature, $this->publicKey, self::ORIGIN));
        $this->assertFalse(CallbackVerifier::verify('POST', '/', str_repeat('a', CallbackVerifier::MAX_BODY_BYTES + 1), $this->postSignature, $this->publicKey, self::ORIGIN));
    }
}
