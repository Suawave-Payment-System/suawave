<?php

declare(strict_types=1);

namespace Suawave\Payment\Cron;

use Suawave\Payment\Model\Config;
use Suawave\Payment\Model\ExpiryNoter;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;

class NoteExpiredPayments
{
    public const LOOKBACK_SECONDS = 2678400;
    public const BATCH = 500;

    public function __construct(
        private readonly CollectionFactory $collections,
        private readonly ExpiryNoter $noter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $now = time();
        $cursor = 0;
        do {
            $collection = $this->page($now, $cursor);
            $seen = 0;
            foreach ($collection as $order) {
                $seen++;
                $cursor = max($cursor, (int) $order->getId());
                try {
                    $this->noter->noteIfDue($order, $now);
                } catch (\Throwable $failure) {
                    $this->logger->error('Suawave expiry note failed for order ' . $order->getIncrementId() . ': ' . $failure->getMessage());
                }
            }
        } while ($seen === self::BATCH);
    }

    private function page(int $now, int $cursor): iterable
    {
        $collection = $this->collections->create();
        $info = 'suawave_payment.additional_information';
        $collection->getSelect()->join(
            ['suawave_payment' => $collection->getTable('sales_order_payment')],
            'main_table.entity_id = suawave_payment.parent_id',
            []
        )->where('suawave_payment.method = ?', Config::METHOD_CODE)
            ->where('main_table.state IN (?)', [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT])
            ->where('main_table.created_at >= ?', gmdate('Y-m-d H:i:s', $now - self::LOOKBACK_SECONDS))
            ->where('main_table.entity_id > ?', $cursor)
            ->where("COALESCE(JSON_UNQUOTE(JSON_EXTRACT($info, '$.suawave_paid')), '0') <> '1'")
            ->where("CAST(JSON_UNQUOTE(JSON_EXTRACT($info, '$.suawave_expires_at')) AS UNSIGNED) BETWEEN 1 AND ?", $now)
            ->where("NOT (JSON_UNQUOTE(JSON_EXTRACT($info, '$.suawave_expiry_noted')) <=> JSON_UNQUOTE(JSON_EXTRACT($info, '$.suawave_nonce')))")
            ->order('main_table.entity_id ASC')
            ->limit(self::BATCH);
        return $collection;
    }
}
