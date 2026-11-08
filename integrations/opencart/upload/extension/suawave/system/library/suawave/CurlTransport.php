<?php

declare(strict_types=1);

namespace Suawave;

final class CurlTransport implements HttpTransport
{
    private const MAXIMUM_BODY_BYTES = 1048576;

    public function __construct(private readonly int $timeoutSeconds = 15)
    {
    }

    public function get(string $url): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new GatewayException('cannot start the HTTP client');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failure = curl_error($handle);
        curl_close($handle);
        if (!is_string($body)) {
            throw new GatewayException('the gateway is not reachable: ' . $failure);
        }
        if (strlen($body) > self::MAXIMUM_BODY_BYTES) {
            throw new GatewayException('the gateway answer is too large');
        }
        return new HttpResponse($status, $body);
    }
}
