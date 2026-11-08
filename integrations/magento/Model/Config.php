<?php

declare(strict_types=1);

namespace Suawave\Payment\Model;

use Suawave\Payment\Core\CurlTransport;
use Suawave\Payment\Core\GatewayClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const METHOD_CODE = 'suawave';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function value(string $field, ?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            'payment/' . self::METHOD_CODE . '/' . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function ticker(?int $storeId = null): string
    {
        return strtolower($this->value('ticker', $storeId));
    }

    public function callbackBaseUrl(?int $storeId = null): string
    {
        return rtrim($this->value('external_origin', $storeId), '/') . '/suawave/callback/index';
    }

    public function externalOrigin(?int $storeId = null): string
    {
        return rtrim($this->value('external_origin', $storeId), '/');
    }

    public function client(?int $storeId = null): GatewayClient
    {
        return new GatewayClient($this->value('api_base_url', $storeId), new CurlTransport());
    }
}
