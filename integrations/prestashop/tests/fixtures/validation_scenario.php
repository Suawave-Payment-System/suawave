<?php

declare(strict_types=1);

namespace Suawave {
    final class PrestashopRuntime
    {
        public const CURRENCY = 'SUAWAVE_CURRENCY';
        public const AWAITING_STATE = 'SUAWAVE_OS_AWAITING';

        public static function isConfigured(): bool
        {
            return true;
        }

        public static function setting(string $name): string
        {
            return [self::CURRENCY => 'USD', self::AWAITING_STATE => '9'][$name] ?? '';
        }

        public static function store(): PaymentStore
        {
            return $GLOBALS['store'];
        }

        public static function checkout(): CheckoutService
        {
            return $GLOBALS['checkout'];
        }

        public static function labels(): PaymentLabel
        {
            return new PaymentLabel('Suawave', new \DateTimeZone('UTC'));
        }
    }
}

namespace {
    use Suawave\CallbackProcessor;
    use Suawave\CallbackRequest;
    use Suawave\CheckoutService;
    use Suawave\GatewayClient;
    use Suawave\HttpResponse;
    use Suawave\PrestashopOrderGateway;
    use Suawave\PaymentLabel;
    use Suawave\PaymentRecord;
    use Suawave\Tests\InMemoryPaymentStore;
    use Suawave\Tests\StaticKeyProvider;
    use Suawave\Tests\TestKeyPair;
    use Suawave\Tests\HookedTransport;

    ini_set('display_errors', 'stderr');
    require $argv[1] . '/vendor/autoload.php';
    define('_PS_VERSION_', '8.2.0');

    final class Redirected extends Exception
    {
    }

    class Tools
    {
        public static function getValue($name)
        {
            return $GLOBALS['request'][$name] ?? false;
        }

        public static function redirect($url)
        {
            throw new Redirected($url);
        }
    }

    class Validate
    {
        public static function isLoadedObject($object)
        {
            return !empty($object->id);
        }
    }

    class PrestaShopLogger
    {
        public static function addLog($message, $severity)
        {
            $GLOBALS['log'][] = $message;
        }
    }

    class Cart
    {
        public const BOTH = 3;
        public $id = 7;
        public $id_customer = 1;
        public $id_address_delivery = 1;
        public $id_address_invoice = 1;
        public $id_currency = 1;

        public function getOrderTotal($tax, $type)
        {
            return 25.0;
        }
    }

    class Currency
    {
        public $id = 1;
        public $iso_code = 'USD';
    }

    class Customer
    {
        public $id = 1;
        public $secure_key = 'K';
    }

    define('_DB_PREFIX_', 'ps_');

    function pSQL($value)
    {
        return addslashes((string) $value);
    }

    class Configuration
    {
        public static function get($name)
        {
            return $name === 'PS_OS_PAYMENT' ? '2' : false;
        }
    }

    class Cache
    {
        public static array $objects = [];

        public static function isStored($key)
        {
            return isset(self::$objects[$key]);
        }

        public static function retrieve($key)
        {
            return self::$objects[$key];
        }

        public static function store($key, $value)
        {
            self::$objects[$key] = $value;
        }

        public static function clean($pattern)
        {
            foreach (array_keys(self::$objects) as $key) {
                if (fnmatch($pattern, $key)) {
                    unset(self::$objects[$key]);
                }
            }
        }
    }

    class Db
    {
        private static ?Db $instance = null;
        private array $queryCache = [];

        public static function getInstance()
        {
            return self::$instance ??= new Db();
        }

        public function getRow($sql, $useCache = true)
        {
            if ($useCache && array_key_exists($sql, $this->queryCache)) {
                return $this->queryCache[$sql];
            }
            if (preg_match('/^SELECT \* FROM `ps_orders` WHERE `id_order` = (\d+)$/D', $sql, $m) !== 1) {
                throw new LogicException('unexpected query ' . $sql);
            }
            $row = $GLOBALS['orders'][(int) $m[1]] ?? false;
            if ($useCache) {
                $this->queryCache[$sql] = $row;
            }
            return $row;
        }

        public function execute($sql, $useCache = true)
        {
            $quoted = "'((?:[^'\\\\]|\\\\.)*)'";
            if (preg_match('/^UPDATE `ps_orders` SET `payment` = ' . $quoted . ' WHERE `id_order` = (\d+)$/D', $sql, $m) === 1) {
                $GLOBALS['orders'][(int) $m[2]]['payment'] = stripslashes($m[1]);
                $GLOBALS['writes'][] = ['orders.payment', (int) $m[2], stripslashes($m[1])];
            } elseif (preg_match('/^UPDATE `ps_order_payment` `op` INNER JOIN `ps_orders` `o` ON .* SET `op`.`payment_method` = ' . $quoted . ' WHERE `o`.`id_order` = (\d+)$/D', $sql, $m) === 1) {
                $GLOBALS['writes'][] = ['order_payment.payment_method', (int) $m[2], stripslashes($m[1])];
            } elseif (preg_match('/^UPDATE `ps_orders` SET (\{.*\}) WHERE `id_order` = (\d+)$/D', $sql, $m) === 1) {
                $fields = json_decode($m[1], true);
                $GLOBALS['orders'][(int) $m[2]] = $fields + $GLOBALS['orders'][(int) $m[2]];
                $GLOBALS['writes'][] = ['orders.*', (int) $m[2], $fields];
            } else {
                throw new LogicException('unexpected statement ' . $sql);
            }
            if ($useCache) {
                preg_match_all('/(?:FROM|JOIN|UPDATE|INTO)\s+`(ps_[a-z_]+)`/i', $sql, $tables);
                foreach (array_keys($this->queryCache) as $cached) {
                    foreach ($tables[1] as $table) {
                        if (str_contains($cached, '`' . $table . '`')) {
                            unset($this->queryCache[$cached]);
                        }
                    }
                }
            }
            return true;
        }

        public function getMsgError()
        {
            return '';
        }
    }

    class Order
    {
        public $id = 0;
        public $id_cart = 0;
        public $module = '';
        public $secure_key = '';
        public $payment = '';
        public $current_state = 0;

        public function __construct($id)
        {
            $key = 'objectmodel_Order_' . (int) $id . '_0_0';
            if (Cache::isStored($key)) {
                $row = Cache::retrieve($key);
            } else {
                $row = Db::getInstance()->getRow('SELECT * FROM `ps_orders` WHERE `id_order` = ' . (int) $id);
                Cache::store($key, $row);
            }
            foreach ($row ?: [] as $name => $value) {
                $this->$name = $value;
            }
        }

        public static function getIdByCartId($cartId)
        {
            foreach ($GLOBALS['orders'] as $id => $order) {
                if ($order['id_cart'] === $cartId) {
                    return $id;
                }
            }
            return false;
        }

        public static function cleanHistoryCache()
        {
        }

        public function getCurrentState()
        {
            return $this->current_state;
        }

        public function update()
        {
            $fields = ['current_state' => $this->current_state, 'payment' => $this->payment];
            return Db::getInstance()->execute('UPDATE `ps_orders` SET ' . json_encode($fields) . ' WHERE `id_order` = ' . (int) $this->id);
        }
    }

    class Message
    {
        public $message;
        public $id_order;
        public $private;

        public function add()
        {
            $GLOBALS['writes'][] = ['message', (int) $this->id_order, $this->message];
            return true;
        }
    }

    class OrderHistory
    {
        public $id_order;

        public function changeIdOrderState($state, $order)
        {
            $order->current_state = (int) $state;
            $order->update();
        }

        public function add($autodate = true, $nullValues = false)
        {
            return true;
        }

        public function sendEmail($order, $templateVars = false)
        {
            return true;
        }
    }

    class Link
    {
        public function getPageLink($name, $ssl, $lang = null, $params = [])
        {
            return $name . ($params === [] ? '' : '?' . http_build_query($params));
        }
    }

    class Context
    {
        public $link;
        public $cart;

        public function getComputingPrecision()
        {
            return 2;
        }
    }

    class Module
    {
        public $active = true;
        public $name = 'suawave';
        public $id = 3;
        public $displayName = 'Suawave';
        public $currentOrder = 0;

        public function validateOrder($cartId, $state, $total, $label, $message, $vars, $currency, $dontTouch, $key)
        {
            $GLOBALS['validated'][] = [$cartId, $state, $label];
            $this->currentOrder = 56;
        }

        public function deadlineLabel($expiresAt)
        {
            return 'D' . $expiresAt;
        }
    }

    class ModuleFrontController
    {
        public $module;
        public $context;
        public $errors = [];

        public function trans($text, $params, $domain)
        {
            return $text;
        }

        public function redirectWithNotifications($url)
        {
            throw new Redirected($url);
        }
    }

    require $argv[1] . '/suawave/controllers/front/validation.php';

    $scenario = $argv[2];
    $now = 1789000000;
    $first = new PaymentRecord(7, 55, 'old-nonce', 'trc20/usdt', 'TA', 'TPayout', '25.00', '10', '0', PaymentRecord::AWAITING, $now - 60);
    $GLOBALS['store'] = new InMemoryPaymentStore();
    $GLOBALS['request'] = [];
    $GLOBALS['orders'] = [];
    $GLOBALS['validated'] = [];
    $GLOBALS['writes'] = [];
    $GLOBALS['log'] = [];
    if ($scenario !== 'new_cart') {
        $GLOBALS['store']->records['TA'] = $first;
        $GLOBALS['orders'][55] = ['id' => 55, 'id_cart' => 7, 'module' => 'suawave', 'secure_key' => 'K', 'payment' => 'Suawave - pay before old', 'current_state' => $scenario === 'paid_order' ? 2 : 9];
    }
    if ($scenario === 'retry_form' || $scenario === 'paid_between') {
        $GLOBALS['request'] = ['id_order' => '55', 'key' => 'K'];
    }
    if ($scenario === 'wrong_key') {
        $GLOBALS['request'] = ['id_order' => '55', 'key' => 'guess'];
    }
    $transport = new HookedTransport([new HttpResponse(200, json_encode([
        'status' => 'success', 'address_in' => 'TB', 'address_out' => 'TPayout', 'callback_url' => 'u',
        'minimum_transaction_coin' => '10.000000', 'expires_at' => '2026-09-11T00:00:00Z',
    ]))]);
    $labels = new PaymentLabel('Suawave', new DateTimeZone('UTC'));
    $GLOBALS['checkout'] = new CheckoutService(new GatewayClient('https://api.example', $transport), $GLOBALS['store'], 'trc20/usdt', 'TPayout', 'https://shop.example/cb', fn (): int => $now, new PrestashopOrderGateway(), $labels);
    if ($scenario === 'paid_between') {
        $GLOBALS['store']->onCommit = function () use ($first, $labels): void {
            $pair = TestKeyPair::generate();
            $processor = new CallbackProcessor(new StaticKeyProvider($pair->publicPem, $pair->publicPem), 'https://shop.example', $GLOBALS['store'], new PrestashopOrderGateway(), null, $labels);
            $uri = '/cb?' . http_build_query([
                'cart' => '7', 'nonce' => $first->nonce, 'uuid' => 'u-old', 'address_in' => 'TA', 'address_out' => 'TPayout',
                'coin' => 'trc20_usdt', 'pending' => '0', 'value_coin' => '25.00',
            ]);
            $GLOBALS['callback'] = $processor->process(new CallbackRequest('GET', $uri, '', '', $pair->sign('https://shop.example' . $uri)))->reason;
        };
    }

    $controller = new SuawaveValidationModuleFrontController();
    $controller->module = new Module();
    $controller->context = new Context();
    $controller->context->link = new Link();
    $controller->context->cart = new Cart();
    $redirect = null;
    try {
        $controller->postProcess();
    } catch (Redirected $done) {
        $redirect = $done->getMessage();
    }
    $records = [];
    foreach ($GLOBALS['store']->records as $address => $record) {
        $records[$address] = [$record->status, $record->orderId, $record->nonce];
    }
    echo "\n", json_encode([
        'redirect' => $redirect,
        'validated' => $GLOBALS['validated'],
        'writes' => $GLOBALS['writes'],
        'orders' => $GLOBALS['orders'],
        'callback' => $GLOBALS['callback'] ?? null,
        'requests' => count($transport->requested),
        'records' => $records,
    ]);
}
