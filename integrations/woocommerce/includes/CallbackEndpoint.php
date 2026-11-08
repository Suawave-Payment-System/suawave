<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class CallbackEndpoint
{
    public const HOOK_PRIORITY = 20;

    public static function register(): void
    {
        add_action('init', [self::class, 'maybeHandle'], self::HOOK_PRIORITY);
    }

    public static function maybeHandle(): void
    {
        if (!isset($_GET['suawave_callback'])) {
            return;
        }
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $query = CallbackParser::parseQuery($requestUri);
        global $wpdb;
        $store = CallbackProcessor::sourceOf($query) === 'wp' ? new WpInvoiceStore() : new WooOrderStore();
        $handler = new CallbackHandler(
            Settings::get('callback_public_key'),
            Settings::trustedOrigin(),
            new CallbackProcessor($store, new WpLedger($wpdb), new WpOrderLock($wpdb))
        );
        $result = $handler->handle(
            (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            $requestUri,
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
            (string) ($_SERVER['HTTP_X_CA_SIGNATURE'] ?? '')
        );
        status_header($result->status);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html($result->body);
        exit;
    }
}
