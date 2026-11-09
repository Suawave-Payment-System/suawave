<?php

declare(strict_types=1);

namespace Suawave\Payment\Observer;

use Suawave\Payment\Core\ExpectedPayment;
use Suawave\Payment\Model\AttemptOpener;
use Suawave\Payment\Model\Config;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CreatePaymentAddress implements ObserverInterface
{
    public function __construct(
        private readonly AttemptOpener $opener
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Config::METHOD_CODE) {
            return;
        }
        foreach (ExpectedPayment::attemptInfo($this->opener->open($order)) as $key => $value) {
            $payment->setAdditionalInformation($key, $value);
        }
    }
}
