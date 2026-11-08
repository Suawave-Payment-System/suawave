<?php

declare(strict_types=1);

namespace Suawave\Payment\Model;

use Suawave\Payment\Core\ProcessedEventStore;
use Magento\Framework\App\ResourceConnection;

class DatabaseEventStore implements ProcessedEventStore
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function claim(string $eventUuid, string $orderReference, string $depositTransaction): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('suawave_callback_event');
        $statement = $connection->query(
            'INSERT IGNORE INTO ' . $connection->quoteIdentifier($table)
            . ' (event_uuid, order_reference, deposit_transaction) VALUES (?, ?, ?)',
            [$eventUuid, $orderReference, $depositTransaction]
        );
        return $statement->rowCount() === 1;
    }
}
