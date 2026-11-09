<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class ExpectedPayment
{
    public const NONCE = 'suawave_nonce';
    public const ADDRESS = 'suawave_address';
    public const TICKER = 'suawave_ticker';
    public const EXPECTED = 'suawave_expected_amount';
    public const RECEIVED = 'suawave_received_amount';
    public const PAID = 'suawave_paid';
    public const EXPIRES_AT = 'suawave_expires_at';
    public const EXPIRY_NOTED = 'suawave_expiry_noted';
    public const PREVIOUS = 'suawave_previous_attempts';
    public const PAID_BY = 'suawave_paid_by';

    public function __construct(
        public readonly string $orderReference,
        public readonly string $nonce,
        public readonly string $depositAddress,
        public readonly string $ticker,
        public readonly string $expectedAmount,
        public readonly string $receivedAmount,
        public readonly bool $paid,
        public readonly ?int $expiresAt = null,
        public readonly bool $expiryNoted = false,
        public readonly array $previous = []
    ) {
    }

    public static function fromInfo(string $orderReference, array $info): ?self
    {
        foreach ([self::NONCE, self::ADDRESS, self::TICKER, self::EXPECTED, self::RECEIVED] as $field) {
            if (!isset($info[$field]) || !is_string($info[$field])) {
                return null;
            }
        }
        $previous = [];
        foreach (is_array($info[self::PREVIOUS] ?? null) ? $info[self::PREVIOUS] : [] as $attempt) {
            if (is_array($attempt)
                && is_string($attempt['nonce'] ?? null)
                && is_string($attempt['address'] ?? null)
                && is_string($attempt['ticker'] ?? null)
                && is_string($attempt['expected'] ?? null)
                && is_string($attempt['received'] ?? null)
                && Decimal::isValid($attempt['expected'])
                && Decimal::isValid($attempt['received'])
            ) {
                $previous[] = $attempt;
            }
        }
        return new self(
            $orderReference,
            $info[self::NONCE],
            $info[self::ADDRESS],
            $info[self::TICKER],
            $info[self::EXPECTED],
            $info[self::RECEIVED],
            ($info[self::PAID] ?? '0') === '1',
            PaymentWindow::fromStored($info[self::EXPIRES_AT] ?? null),
            ($info[self::EXPIRY_NOTED] ?? '') === $info[self::NONCE],
            $previous
        );
    }

    public static function attemptInfo(array $opened): array
    {
        return [
            self::NONCE => $opened['nonce'],
            self::ADDRESS => $opened['address_in'],
            self::TICKER => $opened['ticker'],
            self::EXPECTED => $opened['expected'],
            self::EXPIRES_AT => $opened['expires_at'],
            self::RECEIVED => '0',
            self::PAID => '0',
        ];
    }

    public static function supersededInfo(array $info, array $opened): array
    {
        $previous = is_array($info[self::PREVIOUS] ?? null) ? $info[self::PREVIOUS] : [];
        $previous[] = [
            'nonce' => (string) ($info[self::NONCE] ?? ''),
            'address' => (string) ($info[self::ADDRESS] ?? ''),
            'ticker' => (string) ($info[self::TICKER] ?? ''),
            'expected' => (string) ($info[self::EXPECTED] ?? ''),
            'received' => (string) ($info[self::RECEIVED] ?? '0'),
            'expires_at' => (string) ($info[self::EXPIRES_AT] ?? ''),
        ];
        return [self::PREVIOUS => $previous] + self::attemptInfo($opened);
    }
}
