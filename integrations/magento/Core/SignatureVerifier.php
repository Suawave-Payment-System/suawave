<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class SignatureVerifier
{
    private const MINIMUM_KEY_BITS = 2048;

    public function verify(string $publicKeyPem, string $signatureBase64, string $signedBytes): bool
    {
        $key = $this->loadKey($publicKeyPem);
        $signature = base64_decode(trim($signatureBase64), true);
        if ($signature === false || $signature === '') {
            return false;
        }
        return openssl_verify($signedBytes, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function loadKey(string $publicKeyPem): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new InvalidKeyException('callback public key cannot be parsed');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidKeyException('callback public key is not an RSA key');
        }
        if (($details['bits'] ?? 0) < self::MINIMUM_KEY_BITS) {
            throw new InvalidKeyException('callback public key is shorter than 2048 bits');
        }
        return $key;
    }
}
