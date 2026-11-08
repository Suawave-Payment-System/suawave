<?php

declare(strict_types=1);

namespace Suawave\Payment\Controller\Callback;

use Suawave\Payment\Core\CallbackProcessor;
use Suawave\Payment\Core\CallbackRequest;
use Suawave\Payment\Model\Config;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class Index implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly ResultFactory $resultFactory,
        private readonly CallbackProcessor $processor,
        private readonly Config $config
    ) {
    }

    public function execute(): ResultInterface
    {
        $signature = $this->request->getHeader('x-ca-signature');
        $outcome = $this->processor->handle(new CallbackRequest(
            (string) $this->request->getMethod(),
            $this->config->externalOrigin() . (string) $this->request->getRequestUri(),
            (string) $this->request->getContent(),
            (string) $this->request->getHeader('Content-Type'),
            is_string($signature) ? $signature : ''
        ));
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode($outcome->status);
        $result->setHeader('Content-Type', 'text/plain', true);
        $result->setContents($outcome->body);
        return $result;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
