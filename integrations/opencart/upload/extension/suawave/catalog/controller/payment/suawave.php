<?php

namespace Opencart\Catalog\Controller\Extension\Suawave\Payment;

require_once DIR_EXTENSION . 'suawave/system/library/suawave/autoload.php';

use Suawave\CallbackProcessor;
use Suawave\CallbackRequest;
use Suawave\CheckoutService;
use Suawave\CurlTransport;
use Suawave\Decimal;
use Suawave\GatewayClient;
use Suawave\HttpTransport;
use Suawave\InvalidPublicKey;
use Suawave\OpenCartOrderGateway;
use Suawave\ExpiryNotifier;
use Suawave\OpenCartPaymentStore;
use Suawave\PaymentPage;
use Suawave\PaymentStore;
use Suawave\PaymentRecord;
use Suawave\SignatureVerifier;
use Throwable;

class Suawave extends \Opencart\System\Engine\Controller
{
    private const AMOUNT_PLACES = 2;

    public function index(): string
    {
        $this->load->language('extension/suawave/payment/suawave');
        $this->sweepExpired();
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
                $page = $this->paymentPage();
                $record = $this->checkoutService()->begin(
                    $orderId,
                    $amount,
                    fn (PaymentRecord $attempt, bool $replaced) => $this->recordAttemptHistory($orderId, $attempt, $replaced, $page)
                );
                $json = $page->confirmPayload($record, time());
            } catch (\RuntimeException | \InvalidArgumentException $failure) {
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
        $record = null;
        if ($orderId > 0) {
            $this->sweepExpired();
            $store = $this->paymentStore();
            $record = $store->findPaidByOrderId($orderId) ?? $store->findByOrderId($orderId);
            if ($record !== null && $record->isOverdue(time())) {
                $this->expiryNotifier()->noteIfDue($record, time());
            }
        }
        $json = $this->paymentPage()->statusPayload($record, time());
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

    private function recordAttemptHistory(int $orderId, PaymentRecord $attempt, bool $replaced, PaymentPage $page): void
    {
        if ($attempt->status === PaymentRecord::PAID) {
            return;
        }
        $this->load->model('extension/suawave/payment/suawave');
        $order = $this->model_extension_suawave_payment_suawave->getOrderAmounts($orderId);
        if ($order === null || $order['order_status_id'] === (int) $this->config->get('payment_suawave_paid_status_id')) {
            return;
        }
        $comment = $page->historyComment($attempt);
        $this->load->model('checkout/order');
        if ($order['order_status_id'] === 0) {
            $this->model_checkout_order->addHistory($orderId, (int) $this->config->get('payment_suawave_pending_status_id'), $comment, $comment !== '');
        } elseif ($replaced && $comment !== '') {
            $this->model_checkout_order->addHistory($orderId, $order['order_status_id'], $comment, true);
        }
    }

    private function paymentPage(): PaymentPage
    {
        $this->load->language('extension/suawave/payment/suawave');
        $texts = [];
        foreach (['text_pay_before', 'text_closed'] as $key) {
            $texts[$key] = $this->language->get($key);
        }
        $zone = (string) $this->config->get('config_timezone');
        try {
            $timezone = new \DateTimeZone($zone !== '' ? $zone : 'UTC');
        } catch (\Exception) {
            $timezone = new \DateTimeZone('UTC');
        }
        return new PaymentPage($texts, $timezone);
    }

    private function expiryNotifier(): ExpiryNotifier
    {
        $page = $this->paymentPage();
        $this->load->model('checkout/order');
        return new ExpiryNotifier(
            $this->paymentStore(),
            new OpenCartOrderGateway(
                $this->model_checkout_order,
                (int) $this->config->get('payment_suawave_paid_status_id'),
                $this->db,
                DB_PREFIX,
                (string) $this->config->get('payment_suawave_ticker'),
                (string) $this->config->get('payment_suawave_order_currency'),
                self::AMOUNT_PLACES
            ),
            fn (PaymentRecord $record): string => $page->closedText($record)
        );
    }

    private function sweepExpired(): void
    {
        try {
            $this->expiryNotifier()->sweep(time());
        } catch (\Throwable $failure) {
            $this->log->write('suawave: expiry sweep failed: ' . $failure->getMessage());
        }
    }

    private function respond(int $httpStatus, string $body): void
    {
        http_response_code($httpStatus);
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->setOutput($body);
    }

    protected function paymentStore(): PaymentStore
    {
        return new OpenCartPaymentStore($this->db, DB_PREFIX);
    }

    protected function httpTransport(): HttpTransport
    {
        return new CurlTransport();
    }

    private function checkoutService(): CheckoutService
    {
        $callbackBase = rtrim((string) $this->config->get('payment_suawave_external_origin'), '/')
            . '/index.php?route=extension/suawave/payment/suawave.callback';
        return new CheckoutService(
            new GatewayClient((string) $this->config->get('payment_suawave_base_url'), $this->httpTransport()),
            $this->paymentStore(),
            (string) $this->config->get('payment_suawave_ticker'),
            (string) $this->config->get('payment_suawave_payout_address'),
            $callbackBase
        );
    }
}
