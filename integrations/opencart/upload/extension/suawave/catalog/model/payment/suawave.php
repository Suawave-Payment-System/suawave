<?php

namespace Opencart\Catalog\Model\Extension\Suawave\Payment;

class Suawave extends \Opencart\System\Engine\Model
{
    public function getMethods(array $address = []): array
    {
        $this->load->language('extension/suawave/payment/suawave');

        if (!$this->config->get('payment_suawave_status')) {
            return [];
        }
        $currency = (string) ($this->session->data['currency'] ?? '');
        if ($currency === '' || $currency !== (string) $this->config->get('payment_suawave_order_currency')) {
            return [];
        }

        $option['suawave'] = [
            'code' => 'suawave.suawave',
            'name' => $this->language->get('text_title'),
        ];

        return [
            'code' => 'suawave',
            'name' => $this->language->get('heading_title'),
            'option' => $option,
            'sort_order' => $this->config->get('payment_suawave_sort_order'),
        ];
    }

    public function getOrderAmounts(int $orderId): ?array
    {
        $query = $this->db->query(
            'SELECT CAST(`total` AS CHAR) AS `total`, CAST(`currency_value` AS CHAR) AS `currency_value`, '
            . '`currency_code`, `order_status_id` FROM `' . DB_PREFIX . 'order` WHERE `order_id` = ' . $orderId
        );
        if ($query->num_rows === 0) {
            return null;
        }
        return [
            'total' => (string) $query->row['total'],
            'currency_value' => (string) $query->row['currency_value'],
            'currency_code' => (string) $query->row['currency_code'],
            'order_status_id' => (int) $query->row['order_status_id'],
        ];
    }
}
