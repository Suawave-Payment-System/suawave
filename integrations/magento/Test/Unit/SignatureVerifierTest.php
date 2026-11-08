<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use Suawave\Payment\Core\InvalidKeyException;
use Suawave\Payment\Core\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private SignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new SignatureVerifier();
    }

    public function testKnownGoodBodyVector(): void
    {
        self::assertTrue($this->verifier->verify(
            Fixtures::publicKey(),
            Fixtures::read('confirmed_body.signature'),
            Fixtures::read('confirmed_body.json')
        ));
    }

    public function testKnownGoodUrlVector(): void
    {
        self::assertTrue($this->verifier->verify(
            Fixtures::publicKey(),
            Fixtures::read('confirmed_get_url.signature'),
            Fixtures::read('confirmed_get_url.txt')
        ));
    }

    public function testBadSignatureIsRejected(): void
    {
        $signature = base64_decode(Fixtures::read('confirmed_body.signature'), true);
        $signature[10] = chr(ord($signature[10]) ^ 0x01);
        self::assertFalse($this->verifier->verify(
            Fixtures::publicKey(),
            base64_encode($signature),
            Fixtures::read('confirmed_body.json')
        ));
    }

    public function testSignatureOverDifferentBodyBytesIsRejected(): void
    {
        $reserialized = json_encode(json_decode(Fixtures::read('confirmed_body.json'), true), JSON_PRETTY_PRINT);
        self::assertNotSame(Fixtures::read('confirmed_body.json'), $reserialized);
        self::assertFalse($this->verifier->verify(
            Fixtures::publicKey(),
            Fixtures::read('confirmed_body.signature'),
            $reserialized
        ));
    }

    public function testReorderedQueryStringIsRejected(): void
    {
        $url = Fixtures::read('confirmed_get_url.txt');
        [$path, $query] = explode('?', $url, 2);
        $pairs = explode('&', $query);
        $pairs = array_reverse($pairs);
        self::assertFalse($this->verifier->verify(
            Fixtures::publicKey(),
            Fixtures::read('confirmed_get_url.signature'),
            $path . '?' . implode('&', $pairs)
        ));
    }

    public function testDifferentOriginIsRejected(): void
    {
        $url = str_replace('https://shop.example', 'https://evil.example', Fixtures::read('confirmed_get_url.txt'));
        self::assertFalse($this->verifier->verify(
            Fixtures::publicKey(),
            Fixtures::read('confirmed_get_url.signature'),
            $url
        ));
    }

    public function testEmptyAndNonBase64SignaturesAreRejected(): void
    {
        $body = Fixtures::read('confirmed_body.json');
        self::assertFalse($this->verifier->verify(Fixtures::publicKey(), '', $body));
        self::assertFalse($this->verifier->verify(Fixtures::publicKey(), '%%%not base64%%%', $body));
    }

    public function testSignatureFromAnotherKeyIsRejected(): void
    {
        $other = KeyPair::generate(2048);
        $body = Fixtures::read('confirmed_body.json');
        self::assertFalse($this->verifier->verify(Fixtures::publicKey(), $other->sign($body), $body));
    }

    public function testUnparseableKeyThrows(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->verifier->verify('not a key', Fixtures::read('confirmed_body.signature'), 'x');
    }

    public function testKeyShorterThan2048BitsThrows(): void
    {
        $short = KeyPair::generate(1024);
        $this->expectException(InvalidKeyException::class);
        $this->verifier->verify($short->publicPem, $short->sign('x'), 'x');
    }

    public function testNonRsaKeyThrows(): void
    {
        $curve = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($curve)['key'];
        $this->expectException(InvalidKeyException::class);
        $this->verifier->verify($pem, 'AAAA', 'x');
    }
}
