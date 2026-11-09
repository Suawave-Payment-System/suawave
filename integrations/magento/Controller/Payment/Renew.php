<?php

declare(strict_types=1);

namespace Suawave\Payment\Controller\Payment;

use Suawave\Payment\Core\PaymentAttempts;
use Suawave\Payment\Model\AttemptOpener;
use Suawave\Payment\Model\ExpiryNoter;
use Suawave\Payment\Model\OrderResolver;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;

class Renew implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirects,
        private readonly OrderResolver $resolver,
        private readonly AttemptOpener $opener,
        private readonly PaymentAttempts $attempts,
        private readonly ExpiryNoter $noter,
        private readonly ManagerInterface $messages
    ) {
    }

    public function execute(): ResultInterface
    {
        $order = $this->resolver->resolve($this->request);
        if ($order === null) {
            return $this->redirects->create()->setPath('checkout/cart');
        }
        try {
            $outcome = $this->attempts->renew(
                (string) $order->getIncrementId(),
                fn (): array => $this->opener->open($order),
                time(),
                $this->noter->noteFor($order)
            );
            if ($outcome === PaymentAttempts::RENEWED) {
                $this->messages->addSuccessMessage((string) __('A new payment address was created for your order.'));
            }
        } catch (LocalizedException $failure) {
            $this->messages->addErrorMessage($failure->getMessage());
        }
        $entityId = (int) $this->request->getParam('order_id');
        return $this->redirects->create()->setPath('suawave/payment/index', $entityId > 0 ? ['order_id' => $entityId] : []);
    }
}
