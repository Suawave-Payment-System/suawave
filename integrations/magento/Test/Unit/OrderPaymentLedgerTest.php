<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit {

    use Suawave\Payment\Model\Config;
    use Suawave\Payment\Model\OrderPaymentLedger;
    use Magento\Framework\DB\TransactionFactory;
    use Magento\Sales\Api\OrderRepositoryInterface;
    use Magento\Sales\Model\Order;
    use Magento\Sales\Model\OrderFactory;
    use Magento\Sales\Model\Service\InvoiceService;
    use PHPUnit\Framework\TestCase;

    final class LedgerPayment
    {
        public array $info = [
            OrderPaymentLedger::NONCE => 'n',
            OrderPaymentLedger::ADDRESS => 'a',
            OrderPaymentLedger::TICKER => 't',
            OrderPaymentLedger::EXPECTED => '10',
            OrderPaymentLedger::RECEIVED => '0',
        ];

        public function getMethod(): string
        {
            return Config::METHOD_CODE;
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

    final class LedgerRepository implements OrderRepositoryInterface
    {
        public int $saves = 0;

        public function save($order)
        {
            $this->saves++;
            return $order;
        }
    }

    final class OrderPaymentLedgerTest extends TestCase
    {
        private Order $order;
        private LedgerRepository $repository;
        private InvoiceService $invoices;
        private TransactionFactory $transactions;
        private OrderPaymentLedger $ledger;

        protected function setUp(): void
        {
            $this->order = new Order();
            $this->order->payment = new LedgerPayment();
            $factory = new OrderFactory();
            $factory->order = $this->order;
            $this->repository = new LedgerRepository();
            $this->invoices = new InvoiceService();
            $this->transactions = new TransactionFactory();
            $this->ledger = new OrderPaymentLedger($factory, $this->repository, $this->invoices, $this->transactions);
        }

        public function testCompletingCallbackMovesOrderToProcessingOnceWithHashComment(): void
        {
            $this->ledger->applyDeposit(Fixtures::ORDER, 'evt-1', '0xhash1', '10', '10', true);

            self::assertSame(Order::STATE_PROCESSING, $this->order->state);
            self::assertSame('processing', $this->order->status);
            self::assertSame(1, $this->invoices->prepared);
            self::assertSame(1, $this->transactions->saved);
            self::assertCount(1, $this->order->comments);
            self::assertStringContainsString('0xhash1', $this->order->comments[0][0]);
            self::assertSame('1', $this->order->payment->info[OrderPaymentLedger::PAID]);
        }

        public function testReplayedCompletionDoesNotInvoiceOrChangeStateAgain(): void
        {
            $this->ledger->applyDeposit(Fixtures::ORDER, 'evt-1', '0xhash1', '10', '10', true);
            $this->order->state = 'complete';
            $this->ledger->applyDeposit(Fixtures::ORDER, 'evt-2', '0xhash2', '1', '11', true);

            self::assertSame('complete', $this->order->state);
            self::assertSame(1, $this->invoices->prepared);
            self::assertCount(2, $this->order->comments);
        }

        public function testPartialDepositLeavesOrderPending(): void
        {
            $this->ledger->applyDeposit(Fixtures::ORDER, 'evt-1', '0xhash1', '4', '4', false);

            self::assertSame(Order::STATE_NEW, $this->order->state);
            self::assertSame('pending', $this->order->status);
            self::assertSame(0, $this->invoices->prepared);
            self::assertCount(1, $this->order->comments);
        }
    }
}
