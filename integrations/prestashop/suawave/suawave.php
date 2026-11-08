<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

use Suawave\CurlTransport;
use Suawave\GatewayClient;
use Suawave\GatewayException;
use Suawave\InvalidPublicKey;
use Suawave\PaymentRecord;
use Suawave\PrestashopPublicKeyProvider;
use Suawave\PrestashopRuntime;
use Suawave\SignatureVerifier;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Suawave extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'suawave';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Suawave';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.7.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = $this->trans('Suawave', [], 'Modules.Suawave.Admin');
        $this->description = $this->trans('Accept stablecoin payments such as USDT in PrestaShop. An order is paid only after a signed callback verifies.', [], 'Modules.Suawave.Admin');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->createTables()
            && $this->createAwaitingState();
    }

    public function uninstall(): bool
    {
        foreach ([
            PrestashopRuntime::API_URL,
            PrestashopRuntime::TICKER,
            PrestashopRuntime::PAYOUT,
            PrestashopRuntime::ORIGIN,
            PrestashopRuntime::CURRENCY,
            PrestashopPublicKeyProvider::SETTING,
        ] as $name) {
            Configuration::deleteByName($name);
        }
        return parent::uninstall();
    }

    public function hookPaymentOptions(array $params): array
    {
        if (!$this->active || !PrestashopRuntime::isConfigured()) {
            return [];
        }
        $currency = new Currency((int) $params['cart']->id_currency);
        if ($currency->iso_code !== PrestashopRuntime::setting(PrestashopRuntime::CURRENCY)) {
            return [];
        }
        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->trans('Pay with Suawave', [], 'Modules.Suawave.Shop'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true));
        return [$option];
    }

    public function hookPaymentReturn(array $params): string
    {
        if (!$this->active || !isset($params['order']) || $params['order']->module !== $this->name) {
            return '';
        }
        $record = PrestashopRuntime::store()->findByOrderId((int) $params['order']->id);
        if ($record === null) {
            return '';
        }
        $this->context->smarty->assign([
            'suawave_amount' => $record->amount,
            'suawave_received' => $record->received,
            'suawave_ticker' => strtoupper($record->ticker),
            'suawave_address' => $record->addressIn,
            'suawave_minimum' => $record->minimum,
            'suawave_paid' => $record->status === PaymentRecord::PAID,
            'suawave_detected' => $record->status === PaymentRecord::DETECTED,
            'suawave_qr' => $this->qrFor($record),
        ]);
        return $this->fetch('module:suawave/views/templates/hook/payment_return.tpl');
    }

    public function getContent(): string
    {
        $messages = '';
        if (Tools::isSubmit('submitSuawave')) {
            $messages = $this->saveSettings();
        }
        return $messages . $this->renderForm();
    }

    private function qrFor(PaymentRecord $record): string
    {
        if ($record->status === PaymentRecord::PAID) {
            return '';
        }
        try {
            return PrestashopRuntime::client()->qrCode($record->ticker, $record->addressIn, $record->amount);
        } catch (GatewayException) {
            return '';
        }
    }

    private function saveSettings(): string
    {
        $values = [
            PrestashopRuntime::API_URL => rtrim(trim((string) Tools::getValue(PrestashopRuntime::API_URL)), '/'),
            PrestashopRuntime::TICKER => strtolower(trim((string) Tools::getValue(PrestashopRuntime::TICKER))),
            PrestashopRuntime::PAYOUT => trim((string) Tools::getValue(PrestashopRuntime::PAYOUT)),
            PrestashopRuntime::ORIGIN => rtrim(trim((string) Tools::getValue(PrestashopRuntime::ORIGIN)), '/'),
            PrestashopRuntime::CURRENCY => strtoupper(trim((string) Tools::getValue(PrestashopRuntime::CURRENCY))),
        ];
        $pastedKey = trim((string) Tools::getValue(PrestashopPublicKeyProvider::SETTING));
        $problem = $this->settingsProblem($values);
        if ($problem !== null) {
            return $this->displayError($problem);
        }
        try {
            $key = $pastedKey !== ''
                ? $pastedKey
                : (new GatewayClient($values[PrestashopRuntime::API_URL], new CurlTransport()))->publicKey();
            new SignatureVerifier($key);
        } catch (InvalidPublicKey | GatewayException $failure) {
            return $this->displayError($this->trans('The callback public key was refused: ', [], 'Modules.Suawave.Admin') . $failure->getMessage());
        }
        foreach ($values as $name => $value) {
            Configuration::updateValue($name, $value);
        }
        Configuration::updateValue(PrestashopPublicKeyProvider::SETTING, $key);
        return $this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Suawave.Admin'));
    }

    private function settingsProblem(array $values): ?string
    {
        $apiUrl = parse_url($values[PrestashopRuntime::API_URL]);
        if (!is_array($apiUrl) || !in_array($apiUrl['scheme'] ?? '', ['http', 'https'], true) || ($apiUrl['host'] ?? '') === '') {
            return $this->trans('The gateway API base URL must start with https://.', [], 'Modules.Suawave.Admin');
        }
        if (preg_match('/^[a-z0-9]+\/[a-z0-9]+$/D', $values[PrestashopRuntime::TICKER]) !== 1) {
            return $this->trans('The ticker must look like trc20/usdt.', [], 'Modules.Suawave.Admin');
        }
        if ($values[PrestashopRuntime::PAYOUT] === '') {
            return $this->trans('The payout address is required.', [], 'Modules.Suawave.Admin');
        }
        $origin = parse_url($values[PrestashopRuntime::ORIGIN]);
        if (!is_array($origin)
            || !in_array($origin['scheme'] ?? '', ['http', 'https'], true)
            || ($origin['host'] ?? '') === ''
            || isset($origin['path'])
            || isset($origin['query'])
        ) {
            return $this->trans('The shop public origin must be scheme and host only, for example https://shop.example.', [], 'Modules.Suawave.Admin');
        }
        if (preg_match('/^[A-Z]{3}$/D', $values[PrestashopRuntime::CURRENCY]) !== 1) {
            return $this->trans('The order currency must be a three letter code such as USD.', [], 'Modules.Suawave.Admin');
        }
        return null;
    }

    private function renderForm(): string
    {
        $text = static fn (string $name, string $label, string $hint): array => [
            'type' => 'text', 'name' => $name, 'label' => $label, 'desc' => $hint,
        ];
        $form = [
            'form' => [
                'legend' => ['title' => $this->trans('Suawave', [], 'Modules.Suawave.Admin')],
                'input' => [
                    $text(PrestashopRuntime::API_URL, 'Gateway API base URL', 'For example https://api.suawave.com, no trailing path.'),
                    $text(PrestashopRuntime::TICKER, 'Asset and chain (ticker)', 'For example trc20/usdt or bep20/usdt.'),
                    $text(PrestashopRuntime::PAYOUT, 'Payout address', 'Where the gateway forwards the funds. Immutable per ticker and callback once created.'),
                    $text(PrestashopRuntime::ORIGIN, 'Shop public origin', 'The https origin exactly as the gateway reaches this shop, for example https://shop.example.'),
                    $text(PrestashopRuntime::CURRENCY, 'Order currency', 'The method is offered only for carts in this currency; the token amount equals the cart total one to one.'),
                    [
                        'type' => 'textarea', 'name' => PrestashopPublicKeyProvider::SETTING,
                        'label' => 'Callback public key',
                        'desc' => 'Leave empty to fetch it from the gateway when saving. RSA, at least 2048 bits.',
                    ],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ],
        ];
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSuawave';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->fields_value = [
            PrestashopRuntime::API_URL => PrestashopRuntime::setting(PrestashopRuntime::API_URL),
            PrestashopRuntime::TICKER => PrestashopRuntime::setting(PrestashopRuntime::TICKER),
            PrestashopRuntime::PAYOUT => PrestashopRuntime::setting(PrestashopRuntime::PAYOUT),
            PrestashopRuntime::ORIGIN => PrestashopRuntime::setting(PrestashopRuntime::ORIGIN),
            PrestashopRuntime::CURRENCY => PrestashopRuntime::setting(PrestashopRuntime::CURRENCY),
            PrestashopPublicKeyProvider::SETTING => PrestashopRuntime::setting(PrestashopPublicKeyProvider::SETTING),
        ];
        return $helper->generateForm([$form]);
    }

    private function createTables(): bool
    {
        $prefix = _DB_PREFIX_;
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . $prefix . 'suawave_payment` ('
            . '`id_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`id_cart` INT UNSIGNED NOT NULL, '
            . '`id_order` INT UNSIGNED NOT NULL DEFAULT 0, '
            . '`nonce` VARCHAR(64) NOT NULL, '
            . '`ticker` VARCHAR(32) NOT NULL, '
            . '`address_in` VARCHAR(128) NOT NULL, '
            . '`payout_address` VARCHAR(128) NOT NULL, '
            . '`amount` VARCHAR(64) NOT NULL, '
            . '`minimum` VARCHAR(64) NOT NULL, '
            . "`received` VARCHAR(64) NOT NULL DEFAULT '0', "
            . '`status` VARCHAR(16) NOT NULL, '
            . '`date_add` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`id_payment`), '
            . 'UNIQUE KEY `address_in` (`address_in`), '
            . 'KEY `id_cart` (`id_cart`), '
            . 'KEY `id_order` (`id_order`)'
            . ') ' . $engine
        ) && Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . $prefix . 'suawave_event` ('
            . '`uuid` VARCHAR(64) NOT NULL, '
            . '`address_in` VARCHAR(128) NOT NULL, '
            . '`date_add` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`uuid`)'
            . ') ' . $engine
        );
    }

    private function createAwaitingState(): bool
    {
        $existing = new OrderState((int) Configuration::get(PrestashopRuntime::AWAITING_STATE));
        if (Validate::isLoadedObject($existing)) {
            return true;
        }
        $state = new OrderState();
        $state->name = [];
        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = 'Awaiting crypto payment';
        }
        $state->color = '#4169E1';
        $state->logable = false;
        $state->paid = false;
        $state->invoice = false;
        $state->hidden = false;
        $state->send_email = false;
        $state->delivery = false;
        $state->unremovable = true;
        $state->module_name = $this->name;
        if (!$state->add()) {
            return false;
        }
        return Configuration::updateValue(PrestashopRuntime::AWAITING_STATE, (int) $state->id);
    }
}
