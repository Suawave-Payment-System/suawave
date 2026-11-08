<?php

declare(strict_types=1);

namespace Suawave\Payment\Model;

use Suawave\Payment\Core\PublicKeyProvider;
use Magento\Framework\App\CacheInterface;

class CachedPublicKeyProvider implements PublicKeyProvider
{
    private const CACHE_ID = 'suawave_gateway_callback_public_key';
    private const LIFETIME_SECONDS = 86400;

    public function __construct(
        private readonly Config $config,
        private readonly CacheInterface $cache
    ) {
    }

    public function current(): string
    {
        $cached = $this->cache->load(self::CACHE_ID);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        return $this->refresh();
    }

    public function refresh(): string
    {
        $key = $this->config->client()->publicKey();
        $this->cache->save($key, self::CACHE_ID, [], self::LIFETIME_SECONDS);
        return $key;
    }
}
