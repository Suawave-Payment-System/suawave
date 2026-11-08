<?php

declare(strict_types=1);

namespace Suawave\Payment\Model;

use Suawave\Payment\Core\OrderTransaction;
use Magento\Framework\App\ResourceConnection;

class DatabaseOrderTransaction implements OrderTransaction
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function runLocked(string $orderReference, callable $work): mixed
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $select = $connection->select()
                ->from($this->resource->getTableName('sales_order'), ['entity_id'])
                ->where('increment_id = ?', $orderReference)
                ->forUpdate(true);
            $connection->fetchOne($select);
            $result = $work();
            $connection->commit();
            return $result;
        } catch (\Throwable $failure) {
            $connection->rollBack();
            throw $failure;
        }
    }
}
