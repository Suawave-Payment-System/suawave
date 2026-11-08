<?php

namespace Opencart\Admin\Controller\Extension\Suawave\Payment;

require_once DIR_EXTENSION . 'suawave/system/library/suawave/autoload.php';

use Suawave\CurlTransport;
use Suawave\GatewayClient;
use Suawave\GatewayException;
use Suawave\InvalidPublicKey;
use Suawave\SignatureVerifier;

class Suawave extends \Opencart\System\Engine\Controller
{
    private const SETTING_KEYS = [
        'status',
        'base_url',
        'ticker',
        'payout_address',
        'public_key',
        'external_origin',
        'order_currency',
        'pending_status_id',
        'paid_status_id',
        'sort_order',
    ];

    public function index(): void
    {
        $this->load->language('extension/suawave/payment/suawave');
        $this->document->setTitle($this->language->get('heading_title'));
        $token = 'user_token=' . $this->session->data['user_token'];

        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
            ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/suawave/payment/suawave', $token)],
        ];
        $data['save'] = $this->url->link('extension/suawave/payment/suawave.save', $token);
        $data['back'] = $this->url->link('marketplace/extension', $token . '&type=payment');

        foreach (self::SETTING_KEYS as $key) {
            $data['payment_suawave_' . $key] = $this->config->get('payment_suawave_' . $key);
        }
        if ($data['payment_suawave_base_url'] === null) {
            $data['payment_suawave_base_url'] = 'https://api.suawave.com';
        }
        if ($data['payment_suawave_ticker'] === null) {
            $data['payment_suawave_ticker'] = 'trc20/usdt';
        }
        if ($data['payment_suawave_order_currency'] === null) {
            $data['payment_suawave_order_currency'] = 'USD';
        }
        $data['callback_url'] = HTTP_CATALOG . 'index.php?route=extension/suawave/payment/suawave.callback';

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/suawave/payment/suawave', $data));
    }

    public function save(): void
    {
        $this->load->language('extension/suawave/payment/suawave');
        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/suawave/payment/suawave')) {
            $json['error'] = $this->language->get('error_permission');
        }

        $post = $this->request->post;
        $baseUrl = rtrim(trim((string) ($post['payment_suawave_base_url'] ?? '')), '/');
        if (!$json && !preg_match('#^https?://[^\s]+$#', $baseUrl)) {
            $json['error'] = $this->language->get('error_base_url');
        }
        if (!$json && trim((string) ($post['payment_suawave_ticker'] ?? '')) === '') {
            $json['error'] = $this->language->get('error_ticker');
        }
        if (!$json && trim((string) ($post['payment_suawave_payout_address'] ?? '')) === '') {
            $json['error'] = $this->language->get('error_payout_address');
        }
        if (!$json && !preg_match('#^https://[^\s/]+$#', rtrim(trim((string) ($post['payment_suawave_external_origin'] ?? '')), '/'))) {
            $json['error'] = $this->language->get('error_external_origin');
        }

        $publicKey = trim((string) ($post['payment_suawave_public_key'] ?? ''));
        if (!$json && $publicKey === '') {
            try {
                $publicKey = (new GatewayClient($baseUrl, new CurlTransport()))->publicKey();
            } catch (GatewayException $failure) {
                $json['error'] = $this->language->get('error_public_key_fetch') . ' ' . $failure->getMessage();
            }
        }
        if (!$json) {
            try {
                new SignatureVerifier($publicKey);
            } catch (InvalidPublicKey $failure) {
                $json['error'] = $this->language->get('error_public_key') . ' ' . $failure->getMessage();
            }
        }

        if (!$json) {
            $post['payment_suawave_base_url'] = $baseUrl;
            $post['payment_suawave_public_key'] = $publicKey;
            $post['payment_suawave_external_origin'] = rtrim(trim((string) $post['payment_suawave_external_origin']), '/');
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_suawave', $post);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function install(): void
    {
        if ($this->user->hasPermission('modify', 'extension/payment')) {
            $this->load->model('extension/suawave/payment/suawave');
            $this->model_extension_suawave_payment_suawave->install();
        }
    }

    public function uninstall(): void
    {
        if ($this->user->hasPermission('modify', 'extension/payment')) {
            $this->load->model('extension/suawave/payment/suawave');
            $this->model_extension_suawave_payment_suawave->uninstall();
        }
    }
}
