<?php

declare(strict_types=1);

namespace Suawave;

use Throwable;

final class CallbackProcessor
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly string $externalOrigin,
        private readonly PaymentStore $store,
        private readonly OrderGateway $orders
    ) {
    }

    public function process(CallbackRequest $request): CallbackOutcome
    {
        $verdict = $this->verifier->verify($request->signedMessage($this->externalOrigin), $request->signatureHeader);
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
        } catch (Throwable) {
            return CallbackOutcome::rejected(500, 'processing_failed');
        }
    }

    private function apply(CallbackRequest $request, array $fields, array $event): CallbackOutcome
    {
        $record = $this->store->lockByAddressIn($event['address_in']);
        if ($record === null) {
            return CallbackOutcome::acknowledged('unknown_address');
        }
        if (!self::matchesRecord($request, $fields, $event, $record)) {
            return CallbackOutcome::rejected(400, 'record_mismatch');
        }
        if (!$this->store->claimEvent($event['uuid'], $record->addressIn)) {
            return CallbackOutcome::acknowledged('duplicate');
        }
        if ($event['pending'] === '1') {
            if ($record->status === PaymentRecord::AWAITING) {
                $this->store->save($record->with($record->received, PaymentRecord::DETECTED));
            }
            return CallbackOutcome::acknowledged('pending');
        }
        $received = Decimal::add($record->received, $event['value_coin']);
        if ($record->status === PaymentRecord::PAID) {
            $this->store->save($record->with($received, PaymentRecord::PAID));
            return CallbackOutcome::acknowledged('already_paid');
        }
        if ($record->status === PaymentRecord::SUPERSEDED || !$this->isCurrent($record)) {
            $this->store->save($record->with($received, PaymentRecord::SUPERSEDED));
            $this->orders->addNote($record->orderId, 'Suawave payment received on a superseded address ' . $record->addressIn . ': ' . $event['value_coin'] . ' (total ' . $received . '). The order was not marked paid, review it manually.');
            return CallbackOutcome::acknowledged('superseded');
        }
        if (Decimal::compare($received, $record->amount) < 0) {
            $this->store->save($record->with($received, $record->status));
            return CallbackOutcome::acknowledged('underpaid');
        }
        $payable = $this->orders->currentPayable($record->orderId);
        if ($payable === null || $payable['ticker'] !== $record->ticker || Decimal::compare($payable['amount'], $record->amount) !== 0) {
            $this->store->save($record->with($received, $record->status));
            $this->orders->addNote($record->orderId, 'Suawave payment of ' . $received . ' received for a quote of ' . $record->amount . ' that no longer matches the order total. The order was not marked paid, review it manually.');
            return CallbackOutcome::acknowledged('order_changed');
        }
        $this->orders->markPaid($record->orderId, 'Suawave payment confirmed, received ' . $received . ' of ' . $record->amount);
        $this->store->save($record->with($received, PaymentRecord::PAID));
        return CallbackOutcome::acknowledged('paid');
    }

    private function isCurrent(PaymentRecord $record): bool
    {
        $current = $this->store->findByOrderId($record->orderId);
        return $current !== null && $current->addressIn === $record->addressIn;
    }

    private static function readEvent(array $fields): ?array
    {
        foreach (['uuid', 'address_in', 'address_out', 'coin', 'pending'] as $name) {
            if (!isset($fields[$name]) || $fields[$name] === '') {
                return null;
            }
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
        ];
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
            return ($fields['order'] ?? null) === (string) $record->orderId
                && hash_equals($record->nonce, $fields['nonce'] ?? '');
        }
        return true;
    }
}
