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
            $known = $this->store->findByAddressIn($event['address_in']);
            if ($known === null) {
                return CallbackOutcome::acknowledged('unknown_address');
            }
            return $this->store->withOrderLock($known->orderId, fn (): CallbackOutcome => $this->apply($request, $fields, $event));
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
        $paid = $this->store->findPaidByOrderId($record->orderId);
        if ($event['pending'] === '1') {
            $current = $this->store->findByOrderId($record->orderId);
            if ($paid === null && $current !== null && $current->addressIn === $record->addressIn && $record->status === PaymentRecord::AWAITING) {
                $this->store->save($record->with($record->received, PaymentRecord::DETECTED));
            }
            return CallbackOutcome::acknowledged('pending');
        }
        $received = Decimal::add($record->received, $event['value_coin']);
        if ($paid !== null) {
            $this->store->save($record->with($received, $record->status));
            $this->orders->addNote($record->orderId, 'Suawave payment received on a previous attempt, reconcile: ' . Decimal::plain($event['value_coin']) . ' on ' . $record->addressIn . ' (total ' . Decimal::plain($received) . ' on that address' . $event['txid'] . '). The order was already paid on ' . $paid->addressIn . ', its status was not changed.');
            return CallbackOutcome::acknowledged('already_paid');
        }
        if (Decimal::compare($received, $record->amount) < 0) {
            $this->store->save($record->with($received, $record->status));
            $this->orders->addNote($record->orderId, 'Suawave partial deposit confirmed: received ' . Decimal::plain($event['value_coin']) . ', total received ' . Decimal::plain($received) . ' of ' . Decimal::plain($record->amount) . ', outstanding ' . Decimal::plain(Decimal::subtract($record->amount, $received)) . $event['txid'] . '.');
            return CallbackOutcome::acknowledged('underpaid');
        }
        $payable = $this->orders->currentPayable($record->orderId);
        if ($payable === null || $payable['ticker'] !== $record->ticker || Decimal::compare($payable['amount'], $record->amount) !== 0) {
            $this->store->save($record->with($received, $record->status));
            $this->orders->addNote($record->orderId, 'Suawave payment of ' . Decimal::plain($received) . ' received for a quote of ' . Decimal::plain($record->amount) . ' that no longer matches the order total. The order was not marked paid, review it manually.');
            return CallbackOutcome::acknowledged('order_changed');
        }
        $this->orders->markPaid($record->orderId, 'Suawave payment completed on ' . $record->addressIn . ': received ' . Decimal::plain($event['value_coin']) . ', total received ' . Decimal::plain($received) . ' of ' . Decimal::plain($record->amount) . $event['txid'] . '.');
        $this->store->save($record->with($received, PaymentRecord::PAID));
        return CallbackOutcome::acknowledged('paid');
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
            'txid' => isset($fields['txid_in']) && $fields['txid_in'] !== '' ? ', transaction ' . $fields['txid_in'] : '',
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
