<?php

declare(strict_types=1);

namespace Suawave\Payment\Model;

use Suawave\Payment\Core\BelowMinimumException;
use Suawave\Payment\Core\Checkout;
use Suawave\Payment\Core\Decimal;
use Suawave\Payment\Core\GatewayException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;

class AttemptOpener
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function open(Order $order): array
    {
        $storeId = (int) $order->getStoreId();
        $reference = (string) $order->getIncrementId();
        try {
            return Checkout::open(
                $this->config->client($storeId),
                $this->config->ticker($storeId),
                $this->config->value('payout_address', $storeId),
                Decimal::twoPlaces(Decimal::fromStorage($order->getGrandTotal())),
                (string) $order->getOrderCurrencyCode(),
                fn (string $nonce): string => $this->config->callbackBaseUrl($storeId)
                    . '?' . http_build_query(['order' => $reference, 'nonce' => $nonce], '', '&', PHP_QUERY_RFC3986)
            );
        } catch (BelowMinimumException $failure) {
            throw new LocalizedException(new Phrase(
                'This order total is below the minimum amount accepted for crypto payments.'
            ));
        } catch (GatewayException | \InvalidArgumentException $failure) {
            throw new LocalizedException(new Phrase(
                'Suawave payments are temporarily unavailable. Please choose another payment method.'
            ));
        }
    }
}
