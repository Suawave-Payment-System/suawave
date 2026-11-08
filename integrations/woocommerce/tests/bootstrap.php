<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/Support/MemoryOrders.php';
require_once __DIR__ . '/Support/FakeDb.php';

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
