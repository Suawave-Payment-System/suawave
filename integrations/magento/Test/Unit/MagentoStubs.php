<?php

declare(strict_types=1);

namespace Magento\Sales\Api {
    if (!interface_exists(OrderRepositoryInterface::class, false)) {
        interface OrderRepositoryInterface
        {
            public function save($order);
        }
    }
}

namespace Magento\Sales\Model {
    if (!class_exists(Order::class, false)) {
        class Order
        {
            public const STATE_NEW = 'new';
            public const STATE_PROCESSING = 'processing';
            public const STATE_PENDING_PAYMENT = 'pending_payment';

            public $state = 'new';
            public $status = 'pending';
            public $comments = [];
            public $invoiceable = true;
            public $payment;
            public $saves = 0;
            public $id = 1;
            public $incrementId = '000000042';
            public $storeId = 1;
            public $customerId = 0;

            public function getCustomerId()
            {
                return $this->customerId;
            }

            public function getIncrementId()
            {
                return $this->incrementId;
            }

            public function getStoreId()
            {
                return $this->storeId;
            }

            public function loadByIncrementId($reference)
            {
                return $this;
            }

            public function getId()
            {
                return $this->id;
            }

            public function getPayment()
            {
                return $this->payment;
            }

            public function canInvoice(): bool
            {
                return $this->invoiceable;
            }

            public function setState($state)
            {
                $this->state = $state;
                return $this;
            }

            public function setStatus($status)
            {
                $this->status = $status;
                return $this;
            }

            public function getConfig()
            {
                return new class {
                    public function getStateDefaultStatus($state)
                    {
                        return $state === 'processing' ? 'processing' : 'pending';
                    }
                };
            }

            public function addCommentToStatusHistory($comment)
            {
                $this->comments[] = [$comment, $this->status];
                return $this;
            }
        }
    }

    if (!class_exists(OrderFactory::class, false)) {
        class OrderFactory
        {
            public $order;

            public function create()
            {
                return $this->order;
            }
        }
    }
}

namespace Magento\Sales\Model\Order {
    if (!class_exists(Invoice::class, false)) {
        class Invoice
        {
            public const CAPTURE_OFFLINE = 'offline';

            public $registered = false;

            public function setRequestedCaptureCase($case)
            {
            }

            public function register()
            {
                $this->registered = true;
            }

            public function getOrder()
            {
                return null;
            }
        }
    }
}

namespace Magento\Sales\Model\Service {
    if (!class_exists(InvoiceService::class, false)) {
        class InvoiceService
        {
            public $prepared = 0;

            public function prepareInvoice($order)
            {
                $this->prepared++;
                return new \Magento\Sales\Model\Order\Invoice();
            }
        }
    }
}

namespace Magento\Framework\DB {
    if (!class_exists(TransactionFactory::class, false)) {
        class TransactionFactory
        {
            public $saved = 0;

            public function create()
            {
                return new class($this) {
                    public function __construct(private $factory)
                    {
                    }

                    public function addObject($object)
                    {
                        return $this;
                    }

                    public function save()
                    {
                        $this->factory->saved++;
                    }
                };
            }
        }
    }
}

namespace Magento\Sales\Model\ResourceModel\Order {
    if (!class_exists(CollectionFactory::class, false)) {
        class CollectionFactory
        {
            public $pages = [];
            public $selects = [];

            public function create()
            {
                $select = new \Suawave\Payment\Test\Unit\RecordingSelect();
                $this->selects[] = $select;
                return new class($select, array_shift($this->pages) ?? []) implements \IteratorAggregate {
                    public function __construct(private $select, private array $orders)
                    {
                    }

                    public function getSelect()
                    {
                        return $this->select;
                    }

                    public function getTable($name)
                    {
                        return $name;
                    }

                    public function getIterator(): \Iterator
                    {
                        return new \ArrayIterator($this->orders);
                    }
                };
            }
        }
    }
}

namespace Magento\Framework {
    if (!class_exists(DataObject::class, false)) {
        class DataObject
        {
            protected $_data = [];
            protected static $_underscoreCache = [];

            public function __construct(array $data = [])
            {
                $this->_data = $data;
            }

            public function getData($key = null)
            {
                return $key === null ? $this->_data : ($this->_data[$key] ?? null);
            }

            public function setData($key, $value = null)
            {
                $this->_data[$key] = $value;
                return $this;
            }
        }
    }

    if (!class_exists(Escaper::class, false)) {
        class Escaper
        {
            public function escapeHtml($value): string
            {
                return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            public function escapeHtmlAttr($value): string
            {
                return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            public function escapeUrl($value): string
            {
                return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
    }
}

namespace Magento\Framework\App {
    if (!interface_exists(RequestInterface::class, false)) {
        interface RequestInterface
        {
            public function getParam($key, $defaultValue = null);
        }
    }
}

namespace Magento\Framework\App\Config {
    if (!interface_exists(ScopeConfigInterface::class, false)) {
        interface ScopeConfigInterface
        {
            public function getValue($path, $scopeType = 'default', $scopeCode = null);
        }
    }
}

namespace Magento\Store\Model {
    if (!interface_exists(ScopeInterface::class, false)) {
        interface ScopeInterface
        {
            public const SCOPE_STORE = 'store';
        }
    }
}

namespace Magento\Framework\Stdlib\DateTime {
    if (!interface_exists(TimezoneInterface::class, false)) {
        interface TimezoneInterface
        {
            public function getConfigTimezone($scopeType = null, $scopeCode = null);

            public function formatDateTime($date, $dateType = \IntlDateFormatter::SHORT, $timeType = \IntlDateFormatter::SHORT, $locale = null, $timezone = null, $pattern = null);
        }
    }
}

namespace Magento\Framework\Exception {
    if (!class_exists(NoSuchEntityException::class, false)) {
        class NoSuchEntityException extends \Exception
        {
        }
    }
}

namespace Magento\Checkout\Model {
    if (!class_exists(Session::class, false)) {
        class Session
        {
            public $lastRealOrder;

            public function getLastRealOrder()
            {
                return $this->lastRealOrder;
            }
        }
    }
}

namespace Magento\Customer\Model {
    if (!class_exists(Session::class, false)) {
        class Session
        {
            public $customerId = 0;

            public function isLoggedIn()
            {
                return $this->customerId > 0;
            }

            public function getCustomerId()
            {
                return $this->customerId;
            }
        }
    }
}

namespace Magento\Framework\View\Element\Template {
    if (!class_exists(Context::class, false)) {
        class Context
        {
            public function __construct(
                private \Magento\Framework\App\RequestInterface $request,
                private string $baseUrl,
                private string $moduleDirectory
            ) {
            }

            public function getRequest()
            {
                return $this->request;
            }

            public function getBaseUrl(): string
            {
                return $this->baseUrl;
            }

            public function getModuleDirectory(): string
            {
                return $this->moduleDirectory;
            }

            public function getEscaper()
            {
                return new \Magento\Framework\Escaper();
            }
        }
    }
}

namespace Magento\Framework\View\Element {
    if (!class_exists(AbstractBlock::class, false)) {
        abstract class AbstractBlock extends \Magento\Framework\DataObject
        {
            protected $_design;
            protected $_session;
            protected $_sidResolver;
            protected $_nameInLayout;
            protected $_layout;
            protected $jsLayout = [];
            protected $_request;
            protected $_urlBuilder;
            protected $_eventManager;
            protected $_frontController;
            protected $_assetRepo;
            protected $_viewConfig;
            protected $_cacheState;
            protected $_logger;
            protected $_escaper;
            protected $filterManager;
            protected $_localeDate;
            protected $inlineTranslation;
            protected $_isScopePrivate = false;
            protected $_scopeConfig;
            protected $_cache;
            private $deploymentConfig;
            private $lockQuery;
        }
    }

    if (!class_exists(Template::class, false)) {
        class Template extends AbstractBlock
        {
            protected $_viewVars = [];
            protected $_baseUrl;
            protected $_jsUrl;
            protected $_allowSymlinks;
            protected $_filesystem;
            protected $_template;
            protected $templateEnginePool;
            protected $_storeManager;
            protected $_appState;
            protected $directory;
            private $mediaDirectory;
            protected $templateContext;
            protected $pageConfig;
            protected $resolver;
            protected $validator;
            private $context;

            public function __construct(Template\Context $context, array $data = [])
            {
                $this->context = $context;
                $this->_request = $context->getRequest();
                $this->_escaper = $context->getEscaper();
                $this->templateContext = $this;
                parent::__construct($data);
                if (isset($data['template'])) {
                    $this->_template = $data['template'];
                }
            }

            public function setTemplate($template)
            {
                $this->_template = $template;
                return $this;
            }

            public function getRequest()
            {
                return $this->_request;
            }

            public function getUrl($route = '', $params = [])
            {
                $query = $params === [] ? '' : '?' . http_build_query($params);
                return rtrim($this->context->getBaseUrl(), '/') . '/' . trim($route, '/') . $query;
            }

            public function getBlockHtml($name)
            {
                return $name === 'formkey' ? '<input name="form_key" type="hidden" value="stub-form-key"/>' : '';
            }

            public function toHtml()
            {
                [$module, $path] = explode('::', (string) $this->_template, 2);
                $file = $this->context->getModuleDirectory() . '/view/' . (is_file($this->context->getModuleDirectory() . '/view/base/templates/' . $path) ? 'base' : 'frontend') . '/templates/' . $path;
                $block = $this;
                $escaper = $this->_escaper;
                ob_start();
                try {
                    (static function (string $file, $block, $escaper): void {
                        include $file;
                    })($file, $block, $escaper);
                } finally {
                    $html = (string) ob_get_clean();
                }
                return $html;
            }
        }
    }
}

namespace Magento\Payment\Block {
    if (!class_exists(Info::class, false)) {
        class Info extends \Magento\Framework\View\Element\Template
        {
            protected $_paymentSpecificInformation;
            protected $_template = 'Magento_Payment::info/default.phtml';

            public function getInfo()
            {
                return $this->getData('info');
            }

            public function getMethod()
            {
                return $this->getInfo()->getMethodInstance();
            }

            public function setInfo($info)
            {
                return $this->setData('info', $info);
            }

            public function getSpecificInformation()
            {
                return $this->_prepareSpecificInformation()->getData();
            }

            protected function _prepareSpecificInformation($transport = null)
            {
                if ($this->_paymentSpecificInformation === null) {
                    if ($transport === null) {
                        $transport = new \Magento\Framework\DataObject();
                    } elseif (is_array($transport)) {
                        $transport = new \Magento\Framework\DataObject($transport);
                    }
                    $this->_paymentSpecificInformation = $transport;
                }
                return $this->_paymentSpecificInformation;
            }
        }
    }
}

namespace Magento\Framework\Model {
    if (!class_exists(AbstractModel::class, false)) {
        abstract class AbstractModel extends \Magento\Framework\DataObject
        {
            protected $_eventPrefix = 'core_abstract';
            protected $_eventObject = 'object';
            protected $_idFieldName = 'id';
            protected $_hasDataChanges = false;
            protected $_origData;
            protected $_isDeleted = false;
            protected $_resource;
            protected $_resourceCollection;
            protected $_resourceName;
            protected $_collectionName;
            protected $_cacheTag = false;
            protected $_dataSaveAllowed = true;
            protected $_isObjectNew = null;
            protected $_validatorBeforeSave = null;
            protected $_eventManager;
            protected $_cacheManager;
            protected $_registry;
            protected $_logger;
            protected $_appState;
            protected $_actionValidator;
            protected $storedData = [];
        }
    }

    if (!class_exists(AbstractExtensibleModel::class, false)) {
        abstract class AbstractExtensibleModel extends AbstractModel
        {
            protected $extensionAttributesFactory;
            protected $extensionAttributes;
            protected $customAttributeFactory;
            protected $customAttributesCodes = null;
            protected $customAttributesChanged = false;
        }
    }
}

namespace Magento\Payment\Model\Method {
    if (!class_exists(AbstractMethod::class, false)) {
        abstract class AbstractMethod extends \Magento\Framework\Model\AbstractExtensibleModel
        {
            protected $_code;
            protected $_formBlockType = \Magento\Payment\Block\Form::class;
            protected $_infoBlockType = \Magento\Payment\Block\Info::class;
            protected $_isGateway = false;
            protected $_isOffline = false;
            protected $_canOrder = false;
            protected $_canAuthorize = false;
            protected $_canCapture = false;
            protected $_canCapturePartial = false;
            protected $_canCaptureOnce = false;
            protected $_canRefund = false;
            protected $_canRefundInvoicePartial = false;
            protected $_canVoid = false;
            protected $_canUseInternal = true;
            protected $_canUseCheckout = true;
            protected $_isInitializeNeeded = false;
            protected $_canFetchTransactionInfo = false;
            protected $_canReviewPayment = false;
            protected $_canCancelInvoice = false;
            protected $_debugReplacePrivateDataKeys = [];
            protected $_paymentData;
            protected $_scopeConfig;
            protected $logger;
            private $directory;
        }
    }
}

namespace Psr\Log {
    if (!interface_exists(LoggerInterface::class, false)) {
        interface LoggerInterface
        {
            public function error($message, array $context = []);
        }
    }
}

namespace {
    if (!function_exists('__')) {
        function __($text, ...$arguments)
        {
            foreach ($arguments as $index => $argument) {
                $text = str_replace('%' . ($index + 1), (string) $argument, $text);
            }
            return $text;
        }
    }
}
