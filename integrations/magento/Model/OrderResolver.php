<?php

declare(strict_types=1);

namespace Suawave\Payment\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class OrderResolver
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly OrderRepositoryInterface $orders
    ) {
    }

    public function resolve(RequestInterface $request): ?Order
    {
        $entityId = (int) $request->getParam('order_id');
        if ($entityId > 0) {
            return $this->customerOrder($entityId);
        }
        $order = $this->checkoutSession->getLastRealOrder();
        return $order->getId() && $this->isSuawave($order) ? $order : null;
    }

    private function customerOrder(int $entityId): ?Order
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }
        try {
            $order = $this->orders->get($entityId);
        } catch (NoSuchEntityException $missing) {
            return null;
        }
        if (!$order instanceof Order
            || (int) $order->getCustomerId() === 0
            || (int) $order->getCustomerId() !== (int) $this->customerSession->getCustomerId()
            || !$this->isSuawave($order)
        ) {
            return null;
        }
        return $order;
    }

    private function isSuawave(Order $order): bool
    {
        $payment = $order->getPayment();
        return $payment !== null && $payment->getMethod() === Config::METHOD_CODE;
    }
}
