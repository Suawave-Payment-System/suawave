<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

final class KeyPair
{
    private function __construct(
        public readonly string $publicPem,
        private readonly \OpenSSLAsymmetricKey $private
    ) {
    }

    public static function generate(int $bits): self
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        $details = openssl_pkey_get_details($private);
        return new self($details['key'], $private);
    }

    public function sign(string $bytes): string
    {
        openssl_sign($bytes, $signature, $this->private, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }
}
