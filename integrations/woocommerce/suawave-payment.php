<?php
/**
 * Plugin Name: Suawave Payments
 * Plugin URI: https://suawave.com
 * Description: Accept stablecoin payments such as USDT in WooCommerce. An order is paid only after a signed callback verifies.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * Author: Suawave
 * Author URI: https://suawave.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: suawave-payment
 * WC requires at least: 8.0
 * WC tested up to: 11.2
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/autoload.php';

Suawave\CallbackEndpoint::register();

add_action('before_woocommerce_init', static function (): void {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
        $gateways[] = Suawave\WooGateway::class;
        return $gateways;
    });
    add_action(Suawave\WooOrderStore::EXPIRY_HOOK, static function ($orderId): void {
        Suawave\WooPayments::live()->closeFromSchedule($orderId);
    });
});

add_action('woocommerce_blocks_loaded', static function (): void {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry): void {
        $registry->register(new Suawave\WooBlocks(__FILE__));
    });
});

function suawave_create_invoice(string $reference, string $amount): array
{
    return Suawave\Invoices::create($reference, $amount);
}

function suawave_invoice_is_paid(string $reference): bool
{
    return Suawave\Invoices::isPaid($reference);
}
