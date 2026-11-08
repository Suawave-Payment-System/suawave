<?php

declare(strict_types=1);

namespace Suawave\Payment\Model;

use Suawave\Payment\Core\ExpectedPayment;
use Suawave\Payment\Core\PaymentLedger;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;

class OrderPaymentLedger implements PaymentLedger
{
    public const NONCE = 'suawave_nonce';
    public const ADDRESS = 'suawave_address';
    public const TICKER = 'suawave_ticker';
    public const EXPECTED = 'suawave_expected_amount';
    public const RECEIVED = 'suawave_received_amount';
    public const PAID = 'suawave_paid';

    public function __construct(
        private readonly OrderFactory $orderFactory,
        private readonly OrderRepositoryInterface $orders,
        private readonly InvoiceService $invoices,
        private readonly TransactionFactory $transactions
    ) {
    }

    public function find(string $orderReference): ?ExpectedPayment
    {
        $order = $this->load($orderReference);
        if ($order === null) {
            return null;
        }
        $info = $order->getPayment()->getAdditionalInformation();
        foreach ([self::NONCE, self::ADDRESS, self::TICKER, self::EXPECTED, self::RECEIVED] as $field) {
            if (!isset($info[$field]) || !is_string($info[$field])) {
                return null;
            }
        }
        return new ExpectedPayment(
            $orderReference,
            $info[self::NONCE],
            $info[self::ADDRESS],
            $info[self::TICKER],
            $info[self::EXPECTED],
            $info[self::RECEIVED],
            ($info[self::PAID] ?? '0') === '1'
        );
    }

    public function applyDeposit(
        string $orderReference,
        string $eventUuid,
        string $depositTransaction,
        string $depositAmount,
        string $newReceivedTotal,
        bool $fullyPaid
    ): void {
        $order = $this->load($orderReference);
        if ($order === null) {
            throw new \RuntimeException('order disappeared while processing the callback');
        }
        $payment = $order->getPayment();
        $wasPaid = ($payment->getAdditionalInformation(self::PAID) ?? '0') === '1';
        $payment->setAdditionalInformation(self::RECEIVED, $newReceivedTotal);
        $comment = sprintf(
            'Confirmed crypto deposit %s of %s (event %s). Received in total: %s.',
            $depositTransaction,
            $depositAmount,
            $eventUuid,
            $newReceivedTotal
        );
        if ($fullyPaid && !$wasPaid) {
            $payment->setAdditionalInformation(self::PAID, '1');
            $this->invoice($order);
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
            $order->addCommentToStatusHistory($comment);
            $this->orders->save($order);
            return;
        }
        $order->addCommentToStatusHistory($comment);
        $this->orders->save($order);
    }

    private function load(string $orderReference): ?Order
    {
        $order = $this->orderFactory->create()->loadByIncrementId($orderReference);
        if (!$order->getId()) {
            return null;
        }
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Config::METHOD_CODE) {
            return null;
        }
        return $order;
    }

    private function invoice(Order $order): bool
    {
        if (!$order->canInvoice()) {
            return false;
        }
        $invoice = $this->invoices->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $this->transactions->create()
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();
        return true;
    }
}
