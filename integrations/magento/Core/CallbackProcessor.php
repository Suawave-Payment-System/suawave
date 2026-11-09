<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class CallbackProcessor
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly PublicKeyProvider $keys,
        private readonly PaymentLedger $ledger,
        private readonly ProcessedEventStore $events,
        private readonly OrderTransaction $transactions
    ) {
    }

    public function handle(CallbackRequest $request): CallbackResponse
    {
        $method = strtoupper($request->method);
        if ($method !== 'GET' && $method !== 'POST') {
            return CallbackResponse::rejected(405, 'method not allowed');
        }
        if ($request->signature === '') {
            return CallbackResponse::rejected(401, 'missing signature');
        }
        $signedBytes = $method === 'GET' ? $request->fullUrl : $request->rawBody;
        try {
            if (!$this->signatureMatches($request->signature, $signedBytes)) {
                return CallbackResponse::rejected(401, 'invalid signature');
            }
        } catch (InvalidKeyException | GatewayException $exception) {
            return CallbackResponse::rejected(503, 'verification key unavailable');
        }

        $fields = $this->readFields($request, $method);
        if ($fields === null) {
            return CallbackResponse::rejected(400, 'malformed callback');
        }
        $uuid = $fields['uuid'] ?? '';
        $pending = $fields['pending'] ?? '';
        if ($uuid === '' || strlen($uuid) > 64 || ($pending !== '0' && $pending !== '1')) {
            return CallbackResponse::rejected(400, 'malformed callback');
        }
        if ($pending === '1') {
            return CallbackResponse::acknowledged();
        }
        return $this->handleConfirmed($uuid, $fields);
    }

    private function signatureMatches(string $signature, string $signedBytes): bool
    {
        $cached = $this->keys->current();
        if ($this->verifier->verify($cached, $signature, $signedBytes)) {
            return true;
        }
        $fresh = $this->keys->refresh();
        if ($fresh === $cached) {
            return false;
        }
        return $this->verifier->verify($fresh, $signature, $signedBytes);
    }

    private function handleConfirmed(string $uuid, array $fields): CallbackResponse
    {
        $reference = $fields['order'] ?? '';
        $nonce = $fields['nonce'] ?? '';
        $address = $fields['address_in'] ?? '';
        $coin = $fields['coin'] ?? '';
        $amount = $fields['value_coin'] ?? '';
        $deposit = $fields['txid_in'] ?? '';
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $reference) !== 1
            || $nonce === ''
            || $address === ''
            || $coin === ''
            || $deposit === ''
            || !Decimal::isValid($amount)
            || !Decimal::isPositive($amount)
        ) {
            return CallbackResponse::rejected(400, 'malformed callback');
        }

        return $this->transactions->runLocked(
            $reference,
            fn (): CallbackResponse => $this->applyConfirmed($reference, $nonce, $address, $coin, $uuid, $deposit, $amount)
        );
    }

    private function applyConfirmed(
        string $reference,
        string $nonce,
        string $address,
        string $coin,
        string $uuid,
        string $deposit,
        string $amount
    ): CallbackResponse {
        $current = $this->ledger->find($reference);
        if ($current === null) {
            return CallbackResponse::rejected(404, 'unknown order');
        }
        $attempt = $this->matchingAttempt($current, $nonce, $address, $coin);
        if ($attempt === false) {
            return CallbackResponse::rejected(403, 'callback does not match the order');
        }
        if (!$this->events->claim($uuid, $reference, $deposit)) {
            return CallbackResponse::acknowledged();
        }
        if ($current->paid) {
            $this->ledger->noteReconcile($reference, $uuid, $deposit, $amount);
            return CallbackResponse::acknowledged();
        }
        $received = $attempt === null ? $current->receivedAmount : $current->previous[$attempt]['received'];
        $expected = $attempt === null ? $current->expectedAmount : $current->previous[$attempt]['expected'];
        $total = Decimal::add($received, $amount);
        $this->ledger->applyDeposit($reference, $uuid, $deposit, $amount, $total, Decimal::atLeast($total, $expected), $attempt);
        return CallbackResponse::acknowledged();
    }

    private function matchingAttempt(ExpectedPayment $current, string $nonce, string $address, string $coin): int|false|null
    {
        if ($this->matches($current->nonce, $current->depositAddress, $current->ticker, $nonce, $address, $coin)) {
            return null;
        }
        foreach ($current->previous as $index => $attempt) {
            if ($this->matches($attempt['nonce'], $attempt['address'], $attempt['ticker'], $nonce, $address, $coin)) {
                return $index;
            }
        }
        return false;
    }

    private function matches(
        string $storedNonce,
        string $storedAddress,
        string $storedTicker,
        string $nonce,
        string $address,
        string $coin
    ): bool {
        return $storedNonce !== ''
            && hash_equals($storedNonce, $nonce)
            && $this->sameAddress($storedAddress, $address)
            && $coin === str_replace('/', '_', $storedTicker);
    }

    private function sameAddress(string $stored, string $received): bool
    {
        if (str_starts_with($stored, '0x') || str_starts_with($stored, '0X')) {
            return hash_equals(strtolower($stored), strtolower($received));
        }
        return hash_equals($stored, $received);
    }

    private function readFields(CallbackRequest $request, string $method): ?array
    {
        $query = [];
        $queryString = parse_url($request->fullUrl, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $query);
        }
        if ($method === 'GET') {
            return $this->onlyStrings($query);
        }
        $body = $this->readBody($request);
        if ($body === null) {
            return null;
        }
        $body = $this->onlyStrings($body);
        $query = $this->onlyStrings($query);
        if ($body === null || $query === null) {
            return null;
        }
        return $body + $query;
    }

    private function readBody(CallbackRequest $request): ?array
    {
        if (stripos($request->contentType, 'application/json') !== false) {
            $decoded = json_decode($request->rawBody, true);
            return is_array($decoded) ? $decoded : null;
        }
        $parsed = [];
        parse_str($request->rawBody, $parsed);
        return $parsed;
    }

    private function onlyStrings(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                return null;
            }
        }
        return $values;
    }
}
