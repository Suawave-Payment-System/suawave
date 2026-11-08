<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Suawave\GatewayException;
use Suawave\PrestashopRuntime;

class SuawaveValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        $cart = $this->context->cart;
        if (!$this->module->active
            || !PrestashopRuntime::isConfigured()
            || $cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
        ) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }
        $currency = new Currency((int) $cart->id_currency);
        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)
            || $currency->iso_code !== PrestashopRuntime::setting(PrestashopRuntime::CURRENCY)
        ) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        $total = $cart->getOrderTotal(true, Cart::BOTH);
        $amount = number_format($total, $this->context->getComputingPrecision(), '.', '');

        try {
            $record = PrestashopRuntime::checkout()->begin((int) $cart->id, $amount);
        } catch (GatewayException $failure) {
            PrestaShopLogger::addLog('suawave: ' . $failure->getMessage(), 3);
            $this->errors[] = $this->trans('The crypto payment could not be started. Please try again or choose another payment method.', [], 'Modules.Suawave.Shop');
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, ['step' => 3]));
            return;
        }

        $this->module->validateOrder(
            (int) $cart->id,
            (int) PrestashopRuntime::setting(PrestashopRuntime::AWAITING_STATE),
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );
        PrestashopRuntime::checkout()->attachOrder($record, (int) $this->module->currentOrder);

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $this->module->currentOrder,
            'key' => $customer->secure_key,
        ]));
    }
}
