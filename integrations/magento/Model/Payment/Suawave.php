<?php

declare(strict_types=1);

namespace Suawave\Payment\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;

class Suawave extends AbstractMethod
{
    protected $_code = 'suawave';
    protected $_isOffline = true;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
}
