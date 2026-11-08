<?php

declare(strict_types=1);

use Suawave\Payment\Core\CallbackProcessor;
use Suawave\Payment\Core\CallbackRequest;
use Suawave\Payment\Core\CurlTransport;
use Suawave\Payment\Core\ExpectedPayment;
use Suawave\Payment\Core\GatewayClient;
use Suawave\Payment\Core\OrderTransaction;
use Suawave\Payment\Core\PaymentLedger;
use Suawave\Payment\Core\ProcessedEventStore;
use Suawave\Payment\Core\PublicKeyProvider;
use Suawave\Payment\Core\SignatureVerifier;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = array_slice($argv, 1);
if (count($arguments) < 2) {
    fwrite(STDERR, "usage: php bin/complete-mock-payment.php <gateway-base-url> <payout-address> [ticker=trc20/usdt] [listen-port=18080] [timeout-seconds=300]\n");
    exit(2);
}
[$baseUrl, $payoutAddress] = $arguments;
$ticker = $arguments[2] ?? 'trc20/usdt';
$port = (int) ($arguments[3] ?? 18080);
$timeout = (int) ($arguments[4] ?? 300);

$client = new GatewayClient($baseUrl, new CurlTransport());
$publicKey = $client->publicKey();

$reference = 'mock' . bin2hex(random_bytes(4));
$nonce = bin2hex(random_bytes(16));
$origin = 'http://127.0.0.1:' . $port;
$callback = $origin . '/suawave/callback/index?' . http_build_query(['order' => $reference, 'nonce' => $nonce], '', '&', PHP_QUERY_RFC3986);
$created = $client->createAddress($ticker, $payoutAddress, $callback);
$expectedAmount = $created['minimum_transaction_coin'];

$keys = new class ($publicKey, $client) implements PublicKeyProvider {
    public function __construct(private string $key, private GatewayClient $client)
    {
    }

    public function current(): string
    {
        return $this->key;
    }

    public function refresh(): string
    {
        $this->key = $this->client->publicKey();
        return $this->key;
    }
};

$ledger = new class ($reference, $nonce, $created['address_in'], $ticker, $expectedAmount) implements PaymentLedger {
    public ?ExpectedPayment $payment;
    public int $applied = 0;

    public function __construct(string $reference, string $nonce, string $address, string $ticker, string $expected)
    {
        $this->payment = new ExpectedPayment($reference, $nonce, $address, $ticker, $expected, '0', false);
    }

    public function find(string $orderReference): ?ExpectedPayment
    {
        return $orderReference === $this->payment->orderReference ? $this->payment : null;
    }

    public function applyDeposit(string $orderReference, string $eventUuid, string $depositTransaction, string $depositAmount, string $newReceivedTotal, bool $fullyPaid): void
    {
        $this->applied++;
        $this->payment = new ExpectedPayment(
            $this->payment->orderReference,
            $this->payment->nonce,
            $this->payment->depositAddress,
            $this->payment->ticker,
            $this->payment->expectedAmount,
            $newReceivedTotal,
            $this->payment->paid || $fullyPaid
        );
        fwrite(STDOUT, sprintf("applied deposit %s of %s (event %s), total %s, paid=%s\n", $depositTransaction, $depositAmount, $eventUuid, $newReceivedTotal, $this->payment->paid ? 'yes' : 'no'));
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

$transactions = new class implements OrderTransaction {
    public function runLocked(string $orderReference, callable $work): mixed
    {
        return $work();
    }
};

$processor = new CallbackProcessor(new SignatureVerifier(), $keys, $ledger, $events, $transactions);

$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "cannot listen on port $port: $errorMessage\n");
    exit(2);
}

fwrite(STDOUT, "deposit address: {$created['address_in']}\n");
fwrite(STDOUT, "send at least {$expectedAmount} on the mock chain to it; waiting up to {$timeout}s for the confirmed callback\n");

$deadline = time() + $timeout;
$rejected = 0;
while (time() < $deadline && !$ledger->payment->paid) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 5);
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && strlen($head) < 65536) {
        $chunk = fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $head .= $chunk;
    }
    [$headerBlock, $bodyStart] = array_pad(explode("\r\n\r\n", $head, 2), 2, '');
    $lines = explode("\r\n", $headerBlock);
    [$method, $target] = array_pad(explode(' ', array_shift($lines)), 2, '');
    $headers = [];
    foreach ($lines as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = $bodyStart;
    while (strlen($body) < $length) {
        $chunk = fread($connection, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    $outcome = $processor->handle(new CallbackRequest(
        $method,
        $origin . $target,
        $body,
        $headers['content-type'] ?? '',
        $headers['x-ca-signature'] ?? ''
    ));
    if (!$outcome->isAcknowledged()) {
        $rejected++;
        fwrite(STDOUT, "rejected callback: {$outcome->status} {$outcome->body}\n");
    }
    fwrite($connection, sprintf(
        "HTTP/1.1 %d X\r\nContent-Type: text/plain\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
        $outcome->status,
        strlen($outcome->body),
        $outcome->body
    ));
    fclose($connection);
}

if ($ledger->payment->paid) {
    fwrite(STDOUT, "PASS: order {$reference} marked paid from a verified confirmed callback ({$rejected} callbacks rejected)\n");
    exit(0);
}
fwrite(STDOUT, "FAIL: no verified confirmed callback within {$timeout}s ({$rejected} callbacks rejected)\n");
exit(1);
