<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class PaymentAttempts
{
    public const RENEWED = 'renewed';
    public const PAID = 'paid';
    public const OPEN = 'open';
    public const UNKNOWN = 'unknown';
    public const CHANGED = 'changed';

    public function __construct(
        private readonly AttemptLedger $ledger,
        private readonly OrderTransaction $transactions
    ) {
    }

    public function renew(string $orderReference, callable $open, int $now, callable $expiryNote): string
    {
        $before = $this->ledger->find($orderReference);
        $refusal = $this->refusal($before, $now);
        if ($refusal !== null) {
            return $refusal;
        }
        $opened = $open();
        return $this->transactions->runLocked(
            $orderReference,
            function () use ($orderReference, $before, $opened, $now, $expiryNote): string {
                $current = $this->ledger->find($orderReference);
                $refusal = $this->refusal($current, $now);
                if ($refusal !== null) {
                    return $refusal;
                }
                if (!hash_equals($before->nonce, $current->nonce)) {
                    return self::CHANGED;
                }
                $this->noteLocked($current, $now, $expiryNote);
                $this->ledger->replaceAttempt($orderReference, $opened);
                return self::RENEWED;
            }
        );
    }

    public function noteExpiry(string $orderReference, int $now, callable $expiryNote): bool
    {
        return $this->transactions->runLocked(
            $orderReference,
            function () use ($orderReference, $now, $expiryNote): bool {
                $current = $this->ledger->find($orderReference);
                return $current !== null && $this->noteLocked($current, $now, $expiryNote);
            }
        );
    }

    private function noteLocked(ExpectedPayment $current, int $now, callable $expiryNote): bool
    {
        if (!PaymentWindow::noteDue($current->expiresAt, $current->paid, $current->expiryNoted, $now)) {
            return false;
        }
        $this->ledger->recordExpiry($current->orderReference, $current->nonce, $expiryNote((int) $current->expiresAt));
        return true;
    }

    private function refusal(?ExpectedPayment $payment, int $now): ?string
    {
        if ($payment === null) {
            return self::UNKNOWN;
        }
        if ($payment->paid) {
            return self::PAID;
        }
        if (!PaymentWindow::isClosed($payment->expiresAt, $now)) {
            return self::OPEN;
        }
        return null;
    }
}
