<?php

declare(strict_types=1);

namespace Suawave\Payment\Block\Payment;

use Suawave\Payment\Core\GatewayException;
use Suawave\Payment\Model\Config;
use Suawave\Payment\Model\OrderPaymentLedger;
use Magento\Checkout\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Instructions extends Template
{
    public function __construct(
        Context $context,
        private readonly Session $checkoutSession,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function details(): ?array
    {
        $order = $this->checkoutSession->getLastRealOrder();
        $payment = $order->getId() ? $order->getPayment() : null;
        if ($payment === null || $payment->getMethod() !== Config::METHOD_CODE) {
            return null;
        }
        $info = $payment->getAdditionalInformation();
        $address = $info[OrderPaymentLedger::ADDRESS] ?? null;
        $amount = $info[OrderPaymentLedger::EXPECTED] ?? null;
        $ticker = $info[OrderPaymentLedger::TICKER] ?? null;
        if (!is_string($address) || !is_string($amount) || !is_string($ticker)) {
            return null;
        }
        return [
            'address' => $address,
            'amount' => $amount,
            'ticker' => $ticker,
            'qr' => $this->qrCode($ticker, $address, $amount, (int) $order->getStoreId()),
        ];
    }

    private function qrCode(string $ticker, string $address, string $amount, int $storeId): ?string
    {
        try {
            return $this->config->client($storeId)->qrCode($ticker, $address, $amount);
        } catch (GatewayException | \InvalidArgumentException $failure) {
            return null;
        }
    }
}
