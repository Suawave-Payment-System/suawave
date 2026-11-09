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
        if (!$this->module->active || !PrestashopRuntime::isConfigured()) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }
        $orderId = (int) Tools::getValue('id_order');
        if ($orderId > 0) {
            $this->resumeOrder(new Order($orderId), (string) Tools::getValue('key'));
            return;
        }
        $cart = $this->context->cart;
        if ($cart->id_customer == 0
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
        $existing = (int) Order::getIdByCartId((int) $cart->id);
        if ($existing > 0) {
            $this->resumeOrder(new Order($existing), (string) $customer->secure_key);
            return;
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
            PrestashopRuntime::labels()->open($record->expiresAt),
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );
        PrestashopRuntime::checkout()->attachOrder($record, (int) $this->module->currentOrder);

        Tools::redirect($this->confirmationLink((int) $cart->id, (int) $this->module->currentOrder, (string) $customer->secure_key));
    }

    private function resumeOrder(Order $order, string $key): void
    {
        if (!Validate::isLoadedObject($order)
            || $order->module !== $this->module->name
            || $key === ''
            || !hash_equals((string) $order->secure_key, $key)
        ) {
            Tools::redirect($this->context->link->getPageLink('index', true));
        }
        $link = $this->confirmationLink((int) $order->id_cart, (int) $order->id, (string) $order->secure_key);
        if ((int) $order->getCurrentState() !== (int) PrestashopRuntime::setting(PrestashopRuntime::AWAITING_STATE)) {
            Tools::redirect($link);
        }
        try {
            $record = PrestashopRuntime::checkout()->resume((int) $order->id_cart);
            if ($record !== null && $record->orderId !== (int) $order->id) {
                PrestashopRuntime::checkout()->attachOrder($record, (int) $order->id);
            }
        } catch (GatewayException $failure) {
            PrestaShopLogger::addLog('suawave: ' . $failure->getMessage(), 3);
            $this->errors[] = $this->trans('A new payment address could not be created. Please try again later or contact the shop.', [], 'Modules.Suawave.Shop');
            $this->redirectWithNotifications($link);
            return;
        }
        Tools::redirect($link);
    }

    private function confirmationLink(int $cartId, int $orderId, string $key): string
    {
        return $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => $cartId,
            'id_module' => (int) $this->module->id,
            'id_order' => $orderId,
            'key' => $key,
        ]);
    }
}
