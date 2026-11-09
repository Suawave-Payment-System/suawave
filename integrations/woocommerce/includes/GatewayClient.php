<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class GatewayClient
{
    private $transport;

    public function __construct(private string $baseUrl, callable $transport)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport;
    }

    public static function isValidTicker(string $ticker): bool
    {
        return preg_match('#^[a-z0-9]+/[a-z0-9]+$#', $ticker) === 1;
    }

    public function createAddress(string $ticker, string $payoutAddress, string $callbackUrl): array
    {
        $body = $this->get($ticker, 'create', [
            'address' => $payoutAddress,
            'callback' => $callbackUrl,
            'pending' => '1',
        ]);
        foreach (['address_in', 'minimum_transaction_coin'] as $field) {
            if (!isset($body[$field]) || !is_string($body[$field]) || $body[$field] === '') {
                throw new GatewayException('gateway answer is missing address_in or minimum_transaction_coin');
            }
        }
        if (!Money::isDecimal($body['minimum_transaction_coin'])) {
            throw new GatewayException('gateway answered an invalid minimum');
        }
        return [
            'address_in' => $body['address_in'],
            'minimum' => $body['minimum_transaction_coin'],
            'expires_at' => PaymentWindow::parse($body['expires_at'] ?? null),
        ];
    }

    public function convert(string $ticker, string $value, string $fromCurrency): string
    {
        $body = $this->get($ticker, 'convert', ['value' => $value, 'from' => strtolower($fromCurrency)]);
        $converted = $body['value_coin'] ?? '';
        if (!is_string($converted) || !Money::isPositive($converted)) {
            throw new GatewayException('gateway answered an invalid converted amount');
        }
        return $converted;
    }

    public function qrCode(string $ticker, string $address, string $value): ?string
    {
        try {
            $body = $this->get($ticker, 'qrcode', ['address' => $address, 'value' => $value, 'size' => '256']);
        } catch (GatewayException $failure) {
            return null;
        }
        $image = $body['qr_code'] ?? '';
        if (!is_string($image) || preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $image) !== 1) {
            return null;
        }
        return $image;
    }

    private function get(string $ticker, string $action, array $query): array
    {
        if (!self::isValidTicker($ticker)) {
            throw new GatewayException('invalid ticker');
        }
        $url = $this->baseUrl . '/' . $ticker . '/' . $action . '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        [$status, $raw] = ($this->transport)($url);
        $body = json_decode($raw, true, 8);
        if (!is_array($body)) {
            throw new GatewayException('gateway answered with an unreadable body (HTTP ' . (int) $status . ')');
        }
        if ($status !== 200 || ($body['status'] ?? '') !== 'success') {
            $message = is_string($body['error'] ?? null) ? $body['error'] : 'HTTP ' . $status;
            throw new GatewayException(esc_html($message));
        }
        return $body;
    }
}
