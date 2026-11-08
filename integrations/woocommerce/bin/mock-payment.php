<?php

declare(strict_types=1);

use Suawave\CallbackHandler;
use Suawave\CallbackProcessor;
use Suawave\CallbackVerifier;
use Suawave\GatewayClient;
use Suawave\Ledger;
use Suawave\Money;
use Suawave\OrderRecord;
use Suawave\OrderLock;
use Suawave\OrderStore;
use Suawave\Totals;

require_once __DIR__ . '/../includes/autoload.php';

function usage(): never
{
    fwrite(STDERR, "usage: php bin/mock-payment.php <base_url> <api_key|-> --payout=<address> [options]\n"
        . "  --ticker=trc20/usdt        token ticker\n"
        . "  --amount=<decimal>         amount to request (default: the ticker minimum)\n"
        . "  --callback-host=127.0.0.1  host the gateway can reach this script on\n"
        . "  --callback-port=18089      port this script listens on\n"
        . "  --timeout=180              seconds to wait for the confirmed callback\n"
        . "  --control-url=<url>        mock chain control (http://127.0.0.1:PORT) to make the deposit automatically\n"
        . "  --chain=<key> --contract=<token contract> --decimals=<n>   deposit details for --control-url\n");
    exit(2);
}

function option(array $options, string $name, string $default = ''): string
{
    return $options[$name] ?? $default;
}

function httpGet(string $url): array
{
    $context = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];
        }
    }
    return [$status, $body === false ? '' : $body];
}

function httpPostJson(string $url, string $json): array
{
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $json, 'ignore_errors' => true, 'timeout' => 20]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];
        }
    }
    return [$status, $body === false ? '' : $body];
}

function toBaseUnits(string $amount, int $decimals): string
{
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    if (strlen($fraction) > $decimals) {
        throw new RuntimeException('amount has more decimals than the asset');
    }
    $units = ltrim($whole . str_pad($fraction, $decimals, '0'), '0');
    return $units === '' ? '0' : $units;
}

function readRequest($connection): ?array
{
    stream_set_timeout($connection, 5);
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $head .= $chunk;
        if (strlen($head) > 131072) {
            return null;
        }
    }
    [$headText, $body] = explode("\r\n\r\n", $head, 2);
    $lines = explode("\r\n", $headText);
    $parts = explode(' ', array_shift($lines));
    $headers = [];
    foreach ($lines as $line) {
        $pair = explode(':', $line, 2);
        if (count($pair) === 2) {
            $headers[strtolower(trim($pair[0]))] = trim($pair[1]);
        }
    }
    $length = (int) ($headers['content-length'] ?? 0);
    while (strlen($body) < $length) {
        $chunk = fread($connection, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    return ['method' => $parts[0] ?? '', 'target' => $parts[1] ?? '', 'headers' => $headers, 'body' => $body];
}

function respond($connection, int $status, string $body): void
{
    fwrite($connection, "HTTP/1.1 $status X\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($connection);
}

final class ScriptOrders implements OrderStore, Ledger, OrderLock
{
    public array $order;
    public array $claimed = [];
    public Totals $totals;
    public bool $paid = false;
    public int $deposits = 0;

    public function load(string $orderId): ?OrderRecord
    {
        return new OrderRecord($this->order['nonce'], $this->order['address'], $this->order['coin'], $this->order['amount']);
    }

    public function recordPending(string $orderId, string $txidIn): void
    {
        fwrite(STDOUT, "  pending callback accepted for $txidIn (order stays unpaid)\n");
    }

    public function recordDeposit(string $orderId, string $txidIn, string $txidOut, string $valueCoin, string $received, bool $paid): void
    {
        $this->paid = $this->paid || $paid;
        $this->deposits++;
    }

    public function claim(string $key): bool
    {
        if (isset($this->claimed[$key])) {
            return false;
        }
        $this->claimed[$key] = true;
        return true;
    }

    public function transactional(string $orderId, callable $work): mixed
    {
        [$result, $this->totals] = $work($this->totals);
        return $result;
    }
}

function failWith(string $message): never
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

$positional = [];
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
    } else {
        $positional[] = $argument;
    }
}
if (count($positional) < 2 || option($options, 'payout') === '') {
    usage();
}
[$baseUrl, $apiKey] = $positional;
$baseUrl = rtrim($baseUrl, '/');
$ticker = option($options, 'ticker', 'trc20/usdt');
$host = option($options, 'callback-host', '127.0.0.1');
$port = (int) option($options, 'callback-port', '18089');
$timeout = (int) option($options, 'timeout', '180');
$origin = 'http://' . $host . ':' . $port;

echo "1. fetching the callback public key from $baseUrl/pubkey/\n";
[$status, $raw] = httpGet($baseUrl . '/pubkey/');
$publicKey = json_decode($raw, true)['pubkey'] ?? '';
if ($status !== 200 || !is_string($publicKey) || !CallbackVerifier::isUsableKey($publicKey)) {
    failWith("no usable RSA public key from /pubkey/ (HTTP $status)");
}

$server = @stream_socket_server('tcp://' . $host . ':' . $port, $errorCode, $errorText);
if ($server === false) {
    failWith("cannot listen on $host:$port: $errorText");
}

$client = new GatewayClient($baseUrl, 'httpGet');
$orderId = 'SMOKE' . bin2hex(random_bytes(4));
$nonce = bin2hex(random_bytes(16));
$callbackUrl = $origin . '/?' . http_build_query(['suawave_callback' => '1', 'src' => 'woo', 'order' => $orderId, 'nonce' => $nonce], '', '&', PHP_QUERY_RFC3986);

echo "2. creating the deposit address\n";
try {
    $created = $client->createAddress($ticker, option($options, 'payout'), $callbackUrl);
} catch (Throwable $failure) {
    failWith('create failed: ' . $failure->getMessage());
}
$amount = option($options, 'amount', $created['minimum']);
if (!Money::isPositive($amount) || Money::compare($amount, $created['minimum']) < 0) {
    failWith("amount $amount is not a decimal at or above the minimum {$created['minimum']}");
}
echo "   deposit address {$created['address_in']}, amount $amount, minimum {$created['minimum']}\n";
$qr = $client->qrCode($ticker, $created['address_in'], $amount);
echo '   qr code: ' . ($qr === null ? 'not available' : strlen($qr) . ' bytes of PNG data URI') . "\n";

$orders = new ScriptOrders();
$orders->order = ['nonce' => $nonce, 'address' => $created['address_in'], 'coin' => str_replace('/', '_', $ticker), 'amount' => $amount];
$orders->totals = Totals::empty();
$handler = new CallbackHandler($publicKey, $origin, new CallbackProcessor($orders, $orders, $orders));

if (option($options, 'control-url') !== '') {
    $decimals = (int) option($options, 'decimals', '6');
    $chain = option($options, 'chain');
    if ($chain === '') {
        failWith('--chain is required with --control-url');
    }
    $target = ['Chain' => $chain, 'Network' => 'mock'];
    $deposit = ['Target' => $target, 'Action' => 'deposit', 'Transfer' => [
        'From' => option($options, 'payer', option($options, 'payout')),
        'To' => $created['address_in'],
        'Contract' => option($options, 'contract'),
        'Units' => toBaseUnits($amount, $decimals),
    ]];
    echo "3. depositing on the mock chain through " . option($options, 'control-url') . "\n";
    [$depositStatus, $depositBody] = httpPostJson(rtrim(option($options, 'control-url'), '/') . '/mock/control', (string) json_encode($deposit));
    if ($depositStatus !== 200) {
        failWith("mock deposit refused (HTTP $depositStatus): $depositBody");
    }
    $advance = ['Target' => $target, 'Action' => 'advance', 'Count' => (int) option($options, 'advance', '60')];
    httpPostJson(rtrim(option($options, 'control-url'), '/') . '/mock/control', (string) json_encode($advance));
} else {
    echo "3. waiting: make a mock-chain deposit of $amount to {$created['address_in']} now (see the README)\n";
}

echo "4. waiting up to {$timeout}s for the signed confirmed callback\n";
$deadline = time() + $timeout;
$forgedChecked = false;
$replayChecked = false;
$lastGoodRequest = null;
while (time() < $deadline && !($orders->paid && $forgedChecked && $replayChecked)) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) {
        continue;
    }
    $request = readRequest($connection);
    if ($request === null) {
        fclose($connection);
        continue;
    }
    $result = $handler->handle($request['method'], $request['target'], $request['body'], $request['headers']['content-type'] ?? '', $request['headers']['x-ca-signature'] ?? '');
    echo "   callback {$request['method']} -> HTTP {$result->status} {$result->body}\n";
    respond($connection, $result->status, $result->body);
    if ($result->status === 200 && $orders->paid && $lastGoodRequest === null) {
        $lastGoodRequest = $request;
        $forged = $request;
        $forged['headers']['x-ca-signature'] = base64_encode(random_bytes(256));
        $forgedResult = $handler->handle($forged['method'], $forged['target'], $forged['body'], $forged['headers']['content-type'] ?? '', $forged['headers']['x-ca-signature']);
        if ($forgedResult->status !== 401) {
            failWith('a callback with a bad signature was not rejected with 401');
        }
        echo "   forged signature rejected with 401\n";
        $forgedChecked = true;
        $before = $orders->deposits;
        $replayResult = $handler->handle($request['method'], $request['target'], $request['body'], $request['headers']['content-type'] ?? '', $request['headers']['x-ca-signature'] ?? '');
        if ($replayResult->status !== 200 || $orders->deposits !== $before) {
            failWith('a replayed callback was not acknowledged without side effects');
        }
        echo "   replayed uuid acknowledged without a second credit\n";
        $replayChecked = true;
    }
}
if (!$orders->paid) {
    failWith('no verified confirmed callback arrived in time (is the gateway able to reach ' . $origin . '?)');
}
echo "OK: order $orderId paid from a verified callback, received {$orders->totals->received} of $amount\n";
