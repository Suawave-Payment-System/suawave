<?php

declare(strict_types=1);

namespace {
    use Suawave\CallbackProcessor;
    use Suawave\CallbackRequest;
    use Suawave\PaymentRecord;
    use Suawave\PrestashopFailureLog;
    use Suawave\PrestashopOrderGateway;
    use Suawave\Tests\InMemoryPaymentStore;
    use Suawave\Tests\StaticKeyProvider;
    use Suawave\Tests\TestKeyPair;

    ini_set('display_errors', 'stderr');
    require $argv[1] . '/vendor/autoload.php';

    class Configuration
    {
        public static function get($name)
        {
            return $name === 'PS_OS_PAYMENT' ? '2' : false;
        }
    }

    class Cache
    {
        public static function clean($pattern)
        {
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
            $GLOBALS['log'][] = [$severity, $message];
        }
    }

    class Order
    {
        public $id = 0;
        public $current_state = 0;
        public $id_lang = 1;

        public function __construct($id)
        {
            foreach ($GLOBALS['orders'][(int) $id] ?? [] as $name => $value) {
                $this->$name = $value;
            }
        }

        public static function cleanHistoryCache()
        {
            $GLOBALS['cache_cleaned']++;
        }
    }

    class Message
    {
        public $message;
        public $id_order;
        public $private;

        public function add()
        {
            $GLOBALS['messages'][] = [(int) $this->id_order, $this->message, (int) $this->private];
            return true;
        }
    }

    class OrderHistory
    {
        public $id;
        public $id_order;
        public $id_order_state;

        public function changeIdOrderState($state, $order)
        {
            $this->id_order_state = (int) $state;
        }

        public function add($autodate = true, $nullValues = false)
        {
            if ($GLOBALS['history_fails']) {
                return false;
            }
            $GLOBALS['history'][] = [(int) $this->id_order, $this->id_order_state];
            $this->id = count($GLOBALS['history']);
            $GLOBALS['orders'][(int) $this->id_order]['current_state'] = $this->id_order_state;
            return true;
        }

        public function addWithemail($autodate = true, $templateVars = false, $context = null)
        {
            throw new LogicException('addWithemail ties the paid transition to Mail::Send');
        }

        public function sendEmail($order, $templateVars = false)
        {
            $GLOBALS['emails'][] = [(int) $this->id, (int) $order->id];
            if ($GLOBALS['mail'] === 'throws') {
                throw new RuntimeException('Swift Error: Expected response code 220');
            }
            return $GLOBALS['mail'] === 'sent';
        }
    }

    $scenario = $argv[2];
    $GLOBALS['orders'] = [55 => ['id' => 55, 'current_state' => 9]];
    $GLOBALS['history'] = [];
    $GLOBALS['emails'] = [];
    $GLOBALS['messages'] = [];
    $GLOBALS['log'] = [];
    $GLOBALS['cache_cleaned'] = 0;
    $GLOBALS['history_fails'] = $scenario === 'history_fails';
    $GLOBALS['mail'] = ['mail_false' => 'false', 'mail_throws' => 'throws'][$scenario] ?? 'sent';

    $store = new InMemoryPaymentStore();
    $store->save(new PaymentRecord(7, 55, 'nonce-1', 'trc20/usdt', 'TA', 'TPayout', '12.00', '10', '0', PaymentRecord::AWAITING));
    $pair = TestKeyPair::generate();
    $processor = new CallbackProcessor(new StaticKeyProvider($pair->publicPem, $pair->publicPem), 'https://shop.example', $store, new PrestashopOrderGateway(), new PrestashopFailureLog());
    $uri = '/cb?' . http_build_query([
        'cart' => '7', 'nonce' => 'nonce-1', 'uuid' => 'u-1', 'address_in' => 'TA', 'address_out' => 'TPayout',
        'coin' => 'trc20_usdt', 'pending' => '0', 'value_coin' => '12.00',
    ]);
    $request = new CallbackRequest('GET', $uri, '', '', $pair->sign('https://shop.example' . $uri));
    $outcomes = [];
    foreach ([1, 2] as $delivery) {
        $outcome = $processor->process($request);
        $outcomes[] = [$outcome->httpStatus, $outcome->reason];
    }
    $record = $store->findByAddressIn('TA');
    echo json_encode([
        'outcomes' => $outcomes,
        'state' => $GLOBALS['orders'][55]['current_state'],
        'history' => $GLOBALS['history'],
        'emails' => $GLOBALS['emails'],
        'messages' => $GLOBALS['messages'],
        'log' => $GLOBALS['log'],
        'cache_cleaned' => $GLOBALS['cache_cleaned'],
        'record' => [$record->status, $record->received],
        'events' => array_keys($store->events),
    ]), "\n";
}
