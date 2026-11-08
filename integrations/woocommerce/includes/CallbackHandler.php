<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class CallbackHandler
{
    public function __construct(
        private string $publicKeyPem,
        private string $trustedOrigin,
        private CallbackProcessor $processor
    ) {
    }

    public function handle(string $method, string $requestUri, string $rawBody, string $contentType, string $signature): CallbackResult
    {
        if (!CallbackVerifier::verify($method, $requestUri, $rawBody, $signature, $this->publicKeyPem, $this->trustedOrigin)) {
            return CallbackResult::rejected(401, 'invalid signature');
        }
        $query = CallbackParser::parseQuery($requestUri);
        $fields = $method === 'GET' ? $query : CallbackParser::parseBody($contentType, $rawBody);
        if ($query === null || $fields === null) {
            return CallbackResult::rejected(400, 'unreadable callback');
        }
        return $this->processor->process($query, $fields);
    }
}
