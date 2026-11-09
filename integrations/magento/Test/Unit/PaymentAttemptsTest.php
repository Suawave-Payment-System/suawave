<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use Suawave\Payment\Core\ExpectedPayment;
use Suawave\Payment\Core\PaymentAttempts;
use Suawave\Payment\Cron\NoteExpiredPayments;
use Suawave\Payment\Model\ExpiryNoter;
use Suawave\Payment\Model\OrderPaymentLedger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PaymentAttemptsTest extends TestCase
{
    private AttemptHarness $shop;
    private int $expired;

    protected function setUp(): void
    {
        $this->shop = new AttemptHarness();
        $this->expired = (int) strtotime(AttemptHarness::FIRST) + 60;
    }

    public function testRenewalOfAnExpiredAttemptOpensANewNonceAndAddressAndSupersedesTheOld(): void
    {
        self::assertSame(PaymentAttempts::RENEWED, $this->shop->renew($this->expired));

        $nonce = $this->shop->info(ExpectedPayment::NONCE);
        self::assertNotSame(AttemptHarness::OLD_NONCE, $nonce);
        self::assertSame(32, strlen($nonce));
        self::assertSame(AttemptHarness::NEW_ADDRESS, $this->shop->info(ExpectedPayment::ADDRESS));
        self::assertSame((string) strtotime(AttemptHarness::SECOND), $this->shop->info(ExpectedPayment::EXPIRES_AT));
        self::assertSame('0', $this->shop->info(ExpectedPayment::PAID));
        self::assertStringContainsString(rawurlencode('nonce=' . $nonce), $this->shop->gateway->urls[1]);
        $previous = $this->shop->info(ExpectedPayment::PREVIOUS);
        self::assertCount(1, $previous);
        self::assertSame(AttemptHarness::OLD_NONCE, $previous[0]['nonce']);
        self::assertSame(AttemptHarness::OLD_ADDRESS, $previous[0]['address']);
        self::assertSame(1, $this->shop->notesContaining('window closed'));
        self::assertSame(0, $this->shop->repository->unlockedSaves);
    }

    public function testRenewalIsRefusedWhileTheWindowIsOpenOrTheOrderIsPaid(): void
    {
        $called = 0;
        $open = function () use (&$called): array {
            $called++;
            return [];
        };
        self::assertSame(PaymentAttempts::OPEN, $this->shop->renew((int) strtotime(AttemptHarness::FIRST) - 1, $open));
        $this->shop->order->payment->info[ExpectedPayment::PAID] = '1';
        self::assertSame(PaymentAttempts::PAID, $this->shop->renew($this->expired, $open));
        self::assertSame(0, $called);
        self::assertSame(AttemptHarness::OLD_NONCE, $this->shop->info(ExpectedPayment::NONCE));
    }

    public function testConfirmedCallbackForTheSupersededAttemptAfterTheNewOnePaidAddsOneNoteAndKeepsTheStatus(): void
    {
        $this->shop->renew($this->expired);
        $nonce = $this->shop->info(ExpectedPayment::NONCE);
        self::assertTrue($this->shop->confirm($nonce, AttemptHarness::NEW_ADDRESS, 'new-1')->isAcknowledged());
        self::assertSame(Order::STATE_PROCESSING, $this->shop->order->state);
        $this->shop->order->state = 'complete';
        $comments = count($this->shop->order->comments);

        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'old-1')->isAcknowledged());
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'old-1')->isAcknowledged());

        self::assertSame('complete', $this->shop->order->state);
        self::assertSame(1, $this->shop->invoices->prepared);
        self::assertSame($comments + 1, count($this->shop->order->comments));
        self::assertSame(1, $this->shop->notesContaining('payment received on a previous attempt, reconcile'));
        self::assertSame('25.000000', $this->shop->info(ExpectedPayment::RECEIVED));
    }

    public function testSupersededAttemptCallbackPaysAnUnpaidOrderAndRecordsThePayingAttempt(): void
    {
        $this->shop->renew($this->expired);
        $nonce = $this->shop->info(ExpectedPayment::NONCE);
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'old-1')->isAcknowledged());

        self::assertSame(Order::STATE_PROCESSING, $this->shop->order->state);
        self::assertSame('1', $this->shop->info(ExpectedPayment::PAID));
        self::assertSame(1, $this->shop->invoices->prepared);
        self::assertSame(
            ['nonce' => AttemptHarness::OLD_NONCE, 'address' => AttemptHarness::OLD_ADDRESS, 'attempt' => 'previous'],
            $this->shop->info(ExpectedPayment::PAID_BY)
        );
        self::assertSame('25.000000', $this->shop->info(ExpectedPayment::PREVIOUS)[0]['received']);
        self::assertSame('0', $this->shop->info(ExpectedPayment::RECEIVED));
        self::assertSame(0, $this->shop->notesContaining('previous attempt, reconcile'));

        $this->shop->order->state = 'complete';
        self::assertTrue($this->shop->confirm($nonce, AttemptHarness::NEW_ADDRESS, 'new-after-paid')->isAcknowledged());
        self::assertSame('complete', $this->shop->order->state);
        self::assertSame(1, $this->shop->invoices->prepared);
        self::assertSame('0', $this->shop->info(ExpectedPayment::RECEIVED));
        self::assertSame(1, $this->shop->notesContaining('previous attempt, reconcile'));
    }

    public function testSupersededAttemptPartialDepositsAccumulateOnThatAttempt(): void
    {
        $this->shop->renew($this->expired);
        $this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'old-part-1', '10.000000');
        self::assertSame('0', $this->shop->info(ExpectedPayment::PAID));
        $this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'old-part-2', '15.000000');
        self::assertSame('1', $this->shop->info(ExpectedPayment::PAID));
        self::assertSame('25.000000', $this->shop->info(ExpectedPayment::PREVIOUS)[0]['received']);
        self::assertSame(1, $this->shop->invoices->prepared);
    }

    public function testSecondConfirmedCallbackOnAPaidOrderOnlyAddsTheReconcileNote(): void
    {
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'first')->isAcknowledged());
        $this->shop->order->state = 'complete';
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'second')->isAcknowledged());
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'second')->isAcknowledged());

        self::assertSame('complete', $this->shop->order->state);
        self::assertSame(1, $this->shop->invoices->prepared);
        self::assertSame('25.000000', $this->shop->info(ExpectedPayment::RECEIVED));
        self::assertSame(['nonce' => AttemptHarness::OLD_NONCE, 'address' => AttemptHarness::OLD_ADDRESS, 'attempt' => 'current'], $this->shop->info(ExpectedPayment::PAID_BY));
        self::assertSame(1, $this->shop->notesContaining('previous attempt, reconcile'));
    }

    public function testOldCallbackThatPaysDuringTheCreateRequestStopsTheRenewal(): void
    {
        $opener = $this->shop->opener();
        $outcome = $this->shop->renew($this->expired, function () use ($opener): array {
            self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'old-race')->isAcknowledged());
            return $opener();
        });

        self::assertSame(PaymentAttempts::PAID, $outcome);
        self::assertSame(AttemptHarness::OLD_NONCE, $this->shop->info(ExpectedPayment::NONCE));
        self::assertSame(AttemptHarness::OLD_ADDRESS, $this->shop->info(ExpectedPayment::ADDRESS));
        self::assertNull($this->shop->info(ExpectedPayment::PREVIOUS));
        self::assertSame(Order::STATE_PROCESSING, $this->shop->order->state);
        self::assertSame(1, $this->shop->invoices->prepared);
        self::assertSame(403, $this->shop->confirm('ffffffffffffffffffffffffffffffff', AttemptHarness::NEW_ADDRESS, 'new-race')->status);
        self::assertSame(1, $this->shop->invoices->prepared);
    }

    public function testCallbackValidatedBeforeARenewalCommitsIsCheckedAgainUnderTheLock(): void
    {
        $this->shop->transaction->beforeLock = fn () => $this->shop->renew($this->expired);
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'old-late')->isAcknowledged());

        $nonce = $this->shop->info(ExpectedPayment::NONCE);
        self::assertNotSame(AttemptHarness::OLD_NONCE, $nonce);
        self::assertSame('0', $this->shop->info(ExpectedPayment::RECEIVED));
        self::assertSame('1', $this->shop->info(ExpectedPayment::PAID));
        self::assertSame('previous', $this->shop->info(ExpectedPayment::PAID_BY)['attempt']);
        self::assertSame(Order::STATE_PROCESSING, $this->shop->order->state);
        self::assertSame(1, $this->shop->invoices->prepared);
        self::assertTrue($this->shop->confirm($nonce, AttemptHarness::NEW_ADDRESS, 'new-late')->isAcknowledged());
        self::assertSame('0', $this->shop->info(ExpectedPayment::RECEIVED));
        self::assertSame(1, $this->shop->invoices->prepared);
        self::assertSame(1, $this->shop->notesContaining('previous attempt, reconcile'));
    }

    public function testOnTimeDepositFinalizedAfterTheDeadlineStillPaysTheCurrentAttempt(): void
    {
        $this->shop->attempts->noteExpiry($this->shop->order->incrementId, $this->expired, $this->shop->expiryNote());
        self::assertTrue($this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'late-final')->isAcknowledged());
        self::assertSame(Order::STATE_PROCESSING, $this->shop->order->state);
        self::assertSame(PaymentAttempts::PAID, $this->shop->renew($this->expired + 60));
    }

    public function testExpiryIsNotedOncePerAttemptUnderTheLockAndNeverOncePaid(): void
    {
        $reference = $this->shop->order->incrementId;
        $note = $this->shop->expiryNote();
        self::assertFalse($this->shop->attempts->noteExpiry($reference, $this->expired - 120, $note));
        self::assertTrue($this->shop->attempts->noteExpiry($reference, $this->expired, $note));
        self::assertFalse($this->shop->attempts->noteExpiry($reference, $this->expired + 5, $note));
        self::assertSame(AttemptHarness::OLD_NONCE, $this->shop->info(ExpectedPayment::EXPIRY_NOTED));

        $this->shop->renew($this->expired);
        self::assertSame(1, $this->shop->notesContaining('window closed'));
        $second = (int) strtotime(AttemptHarness::SECOND);
        self::assertTrue($this->shop->attempts->noteExpiry($reference, $second, $note));
        self::assertFalse($this->shop->attempts->noteExpiry($reference, $second + 5, $note));
        self::assertSame(2, $this->shop->notesContaining('window closed'));

        $paid = new AttemptHarness();
        $paid->order->payment->info[ExpectedPayment::PAID] = '1';
        self::assertFalse($paid->attempts->noteExpiry($reference, $this->expired, $note));
        self::assertSame(0, $paid->notesContaining('window closed'));
        self::assertContains('lock:' . $reference, $this->shop->transaction->log);
        self::assertSame(0, $this->shop->repository->unlockedSaves);
    }

    public function testCallbackThatPaysBeforeTheExpiryNoterTakesTheLockSuppressesTheNote(): void
    {
        $this->shop->transaction->beforeLock = fn () => $this->shop->confirm(AttemptHarness::OLD_NONCE, AttemptHarness::OLD_ADDRESS, 'just-in-time');
        self::assertFalse($this->shop->attempts->noteExpiry($this->shop->order->incrementId, $this->expired, $this->shop->expiryNote()));
        self::assertSame(0, $this->shop->notesContaining('window closed'));
    }

    public function testCronPagesWithACursorSkipsNotedRowsAndLooksBackPastTheApiMaximum(): void
    {
        $orders = [];
        for ($id = 1; $id <= NoteExpiredPayments::BATCH + 1; $id++) {
            $order = new Order();
            $order->id = $id;
            $orders[] = $order;
        }
        $collections = new CollectionFactory();
        $collections->pages = [array_slice($orders, 0, NoteExpiredPayments::BATCH), array_slice($orders, NoteExpiredPayments::BATCH)];
        $noter = $this->createMock(ExpiryNoter::class);
        $noter->expects(self::exactly(NoteExpiredPayments::BATCH + 1))->method('noteIfDue')->willReturnCallback(
            function (Order $order): bool {
                if ($order->id === 7) {
                    throw new \RuntimeException('lock wait timeout');
                }
                return true;
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new NoteExpiredPayments($collections, $noter, $logger))->execute();

        self::assertCount(2, $collections->selects);
        self::assertSame(720 * 3600 + 86400, NoteExpiredPayments::LOOKBACK_SECONDS);
        $first = $this->conditions($collections->selects[0]);
        $second = $this->conditions($collections->selects[1]);
        self::assertSame(0, $first['main_table.entity_id > ?']);
        self::assertSame(NoteExpiredPayments::BATCH, $second['main_table.entity_id > ?']);
        self::assertSame(['main_table.entity_id ASC'], $collections->selects[0]->orders);
        $text = implode("\n", array_keys($first));
        self::assertStringContainsString(OrderPaymentLedger::EXPIRY_NOTED, $text);
        self::assertStringContainsString(OrderPaymentLedger::NONCE, $text);
        self::assertStringContainsString(OrderPaymentLedger::PAID, $text);
        self::assertStringContainsString(OrderPaymentLedger::EXPIRES_AT, $text);
    }

    private function conditions(RecordingSelect $select): array
    {
        $conditions = [];
        foreach ($select->wheres as [$condition, $value]) {
            $conditions[$condition] = $value;
        }
        return $conditions;
    }
}
