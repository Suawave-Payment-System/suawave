<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class WooGateway extends \WC_Payment_Gateway
{
    public const SUPPORTS = ['products'];

    public function __construct()
    {
        $this->id = WooOrderStore::GATEWAY_ID;
        $this->method_title = 'Suawave payment';
        $this->method_description = 'Accept stablecoin payments through your payment gateway. Orders are marked paid only from a signed callback.';
        $this->has_fields = false;
        $this->supports = self::SUPPORTS;
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'renderInstructions']);
        add_action('woocommerce_view_order', [$this, 'renderInstructions'], 5);
        add_action('woocommerce_email_before_order_table', [$this, 'renderEmailInstructions'], 10, 3);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => ['title' => 'Enable', 'type' => 'checkbox', 'label' => 'Enable Suawave payments', 'default' => 'no'],
            'title' => ['title' => 'Title', 'type' => 'text', 'default' => 'Pay with Suawave (USDT)'],
            'description' => ['title' => 'Description', 'type' => 'textarea', 'default' => 'You will receive the deposit address and the exact amount after placing the order.'],
            'api_base_url' => ['title' => 'Gateway API base URL', 'type' => 'text', 'description' => 'For example https://api.suawave.com, without a trailing slash.', 'default' => 'https://api.suawave.com'],
            'ticker' => ['title' => 'Asset and chain', 'type' => 'text', 'description' => 'Lowercase ticker with slash: trc20/usdt or bep20/usdt.', 'default' => 'trc20/usdt'],
            'payout_address' => ['title' => 'Payout address', 'type' => 'text', 'description' => 'Your wallet address on that chain. Deposits are forwarded here.', 'default' => ''],
            'callback_public_key' => ['title' => 'Callback public key', 'type' => 'textarea', 'description' => 'PEM from GET {base URL}/pubkey/ (the pubkey field). RSA, at least 2048 bits.', 'default' => '', 'css' => 'min-width:420px;height:160px;font-family:monospace;'],
            'external_origin' => ['title' => 'Public site origin', 'type' => 'text', 'description' => 'Scheme and host (and port) the gateway uses to reach this site, for example https://shop.example. Leave empty to use the WordPress home URL. Callbacks signed over a different origin are rejected.', 'default' => ''],
            'price_mode' => ['title' => 'Price', 'type' => 'select', 'options' => ['same' => 'Order total equals the asset amount (store currency is pegged 1:1)', 'convert' => 'Convert the order total with the gateway rate'], 'default' => 'same'],
        ];
    }

    public function validate_callback_public_key_field($key, $value): string
    {
        $pem = trim((string) $value);
        if ($pem !== '' && !CallbackVerifier::isUsableKey($pem)) {
            \WC_Admin_Settings::add_error('The callback public key is not a valid RSA public key of at least 2048 bits.');
            return (string) $this->get_option('callback_public_key');
        }
        return $pem;
    }

    public function validate_ticker_field($key, $value): string
    {
        $ticker = strtolower(trim((string) $value));
        if (!GatewayClient::isValidTicker($ticker)) {
            \WC_Admin_Settings::add_error('The asset and chain must look like trc20/usdt.');
            return (string) $this->get_option('ticker');
        }
        return $ticker;
    }

    public function is_available(): bool
    {
        return parent::is_available()
            && extension_loaded('bcmath')
            && extension_loaded('openssl')
            && $this->get_option('api_base_url') !== ''
            && $this->get_option('payout_address') !== ''
            && CallbackVerifier::isUsableKey($this->get_option('callback_public_key'));
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return $this->fail('Order not found.');
        }
        $ticker = $this->get_option('ticker');
        try {
            $client = Settings::client();
            $amount = $this->amountFor($client, $ticker, (string) $order->get_total(), $order->get_currency());
            $outcome = WooPayments::live()->open($order, $client, $ticker, $this->get_option('payout_address'), $amount);
        } catch (LockUnavailable $busy) {
            return $this->fail('The order is being updated. Please try again in a moment.');
        } catch (GatewayException $failure) {
            return $this->fail('The crypto payment could not be started: ' . $failure->getMessage());
        }
        if ($outcome === WooPayments::ALREADY_PAID) {
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }
        wc_maybe_reduce_stock_levels($order->get_id());
        if (function_exists('WC') && WC()->cart !== null) {
            WC()->cart->empty_cart();
        }
        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    public function renderInstructions($orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order && $order->get_payment_method() === $this->id) {
            echo wp_kses(WooPayments::live()->html($order, true, time(), wp_timezone()), self::allowedHtml(), self::allowedProtocols());
        }
    }

    public function renderEmailInstructions($order, $sentToAdmin, $plainText): void
    {
        if ($sentToAdmin || !$order instanceof \WC_Order || $order->get_payment_method() !== $this->id || !$order->has_status(['pending', 'on-hold', 'processing', 'completed'])) {
            return;
        }
        if ($plainText) {
            echo esc_html(WooPayments::live()->text($order, time(), wp_timezone()));
            return;
        }
        echo wp_kses(WooPayments::live()->html($order, false, time(), wp_timezone(), true), self::allowedHtml(), self::allowedProtocols());
    }

    private static function allowedHtml(): array
    {
        return [
            'section' => ['class' => true],
            'h2' => [],
            'ul' => [],
            'li' => [],
            'p' => [],
            'strong' => ['style' => true],
            'img' => ['alt' => true, 'width' => true, 'height' => true, 'src' => true],
        ];
    }

    private static function allowedProtocols(): array
    {
        return array_merge(wp_allowed_protocols(), ['data']);
    }

    private function amountFor(GatewayClient $client, string $ticker, string $orderTotal, string $currency): string
    {
        if (!Money::isPositive($orderTotal)) {
            throw new GatewayException('The order total is not a positive decimal amount.');
        }
        if ($this->get_option('price_mode') === 'convert') {
            return $client->convert($ticker, $orderTotal, $currency);
        }
        return $orderTotal;
    }

    private function fail(string $message): array
    {
        wc_add_notice($message, 'error');
        return ['result' => 'failure'];
    }
}
