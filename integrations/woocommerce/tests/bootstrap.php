<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/Support/MemoryOrders.php';
require_once __DIR__ . '/Support/FakeDb.php';
require_once __DIR__ . '/Support/FakeWoo.php';

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
