<?php

declare(strict_types=1);

namespace Suawave\Payment\Controller\Payment;

use Suawave\Payment\Model\OrderResolver;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $pages,
        private readonly RedirectFactory $redirects,
        private readonly OrderResolver $resolver
    ) {
    }

    public function execute(): ResultInterface
    {
        if ($this->resolver->resolve($this->request) === null) {
            return $this->redirects->create()->setPath('checkout/cart');
        }
        $page = $this->pages->create();
        $page->getConfig()->getTitle()->set((string) __('Crypto payment'));
        return $page;
    }
}
