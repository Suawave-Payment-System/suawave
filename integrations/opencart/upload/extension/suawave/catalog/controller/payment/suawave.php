<?php

namespace Opencart\Catalog\Controller\Extension\Suawave\Payment;

require_once DIR_EXTENSION . 'suawave/system/library/suawave/autoload.php';

use Suawave\CallbackProcessor;
use Suawave\CallbackRequest;
use Suawave\CheckoutService;
use Suawave\CurlTransport;
use Suawave\Decimal;
use Suawave\GatewayClient;
use Suawave\GatewayException;
use Suawave\InvalidPublicKey;
use Suawave\OpenCartOrderGateway;
use Suawave\OpenCartPaymentStore;
use Suawave\SignatureVerifier;
use Throwable;

class Suawave extends \Opencart\System\Engine\Controller
{
    private const AMOUNT_PLACES = 2;

    public function index(): string
    {
        $this->load->language('extension/suawave/payment/suawave');
        $data['confirm'] = $this->url->link('extension/suawave/payment/suawave.confirm', 'language=' . $this->config->get('config_language'));
        $data['status'] = $this->url->link('extension/suawave/payment/suawave.status', 'language=' . $this->config->get('config_language'));
        $data['success'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'));
        $data['asset'] = (string) $this->config->get('payment_suawave_ticker');
        $data['qr_script'] = 'extension/suawave/catalog/view/javascript/qrcode.js';
        return $this->load->view('extension/suawave/payment/suawave', $data);
    }

    public function confirm(): void
    {
        $this->load->language('extension/suawave/payment/suawave');
        $json = [];

        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        $method = (string) ($this->session->data['payment_method']['code'] ?? '');
        $this->load->model('extension/suawave/payment/suawave');
        $amounts = $orderId > 0 ? $this->model_extension_suawave_payment_suawave->getOrderAmounts($orderId) : null;

        if ($amounts === null) {
            $json['error'] = $this->language->get('error_order');
        } elseif (!str_starts_with($method, 'suawave')) {
            $json['error'] = $this->language->get('error_method');
        } elseif ($amounts['currency_code'] !== (string) $this->config->get('payment_suawave_order_currency')) {
            $json['error'] = $this->language->get('error_currency');
        }

        if (!$json) {
            try {
                $amount = Decimal::fromOrderTotal($amounts['total'], $amounts['currency_value'], self::AMOUNT_PLACES);
                $record = $this->checkoutService()->begin($orderId, $amount);
                if ($amounts['order_status_id'] === 0) {
                    $this->load->model('checkout/order');
                    $this->model_checkout_order->addHistory($orderId, (int) $this->config->get('payment_suawave_pending_status_id'), '', false);
                }
                $json['amount'] = $record->amount;
                $json['address'] = $record->addressIn;
                $json['asset'] = $record->ticker;
                $json['qr_text'] = $record->addressIn;
            } catch (GatewayException | \InvalidArgumentException $failure) {
                $this->log->write('suawave: ' . $failure->getMessage());
                $json['error'] = $this->language->get('error_gateway');
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function status(): void
    {
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        $json = ['status' => 'unknown'];
        if ($orderId > 0) {
            $record = $this->paymentStore()->findByOrderId($orderId);
            if ($record !== null) {
                $json['status'] = $record->status;
            }
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function callback(): void
    {
        $request = CallbackRequest::fromServer($_SERVER, (string) file_get_contents('php://input'));
        try {
            $verifier = new SignatureVerifier((string) $this->config->get('payment_suawave_public_key'));
        } catch (InvalidPublicKey $failure) {
            $this->log->write('suawave: ' . $failure->getMessage());
            $this->respond(500, 'misconfigured');
            return;
        }
        $this->load->model('checkout/order');
        $processor = new CallbackProcessor(
            $verifier,
            (string) $this->config->get('payment_suawave_external_origin'),
            $this->paymentStore(),
            new OpenCartOrderGateway(
                $this->model_checkout_order,
                (int) $this->config->get('payment_suawave_paid_status_id'),
                $this->db,
                DB_PREFIX,
                (string) $this->config->get('payment_suawave_ticker'),
                (string) $this->config->get('payment_suawave_order_currency'),
                self::AMOUNT_PLACES
            )
        );
        $outcome = $processor->process($request);
        if ($outcome->httpStatus !== 200) {
            $this->log->write('suawave: callback refused: ' . $outcome->reason);
        }
        $this->respond($outcome->httpStatus, $outcome->body);
    }

    private function respond(int $httpStatus, string $body): void
    {
        http_response_code($httpStatus);
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->setOutput($body);
    }

    private function paymentStore(): OpenCartPaymentStore
    {
        return new OpenCartPaymentStore($this->db, DB_PREFIX);
    }

    private function checkoutService(): CheckoutService
    {
        $callbackBase = rtrim((string) $this->config->get('payment_suawave_external_origin'), '/')
            . '/index.php?route=extension/suawave/payment/suawave.callback';
        return new CheckoutService(
            new GatewayClient((string) $this->config->get('payment_suawave_base_url'), new CurlTransport()),
            $this->paymentStore(),
            (string) $this->config->get('payment_suawave_ticker'),
            (string) $this->config->get('payment_suawave_payout_address'),
            $callbackBase
        );
    }
}
