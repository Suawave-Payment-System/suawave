<?php

declare(strict_types=1);

namespace Suawave;

use Configuration;
use Message;
use Order;
use OrderHistory;
use RuntimeException;
use Validate;

final class PrestashopOrderGateway implements OrderGateway
{
    public function markPaid(int $orderId, string $comment): void
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            throw new RuntimeException('the order ' . $orderId . ' does not exist');
        }
        $note = new Message();
        $note->message = $comment;
        $note->id_order = $orderId;
        $note->private = 1;
        $note->add();
        $history = new OrderHistory();
        $history->id_order = $orderId;
        $history->changeIdOrderState((int) Configuration::get('PS_OS_PAYMENT'), $order);
        if (!$history->addWithemail(true)) {
            throw new RuntimeException('the order history could not be saved');
        }
    }
}
