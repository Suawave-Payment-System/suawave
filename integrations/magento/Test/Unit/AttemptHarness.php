<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use Suawave\Payment\Core\CallbackProcessor;
use Suawave\Payment\Core\CallbackRequest;
use Suawave\Payment\Core\CallbackResponse;
use Suawave\Payment\Core\Checkout;
use Suawave\Payment\Core\ExpectedPayment;
use Suawave\Payment\Core\GatewayClient;
use Suawave\Payment\Core\OrderTransaction;
use Suawave\Payment\Core\PaymentAttempts;
use Suawave\Payment\Core\ProcessedEventStore;
use Suawave\Payment\Core\PublicKeyProvider;
use Suawave\Payment\Core\SignatureVerifier;
use Suawave\Payment\Model\Config;
use Suawave\Payment\Model\OrderPaymentLedger;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;

final class AttemptPayment
{
    public array $info = [];
    public $order;

    public function getOrder()
    {
        return $this->order;
    }

    public function getMethod(): string
    {
        return Config::METHOD_CODE;
    }

    public function getMethodInstance()
    {
        return new class {
            public function getTitle(): string
            {
                return 'Pay with Suawave';
            }
        };
    }

    public function getAdditionalInformation($key = null)
    {
        return $key === null ? $this->info : ($this->info[$key] ?? null);
    }

    public function setAdditionalInformation($key, $value)
    {
        $this->info[$key] = $value;
        return $this;
    }
}

final class LockingTransaction implements OrderTransaction
{
    public array $log = [];
    public int $depth = 0;
    public $beforeLock = null;

    public function runLocked(string $orderReference, callable $work): mixed
    {
        if ($this->beforeLock !== null) {
            $hook = $this->beforeLock;
            $this->beforeLock = null;
            $hook();
        }
        if ($this->depth > 0) {
            throw new \LogicException('nested order lock');
        }
        $this->log[] = 'lock:' . $orderReference;
        $this->depth++;
        try {
            return $work();
        } finally {
            $this->depth--;
            $this->log[] = 'unlock';
        }
    }
}

final class LockAwareRepository implements OrderRepositoryInterface
{
    public int $saves = 0;
    public int $unlockedSaves = 0;

    public function __construct(private LockingTransaction $transaction)
    {
    }

    public function save($order)
    {
        $this->saves++;
        if ($this->transaction->depth === 0) {
            $this->unlockedSaves++;
        }
        return $order;
    }
}

final class AttemptHarness
{
    public const FIRST = '2026-11-09T12:00:00Z';
    public const SECOND = '2026-11-10T12:00:00Z';
    public const OLD_NONCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public const OLD_ADDRESS = 'TOldDepositAddress000000000000000';
    public const NEW_ADDRESS = 'TNewDepositAddress000000000000000';

    private static ?KeyPair $pair = null;

    public Order $order;
    public LockingTransaction $transaction;
    public LockAwareRepository $repository;
    public InvoiceService $invoices;
    public OrderPaymentLedger $ledger;
    public PaymentAttempts $attempts;
    public CallbackProcessor $processor;
    public ?ScriptedGateway $gateway = null;

    public function __construct()
    {
        self::$pair ??= KeyPair::generate(2048);
        $this->order = new Order();
        $this->order->payment = new AttemptPayment();
        $this->order->payment->order = $this->order;
        $this->order->payment->info = ExpectedPayment::attemptInfo([
            'nonce' => self::OLD_NONCE,
            'address_in' => self::OLD_ADDRESS,
            'ticker' => 'trc20/usdt',
            'expected' => '25.000000',
            'expires_at' => (string) strtotime(self::FIRST),
        ]);
        $factory = new OrderFactory();
        $factory->order = $this->order;
        $this->transaction = new LockingTransaction();
        $this->repository = new LockAwareRepository($this->transaction);
        $this->invoices = new InvoiceService();
        $this->ledger = new OrderPaymentLedger($factory, $this->repository, $this->invoices, new TransactionFactory());
        $this->attempts = new PaymentAttempts($this->ledger, $this->transaction);
        $pem = self::$pair->publicPem;
        $keys = new class ($pem) implements PublicKeyProvider {
            public function __construct(private string $pem)
            {
            }

            public function current(): string
            {
                return $this->pem;
            }

            public function refresh(): string
            {
                return $this->pem;
            }
        };
        $events = new class implements ProcessedEventStore {
            private array $seen = [];

            public function claim(string $eventUuid, string $orderReference, string $depositTransaction): bool
            {
                if (isset($this->seen[$eventUuid])) {
                    return false;
                }
                $this->seen[$eventUuid] = true;
                return true;
            }
        };
        $this->processor = new CallbackProcessor(new SignatureVerifier(), $keys, $this->ledger, $events, $this->transaction);
    }

    public function info(string $key): mixed
    {
        return $this->order->payment->info[$key] ?? null;
    }

    public function opener(): callable
    {
        $this->gateway = new ScriptedGateway([
            ['status' => 'success', 'value_coin' => '25.000000'],
            ['status' => 'success', 'address_in' => self::NEW_ADDRESS, 'minimum_transaction_coin' => '1.000000', 'expires_at' => self::SECOND],
        ]);
        $client = new GatewayClient('https://gw.example', $this->gateway);
        return fn (): array => Checkout::open(
            $client,
            'trc20/usdt',
            'TPayout',
            '25.00',
            'USD',
            fn (string $nonce): string => 'https://shop.example/suawave/callback/index?order=' . $this->order->incrementId . '&nonce=' . $nonce
        );
    }

    public function expiryNote(): callable
    {
        return fn (int $expiresAt): string => 'window closed ' . gmdate('c', $expiresAt);
    }

    public function renew(int $now, ?callable $open = null): string
    {
        return $this->attempts->renew($this->order->incrementId, $open ?? $this->opener(), $now, $this->expiryNote());
    }

    public function confirm(string $nonce, string $address, string $uuid, string $amount = '25.000000'): CallbackResponse
    {
        $url = 'https://shop.example/suawave/callback/index?order=' . $this->order->incrementId . '&nonce=' . $nonce;
        $body = (string) json_encode([
            'uuid' => $uuid,
            'address_in' => $address,
            'txid_in' => 'tx-' . $uuid,
            'coin' => 'trc20_usdt',
            'pending' => '0',
            'value_coin' => $amount,
        ]);
        return $this->processor->handle(new CallbackRequest('POST', $url, $body, 'application/json', self::$pair->sign($body)));
    }

    public function notesContaining(string $text): int
    {
        return count(array_filter($this->order->comments, static fn (array $comment): bool => str_contains((string) $comment[0], $text)));
    }
}
