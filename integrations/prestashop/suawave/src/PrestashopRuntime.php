<?php

declare(strict_types=1);

namespace Suawave;

use Configuration;

final class PrestashopRuntime
{
    public const API_URL = 'SUAWAVE_API_URL';
    public const TICKER = 'SUAWAVE_TICKER';
    public const PAYOUT = 'SUAWAVE_PAYOUT';
    public const ORIGIN = 'SUAWAVE_ORIGIN';
    public const CURRENCY = 'SUAWAVE_CURRENCY';
    public const AWAITING_STATE = 'SUAWAVE_OS_AWAITING';

    public static function setting(string $name): string
    {
        $value = Configuration::get($name);
        return is_string($value) && $value !== '' ? $value : ($name === self::API_URL ? 'https://api.suawave.com' : '');
    }

    public static function isConfigured(): bool
    {
        foreach ([self::API_URL, self::TICKER, self::PAYOUT, self::ORIGIN, self::CURRENCY] as $name) {
            if (self::setting($name) === '') {
                return false;
            }
        }
        return true;
    }

    public static function client(): GatewayClient
    {
        return new GatewayClient(self::setting(self::API_URL), new CurlTransport());
    }

    public static function store(): SqlPaymentStore
    {
        return new SqlPaymentStore(new PrestashopConnection(), _DB_PREFIX_);
    }

    public static function callbackBaseUrl(): string
    {
        return rtrim(self::setting(self::ORIGIN), '/') . '/index.php?fc=module&module=suawave&controller=callback';
    }

    public static function checkout(): CheckoutService
    {
        return new CheckoutService(
            self::client(),
            self::store(),
            self::setting(self::TICKER),
            self::setting(self::PAYOUT),
            self::callbackBaseUrl()
        );
    }

    public static function processor(): CallbackProcessor
    {
        return new CallbackProcessor(
            new PrestashopPublicKeyProvider(self::client()),
            self::setting(self::ORIGIN),
            self::store(),
            new PrestashopOrderGateway(),
            new PrestashopFailureLog()
        );
    }
}
