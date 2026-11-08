<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class CallbackVerifier
{
    public const MAX_BODY_BYTES = 65536;
    private const MIN_KEY_BITS = 2048;

    public static function isUsableKey(string $publicKeyPem): bool
    {
        $key = @openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            return false;
        }
        $details = openssl_pkey_get_details($key);
        return is_array($details)
            && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && ($details['bits'] ?? 0) >= self::MIN_KEY_BITS;
    }

    public static function verify(
        string $method,
        string $requestUri,
        string $rawBody,
        string $signatureBase64,
        string $publicKeyPem,
        string $trustedOrigin
    ): bool {
        if ($signatureBase64 === '' || !self::isUsableKey($publicKeyPem)) {
            return false;
        }
        $signed = self::signedBytes($method, $requestUri, $rawBody, $trustedOrigin);
        if ($signed === null) {
            return false;
        }
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || $signature === '') {
            return false;
        }
        return openssl_verify($signed, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function signedBytes(string $method, string $requestUri, string $rawBody, string $trustedOrigin): ?string
    {
        if ($method === 'GET') {
            if ($trustedOrigin === '' || $requestUri === '' || $requestUri[0] !== '/') {
                return null;
            }
            return rtrim($trustedOrigin, '/') . $requestUri;
        }
        if ($method === 'POST') {
            if (strlen($rawBody) > self::MAX_BODY_BYTES) {
                return null;
            }
            return $rawBody;
        }
        return null;
    }
}
