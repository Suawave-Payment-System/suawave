<?php

declare(strict_types=1);

namespace Suawave;

use OpenSSLAsymmetricKey;

final class SignatureVerifier
{
    public const MINIMUM_KEY_BITS = 2048;

    private OpenSSLAsymmetricKey $key;

    public function __construct(string $publicKeyPem)
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new InvalidPublicKey('the callback public key cannot be parsed');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidPublicKey('the callback public key is not an RSA key');
        }
        if (($details['bits'] ?? 0) < self::MINIMUM_KEY_BITS) {
            throw new InvalidPublicKey('the callback public key is shorter than ' . self::MINIMUM_KEY_BITS . ' bits');
        }
        $this->key = $key;
    }

    public function verify(string $message, ?string $signatureHeader): Verdict
    {
        if ($signatureHeader === null || trim($signatureHeader) === '') {
            return Verdict::MissingSignature;
        }
        $signature = base64_decode(trim($signatureHeader), true);
        if ($signature === false || $signature === '') {
            return Verdict::MalformedSignature;
        }
        $result = openssl_verify($message, $signature, $this->key, OPENSSL_ALGO_SHA256);
        return $result === 1 ? Verdict::Valid : Verdict::InvalidSignature;
    }
}
