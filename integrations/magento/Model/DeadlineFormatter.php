<?php

declare(strict_types=1);

namespace Suawave\Payment\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;

class DeadlineFormatter
{
    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function format(int $expiresAt, int $storeId): string
    {
        $zone = $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, $storeId);
        return $this->timezone->formatDateTime(
            new \DateTimeImmutable('@' . $expiresAt),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT,
            null,
            $zone
        ) . ' (' . $zone . ')';
    }
}
