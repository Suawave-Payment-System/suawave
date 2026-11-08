<?php

declare(strict_types=1);

namespace Suawave;

final class CallbackRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $requestUri,
        public readonly string $contentType,
        public readonly string $rawBody,
        public readonly ?string $signatureHeader
    ) {
    }

    public static function fromServer(array $server, string $rawBody): self
    {
        $signature = $server['HTTP_X_CA_SIGNATURE'] ?? null;
        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            (string) ($server['REQUEST_URI'] ?? ''),
            strtolower((string) ($server['CONTENT_TYPE'] ?? '')),
            $rawBody,
            is_string($signature) ? $signature : null
        );
    }

    public function signedMessage(string $externalOrigin): string
    {
        if ($this->method === 'POST') {
            return $this->rawBody;
        }
        return rtrim($externalOrigin, '/') . $this->requestUri;
    }

    public function fields(): ?array
    {
        if ($this->method === 'GET') {
            $query = (string) parse_url($this->requestUri, PHP_URL_QUERY);
            parse_str($query, $fields);
            return self::onlyStrings($fields);
        }
        if ($this->method !== 'POST') {
            return null;
        }
        if (str_contains($this->contentType, 'application/json')) {
            $decoded = json_decode($this->rawBody, true);
            return is_array($decoded) ? self::onlyStrings($decoded) : null;
        }
        parse_str($this->rawBody, $fields);
        return self::onlyStrings($fields);
    }

    private static function onlyStrings(array $fields): ?array
    {
        foreach ($fields as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                return null;
            }
        }
        return $fields;
    }
}
