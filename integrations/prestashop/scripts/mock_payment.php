<?php

declare(strict_types=1);

require_once __DIR__ . '/../suawave/src/autoload.php';

use Suawave\CallbackRequest;
use Suawave\CurlTransport;
use Suawave\Decimal;
use Suawave\GatewayClient;
use Suawave\SignatureVerifier;
use Suawave\Verdict;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: " . $message . "\n");
    exit(1);
}

function step(string $message): void
{
    echo $message . "\n";
}

function postJson(string $url, array $payload): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($status !== 200 || !is_string($body)) {
        fail('control request to ' . $url . ' answered HTTP ' . $status . ' ' . (string) $body);
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

$positional = [];
$options = [
    'ticker' => 'trc20/usdt',
    'amount' => '25.00',
    'listen' => '127.0.0.1:8099',
    'callback-origin' => '',
    'control-url' => '',
    'chain' => 'tron',
    'network' => 'mock',
    'contract' => '',
    'decimals' => '6',
    'from' => 'TMockSenderAddress00000000000000',
    'timeout' => '60',
];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!array_key_exists($name, $options)) {
            fail('unknown option --' . $name);
        }
        $options[$name] = $value;
    } else {
        $positional[] = $argument;
    }
}
if (count($positional) < 2) {
    fwrite(STDERR, "usage: php scripts/mock_payment.php <base-url> <payout-address> --control-url=<mock control url> --contract=<token contract> [--ticker=trc20/usdt] [--amount=25.00] [--listen=127.0.0.1:8099] [--callback-origin=http://127.0.0.1:8099] [--chain=tron] [--network=mock] [--decimals=6] [--from=<address>] [--timeout=60]\n");
    exit(2);
}
$baseUrl = rtrim($positional[0], '/');
$payoutAddress = $positional[1];
if ($payoutAddress === '' || !Decimal::isPositive($options['amount'])) {
    fail('a payout address and a positive --amount are required');
}
$callbackOrigin = rtrim($options['callback-origin'] !== '' ? $options['callback-origin'] : 'http://' . $options['listen'], '/');

$client = new GatewayClient($baseUrl, new CurlTransport());
$verifier = new SignatureVerifier($client->publicKey());
step('public key fetched and accepted');

$server = stream_socket_server('tcp://' . $options['listen'], $errorNumber, $errorMessage);
if ($server === false) {
    fail('cannot listen on ' . $options['listen'] . ': ' . $errorMessage);
}
stream_set_blocking($server, false);

$nonce = bin2hex(random_bytes(16));
$orderId = random_int(100000, 999999);
$callbackUrl = $callbackOrigin . '/hook?cart=' . $orderId . '&nonce=' . $nonce;
$created = $client->createAddress($options['ticker'], $payoutAddress, $callbackUrl);
step('deposit address ' . $created->addressIn . ' minimum ' . $created->minimum);
if (Decimal::compare($options['amount'], $created->minimum) < 0) {
    fail('amount is below the minimum ' . $created->minimum);
}

if ($options['control-url'] === '') {
    step('no --control-url given: send ' . $options['amount'] . ' to the address yourself; waiting for the signed callback');
} else {
    if ($options['contract'] === '') {
        fail('--contract is required with --control-url');
    }
    $decimals = (int) $options['decimals'];
    $units = bcmul($options['amount'], bcpow('10', (string) $decimals), 0);
    $target = ['Chain' => $options['chain'], 'Network' => $options['network']];
    $deposit = postJson($options['control-url'], [
        'Target' => $target,
        'Action' => 'deposit',
        'Transfer' => ['Contract' => $options['contract'], 'From' => $options['from'], 'To' => $created->addressIn, 'Units' => $units],
    ]);
    $height = $deposit['Block']['Height'] ?? null;
    if (!is_int($height)) {
        fail('deposit control answer has no block height');
    }
    postJson($options['control-url'], ['Target' => $target, 'Action' => 'advance', 'Count' => 30]);
    postJson($options['control-url'], ['Target' => $target, 'Action' => 'finalize', 'Height' => $height + 30]);
    step('mock deposit of ' . $units . ' base units mined and finalized');
}

$deadline = time() + (int) $options['timeout'];
$sawPending = false;
$confirmedValue = null;
$firstConfirmedUri = null;
$firstConfirmedSignature = null;
while (time() < $deadline && $confirmedValue === null) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 5);
    $raw = '';
    while (!str_contains($raw, "\r\n\r\n")) {
        $chunk = fread($connection, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $raw .= $chunk;
    }
    [$head, $bodyStart] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $lines = explode("\r\n", $head);
    [$method, $uri] = array_pad(explode(' ', $lines[0], 3), 2, '');
    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower(trim($name))] = trim($value);
    }
    $length = (int) ($headers['content-length'] ?? 0);
    while (strlen($bodyStart) < $length) {
        $chunk = fread($connection, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $bodyStart .= $chunk;
    }
    $request = new CallbackRequest($method, $uri, strtolower($headers['content-type'] ?? ''), $bodyStart, $headers['x-ca-signature'] ?? null);
    $verdict = $verifier->verify($request->signedMessage($callbackOrigin), $request->signatureHeader);
    $fields = $request->fields() ?? [];
    $reply = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 4\r\nConnection: close\r\n\r\n*ok*";
    if ($verdict !== Verdict::Valid || ($fields['address_in'] ?? '') !== $created->addressIn) {
        step('ignored a callback: ' . $verdict->value);
        $reply = "HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    } elseif (($fields['pending'] ?? '') === '1') {
        $sawPending = true;
        step('pending callback verified, uuid ' . $fields['uuid']);
    } else {
        $confirmedValue = $fields['value_coin'] ?? '';
        $firstConfirmedUri = $uri;
        $firstConfirmedSignature = $request->signatureHeader;
        step('confirmed callback verified, uuid ' . $fields['uuid'] . ' value_coin ' . $confirmedValue);
    }
    fwrite($connection, $reply);
    fclose($connection);
}

if ($confirmedValue === null) {
    fail('no verified confirmed callback within ' . $options['timeout'] . ' seconds');
}
if (!Decimal::isValid($confirmedValue) || Decimal::compare($confirmedValue, $options['amount']) < 0) {
    fail('confirmed value ' . $confirmedValue . ' is below the expected ' . $options['amount']);
}

$tampered = new CallbackRequest('GET', str_replace('value_coin=', 'value_coin=9', (string) $firstConfirmedUri), '', '', $firstConfirmedSignature);
if ($verifier->verify($tampered->signedMessage($callbackOrigin), $tampered->signatureHeader) !== Verdict::InvalidSignature && str_starts_with((string) $firstConfirmedUri, '/hook?')) {
    fail('a tampered callback was not rejected');
}
step('tampered callback rejected');
step('OK pending-seen=' . ($sawPending ? 'yes' : 'no') . ' confirmed=' . $confirmedValue);
