<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class GatewayClient
{
    private const QR_PREFIX = 'data:image/png;base64,';

    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly HttpTransport $transport
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function createAddress(string $ticker, string $payoutAddress, string $callbackUrl): array
    {
        $data = $this->call(
            $this->tickerPath($ticker, 'create'),
            ['address' => $payoutAddress, 'callback' => $callbackUrl, 'json' => '1']
        );
        $address = $data['address_in'] ?? null;
        $minimum = $data['minimum_transaction_coin'] ?? null;
        if (!is_string($address) || $address === '' || !is_string($minimum) || !Decimal::isValid($minimum)) {
            throw new GatewayException('gateway returned an unusable address');
        }
        return ['address_in' => $address, 'minimum_transaction_coin' => $minimum];
    }

    public function convertToTicker(string $ticker, string $fiatAmount, string $fiatCurrency): string
    {
        $data = $this->call(
            $this->tickerPath($ticker, 'convert'),
            ['value' => Decimal::assertValid($fiatAmount), 'from' => strtolower($fiatCurrency)]
        );
        $value = $data['value_coin'] ?? null;
        if (!is_string($value) || !Decimal::isValid($value) || !Decimal::isPositive($value)) {
            throw new GatewayException('gateway returned an unusable conversion');
        }
        return $value;
    }

    public function qrCode(string $ticker, string $address, string $amount): string
    {
        $data = $this->call(
            $this->tickerPath($ticker, 'qrcode'),
            ['address' => $address, 'value' => Decimal::assertValid($amount), 'size' => '256']
        );
        $image = $data['qr_code'] ?? null;
        if (!is_string($image)
            || !str_starts_with($image, self::QR_PREFIX)
            || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', substr($image, strlen(self::QR_PREFIX))) !== 1
        ) {
            throw new GatewayException('gateway returned an unusable QR code');
        }
        return $image;
    }

    public function publicKey(): string
    {
        $data = $this->call('/pubkey/', []);
        $key = $data['pubkey'] ?? null;
        if (!is_string($key) || !str_contains($key, 'BEGIN PUBLIC KEY')) {
            throw new GatewayException('gateway returned an unusable public key');
        }
        return $key;
    }

    private function tickerPath(string $ticker, string $operation): string
    {
        if (preg_match('/^[a-z0-9]+\/[a-z0-9]+$/D', $ticker) !== 1) {
            throw new GatewayException('ticker must look like trc20/usdt');
        }
        return '/' . $ticker . '/' . $operation . '/';
    }

    private function call(string $path, array $query): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $response = $this->transport->get($url);
        $decoded = json_decode($response->body, true);
        if ($response->status !== 200 || !is_array($decoded) || ($decoded['status'] ?? null) !== 'success') {
            throw new GatewayException($this->failureMessage($response, $decoded));
        }
        return $decoded;
    }

    private function failureMessage(HttpResponse $response, mixed $decoded): string
    {
        $detail = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : 'unexpected response';
        return 'gateway answered ' . $response->status . ': ' . substr($detail, 0, 200);
    }
}
