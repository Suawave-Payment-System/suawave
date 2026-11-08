<?php

declare(strict_types=1);

namespace Suawave;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
    exit;
}

final class WooBlocks extends AbstractPaymentMethodType
{
    public const HANDLE = 'suawave-blocks';

    protected $name = WooOrderStore::GATEWAY_ID;

    private string $pluginFile;

    public function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
    }

    public function initialize(): void
    {
        $this->settings = Settings::all();
    }

    public function is_active(): bool
    {
        return $this->get_setting('enabled') === 'yes';
    }

    public function get_payment_method_script_handles(): array
    {
        $path = dirname($this->pluginFile) . '/assets/blocks.js';
        wp_register_script(
            self::HANDLE,
            plugins_url('assets/blocks.js', $this->pluginFile),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            is_file($path) ? (string) filemtime($path) : '1.0.0',
            true
        );
        return [self::HANDLE];
    }

    public function get_payment_method_data(): array
    {
        return [
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'supports' => WooGateway::SUPPORTS,
        ];
    }
}
