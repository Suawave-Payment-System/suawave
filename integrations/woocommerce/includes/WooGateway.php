<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class WooGateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = WooOrderStore::GATEWAY_ID;
        $this->method_title = 'Suawave payment';
        $this->method_description = 'Accept stablecoin payments through your payment gateway. Orders are marked paid only from a signed callback.';
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'renderInstructions']);
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
            $nonce = bin2hex(random_bytes(16));
            $created = $client->createAddress($ticker, $this->get_option('payout_address'), Settings::callbackUrl('woo', (string) $order->get_id(), $nonce));
        } catch (GatewayException $failure) {
            return $this->fail('The crypto payment could not be started: ' . $failure->getMessage());
        }
        if (Money::compare($amount, $created['minimum']) < 0) {
            return $this->fail(sprintf('The amount %s is below the minimum deposit of %s.', $amount, $created['minimum']));
        }
        $order->update_meta_data('_suawave_nonce', $nonce);
        $order->update_meta_data('_suawave_address', $created['address_in']);
        $order->update_meta_data('_suawave_coin', str_replace('/', '_', $ticker));
        $order->update_meta_data('_suawave_ticker', $ticker);
        $order->update_meta_data('_suawave_amount', $amount);
        $order->update_meta_data('_suawave_minimum', $created['minimum']);
        $order->update_meta_data('_suawave_received', '0');
        $order->update_status('on-hold', 'Waiting for the crypto deposit.');
        $order->save();
        wc_reduce_stock_levels($order->get_id());
        WC()->cart->empty_cart();
        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    public function renderInstructions($orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order && $order->get_payment_method() === $this->id) {
            echo wp_kses($this->instructionsHtml($order, true), self::allowedHtml(), self::allowedProtocols());
        }
    }

    public function renderEmailInstructions($order, $sentToAdmin, $plainText): void
    {
        if (!$sentToAdmin && !$plainText && $order instanceof \WC_Order && $order->get_payment_method() === $this->id && $order->has_status('on-hold')) {
            echo wp_kses($this->instructionsHtml($order, false), self::allowedHtml(), self::allowedProtocols());
        }
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

    private function instructionsHtml(\WC_Order $order, bool $withQr): string
    {
        $address = (string) $order->get_meta('_suawave_address');
        $amount = (string) $order->get_meta('_suawave_amount');
        if ($address === '' || $amount === '') {
            return '';
        }
        $ticker = (string) $order->get_meta('_suawave_ticker');
        $symbol = strtoupper(substr($ticker, (int) strpos($ticker, '/') + 1));
        $received = (string) $order->get_meta('_suawave_received');
        $html = '<section class="suawave-payment-instructions"><h2>Suawave payment</h2>';
        if ($order->is_paid()) {
            return $html . '<p>Payment received. Thank you.</p></section>';
        }
        $html .= '<ul>';
        $html .= '<li>Send exactly: <strong>' . esc_html($amount . ' ' . $symbol) . '</strong></li>';
        $html .= '<li>To address: <strong style="word-break:break-all">' . esc_html($address) . '</strong></li>';
        $html .= '<li>Network: ' . esc_html($ticker) . '</li>';
        $html .= '<li>Minimum deposit: ' . esc_html((string) $order->get_meta('_suawave_minimum') . ' ' . $symbol) . '</li>';
        if ($received !== '' && $received !== '0') {
            $html .= '<li>Received so far: ' . esc_html($received . ' ' . $symbol) . '</li>';
        }
        $html .= '</ul>';
        $image = $withQr ? $this->qrImage($order, $ticker, $address, $amount) : null;
        if ($image !== null) {
            $html .= '<p><img alt="Payment QR code" width="256" height="256" src="' . esc_attr($image) . '"></p>';
        }
        return $html . '<p>This page does not update by itself. The order is marked paid once the gateway confirms your deposit.</p></section>';
    }

    private function qrImage(\WC_Order $order, string $ticker, string $address, string $amount): ?string
    {
        $cached = (string) $order->get_meta('_suawave_qr');
        if ($cached !== '') {
            return $cached;
        }
        $image = Settings::client()->qrCode($ticker, $address, $amount);
        if ($image !== null) {
            $order->update_meta_data('_suawave_qr', $image);
            $order->save();
        }
        return $image;
    }
}
