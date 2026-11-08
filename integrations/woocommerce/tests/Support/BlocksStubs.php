<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
    abstract class AbstractPaymentMethodType
    {
        protected $name = '';
        protected $settings = [];

        public function get_name()
        {
            return $this->name;
        }

        protected function get_setting($name, $default = '')
        {
            return $this->settings[$name] ?? $default;
        }
    }
}

namespace {
    if (!class_exists('WC_Payment_Gateway')) {
        abstract class WC_Payment_Gateway
        {
            public $id = '';
            public $supports = [];
        }
    }

    $GLOBALS['suawave_test_options'] = [];
    $GLOBALS['suawave_test_scripts'] = [];

    if (!function_exists('get_option')) {
        function get_option(string $name, $default = false)
        {
            return $GLOBALS['suawave_test_options'][$name] ?? $default;
        }
    }

    if (!function_exists('plugins_url')) {
        function plugins_url(string $path, string $plugin): string
        {
            return 'https://shop.example/wp-content/plugins/suawave-payment/' . $path;
        }
    }

    if (!function_exists('wp_register_script')) {
        function wp_register_script(string $handle, string $src, array $deps, $version, $inFooter): bool
        {
            $GLOBALS['suawave_test_scripts'][$handle] = ['src' => $src, 'deps' => $deps];
            return true;
        }
    }
}
