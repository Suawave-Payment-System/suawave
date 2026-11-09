<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use Suawave\Payment\Block\Info\Suawave as InfoBlock;
use Suawave\Payment\Block\Payment\Instructions;
use Suawave\Payment\Core\Decimal;
use Suawave\Payment\Core\ExpectedPayment;
use Suawave\Payment\Core\GatewayClient;
use Suawave\Payment\Core\PaymentAttempts;
use Suawave\Payment\Model\Config;
use Suawave\Payment\Model\DeadlineFormatter;
use Suawave\Payment\Model\ExpiryNoter;
use Suawave\Payment\Model\OrderResolver;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\TestCase;

final class InstructionsTemplateTest extends TestCase
{
    private const QR = 'data:image/png;base64,QUJD';
    private const TEMPLATE = 'Suawave_Payment::payment/instructions.phtml';

    private AttemptHarness $shop;
    private Context $context;
    private DeadlineFormatter $formatter;
    private ?ScriptedGateway $qrGateway = null;

    protected function setUp(): void
    {
        $this->shop = new AttemptHarness();
        $request = new class implements RequestInterface {
            public array $params = [];

            public function getParam($key, $defaultValue = null)
            {
                return $this->params[$key] ?? $defaultValue;
            }
        };
        $this->context = new Context($request, 'https://shop.example', dirname(__DIR__, 2));
        $this->formatter = new DeadlineFormatter(new class implements TimezoneInterface {
            public function getConfigTimezone($scopeType = null, $scopeCode = null)
            {
                return 'UTC';
            }

            public function formatDateTime($date, $dateType = \IntlDateFormatter::SHORT, $timeType = \IntlDateFormatter::SHORT, $locale = null, $timezone = null, $pattern = null)
            {
                return $date->setTimezone(new \DateTimeZone((string) $timezone))->format('Y-m-d H:i');
            }
        });
    }

    public function testOpenWindowShowsAddressQrAndDeadline(): void
    {
        $deadline = $this->deadlineIn(3600);
        $html = $this->renderInstructions();
        self::assertStringContainsString(AttemptHarness::OLD_ADDRESS, $html);
        self::assertStringContainsString(self::QR, $html);
        self::assertStringContainsString(gmdate('Y-m-d H:i', $deadline) . ' (UTC)', $html);
        self::assertStringNotContainsString('suawave/payment/renew', $html);
        self::assertSame(['Pay before' => gmdate('Y-m-d H:i', $deadline) . ' (UTC)'], $this->infoLines());
    }

    public function testExpiredUnpaidAttemptShowsTheClosedWindowWithTheOrderIdAndARenewForm(): void
    {
        $this->deadlineIn(-60);
        $html = $this->renderInstructions();
        self::assertStringNotContainsString(AttemptHarness::OLD_ADDRESS, $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString(self::QR, $html);
        self::assertNull($this->qrGateway);
        self::assertStringContainsString('Payment window closed', $html);
        self::assertStringContainsString('order ' . $this->shop->order->incrementId, $html);
        self::assertStringContainsString('action="https://shop.example/suawave/payment/renew"', $html);
        self::assertStringContainsString('name="form_key"', $html);
        self::assertSame(1, $this->shop->notesContaining('Suawave payment window closed'));
        $this->renderInstructions();
        self::assertSame(1, $this->shop->notesContaining('Suawave payment window closed'));
        $lines = $this->infoLines();
        self::assertSame($this->shop->order->incrementId, $lines['Order']);
        self::assertArrayHasKey('Payment window closed on', $lines);
        self::assertStringNotContainsString(AttemptHarness::OLD_ADDRESS, implode(' ', $lines));
    }

    public function testPaidAttemptNeverShowsTheAddressAgainAfterExpiry(): void
    {
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'paid-1')->isAcknowledged());
        foreach ([3600, -86400] as $offset) {
            $this->deadlineIn($offset);
            $html = $this->renderInstructions();
            self::assertStringNotContainsString(AttemptHarness::OLD_ADDRESS, $html);
            self::assertStringNotContainsString('<img', $html);
            self::assertStringContainsString('Payment received', $html);
            self::assertStringContainsString('order ' . $this->shop->order->incrementId, $html);
            self::assertStringNotContainsString('suawave/payment/renew', $html);
            self::assertSame(['Payment' => 'Received'], $this->infoLines());
        }
        self::assertNull($this->qrGateway);
        self::assertSame(0, $this->shop->notesContaining('Suawave payment window closed'));
    }

    public function testRenewedAttemptShowsOnlyTheNewAddress(): void
    {
        $this->deadlineIn(-60);
        self::assertSame(PaymentAttempts::RENEWED, $this->shop->renew(time()));
        $this->shop->order->payment->info[ExpectedPayment::EXPIRES_AT] = (string) (time() + 3600);
        $html = $this->renderInstructions();
        self::assertStringContainsString(AttemptHarness::NEW_ADDRESS, $html);
        self::assertStringNotContainsString(AttemptHarness::OLD_ADDRESS, $html);
    }

    public function testRenewalCommentSaysAConfirmedOldDepositStillPaysAnUnpaidOrder(): void
    {
        $this->deadlineIn(-60);
        $this->shop->renew(time());
        self::assertSame(1, $this->shop->notesContaining('still recorded and pays this order while it is unpaid'));
        self::assertSame(0, $this->shop->notesContaining('never change the order status'));
    }

    public function testCustomerOrderPageOnlyResolvesTheLoggedInOwnersOrder(): void
    {
        $this->deadlineIn(-60);
        $this->context->getRequest()->params['order_id'] = '1';
        self::assertSame('', $this->renderInstructions());
        $this->shop->order->customerId = 9;
        self::assertSame('', $this->renderInstructions(8));
        $html = $this->renderInstructions(9);
        self::assertStringContainsString('name="order_id" value="1"', $html);
    }

    private function deadlineIn(int $seconds): int
    {
        $deadline = time() + $seconds;
        $this->shop->order->payment->info[ExpectedPayment::EXPIRES_AT] = (string) $deadline;
        return $deadline;
    }

    private function renderInstructions(int $customerId = 0): string
    {
        $checkout = new CheckoutSession();
        $checkout->lastRealOrder = $this->shop->order;
        $customer = new CustomerSession();
        $customer->customerId = $customerId;
        $orders = new class ($this->shop) implements \Magento\Sales\Api\OrderRepositoryInterface {
            public function __construct(private AttemptHarness $shop)
            {
            }

            public function save($order)
            {
                return $this->shop->repository->save($order);
            }

            public function get($id)
            {
                if ((int) $id !== (int) $this->shop->order->id) {
                    throw new \Magento\Framework\Exception\NoSuchEntityException('missing');
                }
                return $this->shop->order;
            }
        };
        $block = new Instructions(
            $this->context,
            new OrderResolver($checkout, $customer, $orders),
            $this->config(),
            $this->formatter,
            new ExpiryNoter(new PaymentAttempts($this->shop->ledger, $this->shop->transaction), $this->formatter),
            ['template' => self::TEMPLATE]
        );
        return $block->toHtml();
    }

    public function testInfoBlockShowsTheTitleOnceAndFormatsBscAmounts(): void
    {
        $this->deadlineIn(300);
        $block = new InfoBlock($this->context, $this->formatter, ['info' => $this->shop->order->payment]);
        $html = $block->toHtml();
        self::assertSame(1, substr_count($html, 'Pay with Suawave'));
        self::assertStringContainsString('Pay before', $html);
    }

    public function testBscAmountsRoundUpToSixDecimalsWithoutTrailingZerosAndKeepTwoForPlainAmounts(): void
    {
        self::assertSame('12.011261', Decimal::display('12.011260345365980608', 6));
        self::assertSame('12.011184', Decimal::display('12.011183118544993788', 6));
        self::assertSame('12.011819', Decimal::display('12.011818063280722264', 6));
        self::assertSame('12.011183', Decimal::display('12.011183000000000000', 6));
        self::assertSame('10.00', Decimal::display('10.000000000000000000', 6));
        self::assertSame('10.000001', Decimal::display('10.000000000000000001', 6));
        self::assertSame('13.00', Decimal::display('12.9999999', 6));
        self::assertSame('25.00', Decimal::display('25.00', 6));
        self::assertSame('0.000002', Decimal::display('0.0000010004', 6));
        self::assertSame('25', Decimal::display('25', 6));
        self::assertSame(6, Decimal::displayPlaces('bep20/usdt'));
        self::assertNull(Decimal::displayPlaces('eth/eth'));
        self::assertSame('12.00', Decimal::plain('12.000000000000000000'));
        self::assertSame('12.011183118544993788', Decimal::plain('12.011183118544993788'));
    }

    public function testDisplayedAmountIsNeverBelowTheStoredAmount(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $stored = random_int(10, 99) . '.' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT) . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            $shown = Decimal::display($stored, 6);
            self::assertLessThanOrEqual(6, strlen($shown) - strpos($shown, '.') - 1, $stored);
            self::assertTrue(Decimal::atLeast($shown, $stored), $stored);
            self::assertSame(-1, bccomp(bcsub($shown, $stored, 18), '0.000001', 18), $stored);
        }
    }

    public function testShownAmountAndQrRoundUpSoPayingTheShownAmountPaysTheOrder(): void
    {
        $this->shop->order->payment->info[ExpectedPayment::EXPECTED] = '12.011183118544993788';
        $this->deadlineIn(3600);
        $html = $this->renderInstructions();
        self::assertStringContainsString('<strong>12.011184 TRC20/USDT</strong>', $html);
        self::assertStringNotContainsString('12.011183', $html);
        self::assertNotNull($this->qrGateway);
        self::assertStringContainsString('value=12.011184&', $this->qrGateway->urls[0]);
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'short-1', '12.011183')->isAcknowledged());
        self::assertSame('0', (string) ($this->shop->info(ExpectedPayment::PAID) ?? '0'));
        self::assertSame(1, $this->shop->notesContaining('Received on that address in total: 12.011183.'));
    }

    public function testPayingTheShownAmountMarksTheOrderPaidAndTheStoredAmountStaysExact(): void
    {
        $this->shop->order->payment->info[ExpectedPayment::EXPECTED] = '12.011183118544993788';
        $this->deadlineIn(3600);
        $this->renderInstructions();
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'shown-1', '12.011184')->isAcknowledged());
        self::assertSame('1', $this->shop->info(ExpectedPayment::PAID));
        self::assertSame('12.011183118544993788', $this->shop->info(ExpectedPayment::EXPECTED));
    }

    public function testOrderCommentsShowDepositsWithoutTrailingZeros(): void
    {
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'bsc-1', '25.000000000000000000')->isAcknowledged());
        self::assertSame(1, $this->shop->notesContaining(' of 25.00 (event bsc-1)'));
        self::assertSame(1, $this->shop->notesContaining('Received on that address in total: 25.00.'));
        self::assertSame(0, $this->shop->notesContaining('25.000000'));
    }

    private function infoLines(): array
    {
        $block = new InfoBlock($this->context, $this->formatter, ['info' => $this->shop->order->payment]);
        return $block->getSpecificInformation();
    }

    private function config(): Config
    {
        $scope = new class implements ScopeConfigInterface {
            public function getValue($path, $scopeType = 'default', $scopeCode = null)
            {
                return '';
            }
        };
        return new class ($scope, $this) extends Config {
            public function __construct(ScopeConfigInterface $scope, private InstructionsTemplateTest $test)
            {
                parent::__construct($scope);
            }

            public function client(?int $storeId = null): GatewayClient
            {
                return $this->test->qrClient();
            }
        };
    }

    public function qrClient(): GatewayClient
    {
        $this->qrGateway = new ScriptedGateway([['status' => 'success', 'qr_code' => self::QR]]);
        return new GatewayClient('https://gw.example', $this->qrGateway);
    }
}
