<?php

declare(strict_types=1);

namespace Suawave\Payment\Model;

use Suawave\Payment\Core\PaymentAttempts;
use Magento\Sales\Model\Order;

class ExpiryNoter
{
    public function __construct(
        private readonly PaymentAttempts $attempts,
        private readonly DeadlineFormatter $formatter
    ) {
    }

    public function noteIfDue(Order $order, int $now): bool
    {
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Config::METHOD_CODE) {
            return false;
        }
        return $this->attempts->noteExpiry((string) $order->getIncrementId(), $now, $this->noteFor($order));
    }

    public function noteFor(Order $order): callable
    {
        $storeId = (int) $order->getStoreId();
        return fn (int $expiresAt): string => (string) __(
            'The Suawave payment window closed on %1 without a confirmed payment. The deposit address is no longer shown to the customer.',
            $this->formatter->format($expiresAt, $storeId)
        );
    }
}
