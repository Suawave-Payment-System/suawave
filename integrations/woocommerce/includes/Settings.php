<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public const OPTION = 'woocommerce_suawave_settings';

    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    public static function get(string $name, string $default = ''): string
    {
        $value = self::all()[$name] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public static function trustedOrigin(): string
    {
        $configured = trim(self::get('external_origin'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $parts = wp_parse_url(home_url('/'));
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    public static function client(): GatewayClient
    {
        return new GatewayClient(self::get('api_base_url', 'https://api.suawave.com'), static function (string $url): array {
            $response = wp_remote_get($url, ['timeout' => 15, 'redirection' => 0, 'headers' => ['Accept' => 'application/json']]);
            if (is_wp_error($response)) {
                throw new GatewayException(esc_html($response->get_error_message()));
            }
            return [(int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response)];
        });
    }

    public static function callbackUrl(string $source, string $orderId, string $nonce): string
    {
        $base = home_url('/');
        $separator = strpos($base, '?') === false ? '?' : '&';
        return $base . $separator . http_build_query([
            'suawave_callback' => '1',
            'src' => $source,
            'order' => $orderId,
            'nonce' => $nonce,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
