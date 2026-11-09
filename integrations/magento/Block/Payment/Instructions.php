<?php

declare(strict_types=1);

namespace Suawave\Payment\Block\Payment;

use Suawave\Payment\Core\ExpectedPayment;
use Suawave\Payment\Core\GatewayException;
use Suawave\Payment\Core\PaymentView;
use Suawave\Payment\Model\Config;
use Suawave\Payment\Model\DeadlineFormatter;
use Suawave\Payment\Model\ExpiryNoter;
use Suawave\Payment\Model\OrderResolver;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Instructions extends Template
{
    public function __construct(
        Context $context,
        private readonly OrderResolver $orderResolver,
        private readonly Config $config,
        private readonly DeadlineFormatter $formatter,
        private readonly ExpiryNoter $noter,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function details(): ?array
    {
        $order = $this->orderResolver->resolve($this->getRequest());
        if ($order === null) {
            return null;
        }
        $info = $order->getPayment()->getAdditionalInformation();
        $reference = (string) $order->getIncrementId();
        $payment = ExpectedPayment::fromInfo($reference, is_array($info) ? $info : []);
        if ($payment === null) {
            return null;
        }
        $now = time();
        $storeId = (int) $order->getStoreId();
        if (PaymentView::state($payment, $now) === PaymentView::CLOSED) {
            $this->noter->noteIfDue($order, $now);
        }
        $details = PaymentView::details(
            $payment,
            $reference,
            $now,
            fn (int $expiresAt): string => $this->formatter->format($expiresAt, $storeId),
            fn (string $ticker, string $address, string $amount): ?string => $this->qrCode($ticker, $address, $amount, $storeId)
        );
        $details['renew_url'] = $this->getUrl('suawave/payment/renew');
        $details['order_entity'] = (string) (int) $this->getRequest()->getParam('order_id');
        return $details;
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
