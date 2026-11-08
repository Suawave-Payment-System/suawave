<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

class CurlTransport implements HttpTransport
{
    private const TIMEOUT_SECONDS = 15;
    private const MAXIMUM_BODY_BYTES = 1048576;

    public function get(string $url): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new GatewayException('cannot initialise the HTTP client');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = $body === false;
        $message = curl_error($handle);
        curl_close($handle);
        if ($failed) {
            throw new GatewayException('gateway request failed: ' . $message);
        }
        if (strlen($body) > self::MAXIMUM_BODY_BYTES) {
            throw new GatewayException('gateway response is too large');
        }
        return new HttpResponse($status, $body);
    }
}
