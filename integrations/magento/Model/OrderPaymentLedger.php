<?php

declare(strict_types=1);

namespace Suawave\Payment\Model;

use Suawave\Payment\Core\ExpectedPayment;
use Suawave\Payment\Core\AttemptLedger;
use Suawave\Payment\Core\Decimal;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;

class OrderPaymentLedger implements AttemptLedger
{
    public const NONCE = ExpectedPayment::NONCE;
    public const ADDRESS = ExpectedPayment::ADDRESS;
    public const TICKER = ExpectedPayment::TICKER;
    public const EXPECTED = ExpectedPayment::EXPECTED;
    public const RECEIVED = ExpectedPayment::RECEIVED;
    public const PAID = ExpectedPayment::PAID;
    public const EXPIRES_AT = ExpectedPayment::EXPIRES_AT;
    public const EXPIRY_NOTED = ExpectedPayment::EXPIRY_NOTED;
    public const PREVIOUS = ExpectedPayment::PREVIOUS;
    public const PAID_BY = ExpectedPayment::PAID_BY;
    public const RECONCILE_NOTE = 'Suawave payment received on a previous attempt, reconcile: deposit %s of %s (event %s) arrived after the order was paid. The order status was not changed.';

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
        return ExpectedPayment::fromInfo($orderReference, is_array($info) ? $info : []);
    }

    public function replaceAttempt(string $orderReference, array $opened): void
    {
        $order = $this->loadOrFail($orderReference);
        $payment = $order->getPayment();
        $info = $payment->getAdditionalInformation();
        foreach (ExpectedPayment::supersededInfo(is_array($info) ? $info : [], $opened) as $key => $value) {
            $payment->setAdditionalInformation($key, $value);
        }
        $order->addCommentToStatusHistory(
            'The customer renewed the Suawave payment: a new deposit address replaces the expired one. A confirmed deposit to the old address is still recorded and pays this order while it is unpaid.'
        );
        $this->orders->save($order);
    }

    public function recordExpiry(string $orderReference, string $nonce, string $note): void
    {
        $order = $this->loadOrFail($orderReference);
        $order->getPayment()->setAdditionalInformation(self::EXPIRY_NOTED, $nonce);
        $order->addCommentToStatusHistory($note);
        $this->orders->save($order);
    }

    public function noteReconcile(
        string $orderReference,
        string $eventUuid,
        string $depositTransaction,
        string $depositAmount
    ): void {
        $order = $this->loadOrFail($orderReference);
        $order->addCommentToStatusHistory(sprintf(self::RECONCILE_NOTE, $depositTransaction, Decimal::plain($depositAmount), $eventUuid));
        $this->orders->save($order);
    }

    public function applyDeposit(
        string $orderReference,
        string $eventUuid,
        string $depositTransaction,
        string $depositAmount,
        string $newReceivedTotal,
        bool $fullyPaid,
        ?int $previousAttempt = null
    ): void {
        $order = $this->loadOrFail($orderReference);
        $payment = $order->getPayment();
        $wasPaid = ($payment->getAdditionalInformation(self::PAID) ?? '0') === '1';
        $previous = $payment->getAdditionalInformation(self::PREVIOUS);
        if ($previousAttempt === null) {
            $payment->setAdditionalInformation(self::RECEIVED, $newReceivedTotal);
            $paying = [
                'nonce' => (string) $payment->getAdditionalInformation(self::NONCE),
                'address' => (string) $payment->getAdditionalInformation(self::ADDRESS),
                'attempt' => 'current',
            ];
        } else {
            if (!is_array($previous) || !isset($previous[$previousAttempt]) || !is_array($previous[$previousAttempt])) {
                throw new \RuntimeException('previous payment attempt disappeared while it was locked');
            }
            $previous[$previousAttempt]['received'] = $newReceivedTotal;
            $payment->setAdditionalInformation(self::PREVIOUS, $previous);
            $paying = [
                'nonce' => (string) $previous[$previousAttempt]['nonce'],
                'address' => (string) $previous[$previousAttempt]['address'],
                'attempt' => 'previous',
            ];
        }
        $comment = sprintf(
            'Confirmed crypto deposit %s of %s (event %s) to %s. Received on that address in total: %s.',
            $depositTransaction,
            Decimal::plain($depositAmount),
            $eventUuid,
            $paying['address'],
            Decimal::plain($newReceivedTotal)
        );
        if ($fullyPaid && !$wasPaid) {
            $payment->setAdditionalInformation(self::PAID, '1');
            $payment->setAdditionalInformation(self::PAID_BY, $paying);
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

    private function loadOrFail(string $orderReference): Order
    {
        $order = $this->load($orderReference);
        if ($order === null) {
            throw new \RuntimeException('order disappeared while it was locked');
        }
        return $order;
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
