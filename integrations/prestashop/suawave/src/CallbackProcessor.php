<?php

declare(strict_types=1);

namespace Suawave;

use Throwable;

final class CallbackProcessor
{
    public const RECONCILE_NOTE = 'Suawave payment received on a previous attempt, reconcile';
    public const PAID_NOTE = 'Suawave payment received after the order was paid, reconcile';

    public function __construct(
        private readonly PublicKeyProvider $keys,
        private readonly string $externalOrigin,
        private readonly PaymentStore $store,
        private readonly OrderGateway $orders,
        private readonly ?FailureLog $log = null,
        private readonly ?PaymentLabel $labels = null
    ) {
    }

    public function process(CallbackRequest $request): CallbackOutcome
    {
        $message = $request->signedMessage($this->externalOrigin);
        try {
            $verdict = $this->verdict($message, $request->signatureHeader);
        } catch (InvalidPublicKey | GatewayException) {
            return CallbackOutcome::rejected(503, 'verification_key_unavailable');
        }
        if ($verdict !== Verdict::Valid) {
            return CallbackOutcome::rejected(401, $verdict->value);
        }
        $fields = $request->fields();
        if ($fields === null) {
            return CallbackOutcome::rejected(400, 'unreadable_fields');
        }
        $event = self::readEvent($fields);
        if ($event === null) {
            return CallbackOutcome::rejected(400, 'malformed_event');
        }
        try {
            return $this->store->transactional(fn (): CallbackOutcome => $this->apply($request, $fields, $event));
        } catch (Throwable $failure) {
            $this->log?->record($failure);
            return CallbackOutcome::rejected(500, 'processing_failed');
        }
    }

    private function verdict(string $message, ?string $signatureHeader): Verdict
    {
        $cached = $this->keys->current();
        $verdict = (new SignatureVerifier($cached))->verify($message, $signatureHeader);
        if ($verdict !== Verdict::InvalidSignature) {
            return $verdict;
        }
        $fresh = $this->keys->refresh();
        if ($fresh === $cached) {
            return $verdict;
        }
        return (new SignatureVerifier($fresh))->verify($message, $signatureHeader);
    }

    private function apply(CallbackRequest $request, array $fields, array $event): CallbackOutcome
    {
        $found = $this->store->findByAddressIn($event['address_in']);
        if ($found === null) {
            return CallbackOutcome::acknowledged('unknown_address');
        }
        $rows = $this->store->lockCart($found->cartId);
        $record = CartAttempts::byAddress($rows, $event['address_in']);
        if ($record === null) {
            return CallbackOutcome::acknowledged('unknown_address');
        }
        if (!self::matchesRecord($request, $fields, $event, $record)) {
            return CallbackOutcome::rejected(400, 'record_mismatch');
        }
        $orderId = CartAttempts::orderId($rows);
        if ($event['pending'] === '0' && $orderId === 0) {
            return CallbackOutcome::rejected(503, 'order_not_created');
        }
        if (!$this->store->claimEvent($event['uuid'], $record->addressIn)) {
            return CallbackOutcome::acknowledged('duplicate');
        }
        $isCurrent = CartAttempts::current($rows)?->addressIn === $record->addressIn;
        if ($event['pending'] === '1') {
            if ($isCurrent && $record->status === PaymentRecord::AWAITING) {
                $this->store->save($record->with($record->received, PaymentRecord::DETECTED));
            }
            return CallbackOutcome::acknowledged('pending');
        }
        $received = Decimal::add($record->received, $event['value_coin']);
        $paid = CartAttempts::paid($rows);
        if ($paid !== null) {
            $this->store->save($record->with($received, $record->status));
            $note = $paid->addressIn === $record->addressIn ? self::PAID_NOTE : self::RECONCILE_NOTE;
            $this->orders->addNote($orderId, $note . ': ' . Decimal::plain($event['value_coin']) . ' ' . strtoupper($record->ticker) . ' to ' . $record->addressIn);
            return CallbackOutcome::acknowledged('already_paid');
        }
        if (Decimal::compare($received, $record->amount) < 0) {
            $this->store->save($record->with($received, $record->status));
            $this->orders->addNote(
                $orderId,
                'Suawave partial deposit confirmed on ' . $record->addressIn . ': received ' . Decimal::plain($event['value_coin']) . ' ' . strtoupper($record->ticker)
                . ', total received ' . Decimal::plain($received) . ' of ' . Decimal::plain($record->amount) . ', outstanding ' . Decimal::plain(self::outstanding($record->amount, $received)) . $event['txid'] . '.'
            );
            return CallbackOutcome::acknowledged('underpaid');
        }
        if ($this->labels !== null) {
            $this->orders->relabel($orderId, $this->labels->paid());
        }
        $this->orders->markPaid($orderId, 'Suawave payment confirmed, received ' . Decimal::plain($received) . ' of ' . Decimal::plain($record->amount) . ' to ' . $record->addressIn);
        $this->store->save($record->with($received, PaymentRecord::PAID));
        return CallbackOutcome::acknowledged($isCurrent ? 'paid' : 'paid_previous_attempt');
    }

    private static function readEvent(array $fields): ?array
    {
        foreach (['uuid', 'address_in', 'address_out', 'coin', 'pending'] as $name) {
            if (!isset($fields[$name]) || $fields[$name] === '') {
                return null;
            }
        }
        if (strlen($fields['uuid']) > 64) {
            return null;
        }
        if ($fields['pending'] !== '0' && $fields['pending'] !== '1') {
            return null;
        }
        $valueCoin = '0';
        if ($fields['pending'] === '0') {
            $valueCoin = $fields['value_coin'] ?? '';
            if (!Decimal::isPositive($valueCoin)) {
                return null;
            }
        }
        return [
            'uuid' => $fields['uuid'],
            'address_in' => $fields['address_in'],
            'address_out' => $fields['address_out'],
            'coin' => $fields['coin'],
            'pending' => $fields['pending'],
            'value_coin' => $valueCoin,
            'txid' => is_string($fields['txid_in'] ?? null) && preg_match('/^[0-9A-Za-z_:-]{1,128}$/D', $fields['txid_in']) === 1 ? ', transaction ' . $fields['txid_in'] : '',
        ];
    }

    private static function outstanding(string $amount, string $received): string
    {
        $scale = 0;
        foreach ([$amount, $received] as $value) {
            $position = strpos($value, '.');
            $scale = max($scale, $position === false ? 0 : strlen($value) - $position - 1);
        }
        return bcsub($amount, $received, $scale);
    }

    private static function matchesRecord(CallbackRequest $request, array $fields, array $event, PaymentRecord $record): bool
    {
        if ($event['coin'] !== str_replace('/', '_', $record->ticker)) {
            return false;
        }
        if ($event['address_out'] !== $record->payoutAddress) {
            return false;
        }
        if ($request->method === 'GET') {
            return ($fields['cart'] ?? null) === (string) $record->cartId
                && hash_equals($record->nonce, $fields['nonce'] ?? '');
        }
        return true;
    }
}
