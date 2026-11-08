<?php

declare(strict_types=1);

namespace Suawave;

final class GatewayClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly HttpTransport $transport
    ) {
    }

    public function createAddress(string $ticker, string $payoutAddress, string $callbackUrl): CreatedAddress
    {
        $query = http_build_query([
            'callback' => $callbackUrl,
            'address' => $payoutAddress,
            'pending' => '1',
        ], '', '&', PHP_QUERY_RFC3986);
        $data = $this->call(rtrim($this->baseUrl, '/') . '/' . trim($ticker, '/') . '/create/?' . $query);
        foreach (['address_in', 'address_out', 'minimum_transaction_coin'] as $name) {
            if (!isset($data[$name]) || !is_string($data[$name]) || $data[$name] === '') {
                throw new GatewayException('the gateway answer misses ' . $name);
            }
        }
        if (!Decimal::isValid($data['minimum_transaction_coin'])) {
            throw new GatewayException('the gateway answer has an invalid minimum');
        }
        return new CreatedAddress($data['address_in'], $data['address_out'], $data['minimum_transaction_coin']);
    }

    public function qrCode(string $ticker, string $addressIn, string $amount): string
    {
        if (!Decimal::isPositive($amount)) {
            throw new GatewayException('the QR amount is not a positive decimal');
        }
        $query = http_build_query([
            'address' => $addressIn,
            'value' => $amount,
            'size' => '256',
        ], '', '&', PHP_QUERY_RFC3986);
        $data = $this->call(rtrim($this->baseUrl, '/') . '/' . trim($ticker, '/') . '/qrcode/?' . $query);
        $image = $data['qr_code'] ?? null;
        $prefix = 'data:image/png;base64,';
        if (!is_string($image)
            || !str_starts_with($image, $prefix)
            || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', substr($image, strlen($prefix))) !== 1
        ) {
            throw new GatewayException('the gateway answer has no usable QR code');
        }
        return $image;
    }

    public function publicKey(): string
    {
        $data = $this->call(rtrim($this->baseUrl, '/') . '/pubkey/');
        $key = $data['pubkey'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new GatewayException('the gateway answer misses pubkey');
        }
        return $key;
    }

    private function call(string $url): array
    {
        $response = $this->transport->get($url);
        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new GatewayException('the gateway answered with something that is not JSON (HTTP ' . $response->status . ')');
        }
        if ($response->status !== 200 || ($data['status'] ?? null) !== 'success') {
            $message = isset($data['error']) && is_string($data['error']) ? $data['error'] : 'unknown error';
            throw new GatewayException('the gateway refused the request (HTTP ' . $response->status . '): ' . $message);
        }
        return $data;
    }
}
