<?php

declare(strict_types=1);

namespace Suawave\Payment\Block\Info;

use Suawave\Payment\Core\ExpectedPayment;
use Suawave\Payment\Core\PaymentView;
use Suawave\Payment\Model\DeadlineFormatter;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Block\Info;

class Suawave extends Info
{
    protected $_template = 'Suawave_Payment::info/suawave.phtml';

    public function __construct(
        Context $context,
        private readonly DeadlineFormatter $formatter,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $info = $this->getInfo();
        $order = $info->getOrder();
        $additional = $info->getAdditionalInformation();
        if ($order === null || !is_array($additional)) {
            return $transport;
        }
        $reference = (string) $order->getIncrementId();
        $payment = ExpectedPayment::fromInfo($reference, $additional);
        if ($payment === null) {
            return $transport;
        }
        $storeId = (int) $order->getStoreId();
        return new DataObject(PaymentView::infoLines(
            $payment,
            $reference,
            time(),
            fn (int $expiresAt): string => $this->formatter->format($expiresAt, $storeId),
            fn (string $text): string => (string) __($text)
        ));
    }
}
