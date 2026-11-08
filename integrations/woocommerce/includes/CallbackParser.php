<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class CallbackParser
{
    public static function parseQuery(string $requestUri): ?array
    {
        $position = strpos($requestUri, '?');
        if ($position === false) {
            return [];
        }
        return self::parsePairs(substr($requestUri, $position + 1));
    }

    public static function parseBody(string $contentType, string $rawBody): ?array
    {
        if (stripos($contentType, 'application/json') === false) {
            return self::parsePairs($rawBody);
        }
        $decoded = json_decode($rawBody, true, 8);
        if (!is_array($decoded)) {
            return null;
        }
        foreach ($decoded as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                return null;
            }
        }
        return $decoded;
    }

    private static function parsePairs(string $encoded): ?array
    {
        $pairs = [];
        foreach (explode('&', $encoded) as $part) {
            if ($part === '') {
                continue;
            }
            $separator = strpos($part, '=');
            $name = urldecode($separator === false ? $part : substr($part, 0, $separator));
            $value = $separator === false ? '' : urldecode(substr($part, $separator + 1));
            if (array_key_exists($name, $pairs)) {
                return null;
            }
            $pairs[$name] = $value;
        }
        return $pairs;
    }
}
