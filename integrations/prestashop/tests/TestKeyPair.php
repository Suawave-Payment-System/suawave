<?php

declare(strict_types=1);

namespace Suawave\Tests;

use OpenSSLAsymmetricKey;

final class TestKeyPair
{
    private function __construct(
        private readonly OpenSSLAsymmetricKey $private,
        public readonly string $publicPem
    ) {
    }

    public static function generate(int $bits = 2048, int $type = OPENSSL_KEYTYPE_RSA): self
    {
        $private = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => $type]);
        $details = openssl_pkey_get_details($private);
        return new self($private, $details['key']);
    }

    public function sign(string $message): string
    {
        openssl_sign($message, $signature, $this->private, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }
}
