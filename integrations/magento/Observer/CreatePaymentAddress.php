<?php

declare(strict_types=1);

namespace Suawave\Payment\Observer;

use Suawave\Payment\Core\Decimal;
use Suawave\Payment\Core\GatewayException;
use Suawave\Payment\Model\Config;
use Suawave\Payment\Model\OrderPaymentLedger;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class CreatePaymentAddress implements ObserverInterface
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Config::METHOD_CODE) {
            return;
        }
        $storeId = (int) $order->getStoreId();
        $reference = (string) $order->getIncrementId();
        $ticker = $this->config->ticker($storeId);
        $nonce = bin2hex(random_bytes(16));

        try {
            $client = $this->config->client($storeId);
            $fiat = Decimal::twoPlaces(Decimal::fromStorage($order->getGrandTotal()));
            $expected = $client->convertToTicker($ticker, $fiat, (string) $order->getOrderCurrencyCode());
            $callback = $this->config->callbackBaseUrl($storeId)
                . '?' . http_build_query(['order' => $reference, 'nonce' => $nonce], '', '&', PHP_QUERY_RFC3986);
            $created = $client->createAddress($ticker, $this->config->value('payout_address', $storeId), $callback);
            if (!Decimal::atLeast($expected, $created['minimum_transaction_coin'])) {
                throw new LocalizedException(new Phrase(
                    'This order total is below the minimum amount accepted for crypto payments.'
                ));
            }
        } catch (GatewayException | \InvalidArgumentException $failure) {
            throw new LocalizedException(new Phrase(
                'Suawave payments are temporarily unavailable. Please choose another payment method.'
            ));
        }

        $payment->setAdditionalInformation(OrderPaymentLedger::NONCE, $nonce);
        $payment->setAdditionalInformation(OrderPaymentLedger::ADDRESS, $created['address_in']);
        $payment->setAdditionalInformation(OrderPaymentLedger::TICKER, $ticker);
        $payment->setAdditionalInformation(OrderPaymentLedger::EXPECTED, $expected);
        $payment->setAdditionalInformation(OrderPaymentLedger::RECEIVED, '0');
        $payment->setAdditionalInformation(OrderPaymentLedger::PAID, '0');
    }
}
