<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\CallbackRequest;
use Suawave\InvalidPublicKey;
use Suawave\SignatureVerifier;
use Suawave\Verdict;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private static array $vector;

    public static function setUpBeforeClass(): void
    {
        self::$vector = json_decode((string) file_get_contents(__DIR__ . '/vector.json'), true);
    }

    public function testKnownGoodGetVector(): void
    {
        $request = new CallbackRequest('GET', self::$vector['get_request_uri'], '', '', self::$vector['get_signature']);
        $verifier = new SignatureVerifier(self::$vector['public_key']);

        $verdict = $verifier->verify($request->signedMessage(self::$vector['external_origin']), $request->signatureHeader);

        $this->assertSame(Verdict::Valid, $verdict);
    }

    public function testKnownGoodJsonPostVector(): void
    {
        $request = new CallbackRequest('POST', '/hook', 'application/json', self::$vector['post_json_body'], self::$vector['post_json_signature']);
        $verifier = new SignatureVerifier(self::$vector['public_key']);

        $verdict = $verifier->verify($request->signedMessage(self::$vector['external_origin']), $request->signatureHeader);

        $this->assertSame(Verdict::Valid, $verdict);
    }

    public function testMissingHeader(): void
    {
        $verifier = new SignatureVerifier(self::$vector['public_key']);

        $this->assertSame(Verdict::MissingSignature, $verifier->verify('message', null));
        $this->assertSame(Verdict::MissingSignature, $verifier->verify('message', '  '));
    }

    public function testMalformedBase64(): void
    {
        $verifier = new SignatureVerifier(self::$vector['public_key']);

        $this->assertSame(Verdict::MalformedSignature, $verifier->verify('message', '***not base64***'));
    }

    public function testBadSignature(): void
    {
        $verifier = new SignatureVerifier(self::$vector['public_key']);
        $bad = base64_encode(random_bytes(256));

        $this->assertSame(Verdict::InvalidSignature, $verifier->verify('message', $bad));
    }

    public function testSignatureFromAnotherKey(): void
    {
        $other = TestKeyPair::generate();
        $verifier = new SignatureVerifier(self::$vector['public_key']);

        $this->assertSame(Verdict::InvalidSignature, $verifier->verify('message', $other->sign('message')));
    }

    public function testSignatureOverDifferentBytesForPost(): void
    {
        $pair = TestKeyPair::generate();
        $signed = '{"uuid":"u","value_coin":"100.000000"}';
        $reserialized = '{"uuid": "u", "value_coin": "100.000000"}';
        $request = new CallbackRequest('POST', '/hook', 'application/json', $reserialized, $pair->sign($signed));

        $verdict = (new SignatureVerifier($pair->publicPem))->verify($request->signedMessage('https://shop.example'), $request->signatureHeader);

        $this->assertSame(Verdict::InvalidSignature, $verdict);
    }

    public function testReorderedQueryChangesTheSignedUrl(): void
    {
        $uri = self::$vector['get_request_uri'];
        [$path, $query] = explode('?', $uri, 2);
        $parts = explode('&', $query);
        $reordered = $path . '?' . implode('&', array_reverse($parts));
        $request = new CallbackRequest('GET', $reordered, '', '', self::$vector['get_signature']);

        $verdict = (new SignatureVerifier(self::$vector['public_key']))->verify($request->signedMessage(self::$vector['external_origin']), $request->signatureHeader);

        $this->assertSame(Verdict::InvalidSignature, $verdict);
    }

    public function testWrongConfiguredOriginFails(): void
    {
        $request = new CallbackRequest('GET', self::$vector['get_request_uri'], '', '', self::$vector['get_signature']);

        $verdict = (new SignatureVerifier(self::$vector['public_key']))->verify($request->signedMessage('https://evil.example'), $request->signatureHeader);

        $this->assertSame(Verdict::InvalidSignature, $verdict);
    }

    public function testUnparseableKey(): void
    {
        $this->expectException(InvalidPublicKey::class);

        new SignatureVerifier('-----BEGIN PUBLIC KEY-----garbage-----END PUBLIC KEY-----');
    }

    public function testTooShortKey(): void
    {
        $short = TestKeyPair::generate(1024);

        $this->expectException(InvalidPublicKey::class);

        new SignatureVerifier($short->publicPem);
    }

    public function testNonRsaKey(): void
    {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($ec)['key'];

        $this->expectException(InvalidPublicKey::class);

        new SignatureVerifier($pem);
    }
}
