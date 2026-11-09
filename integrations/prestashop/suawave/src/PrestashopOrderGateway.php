<?php

declare(strict_types=1);

namespace Suawave;

use Cache;
use Configuration;
use Db;
use Message;
use Order;
use OrderHistory;
use RuntimeException;
use Throwable;
use Validate;

final class PrestashopOrderGateway implements OrderGateway
{
    private readonly FailureLog $log;

    public function __construct(?FailureLog $log = null)
    {
        $this->log = $log ?? new PrestashopFailureLog();
    }

    public function markPaid(int $orderId, string $comment): void
    {
        Cache::clean('objectmodel_Order_' . $orderId . '_*');
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            throw new RuntimeException('the order ' . $orderId . ' does not exist');
        }
        $this->addNote($orderId, $comment);
        $history = new OrderHistory();
        $history->id_order = $orderId;
        $history->changeIdOrderState((int) Configuration::get('PS_OS_PAYMENT'), $order);
        if (!$history->add(true)) {
            throw new RuntimeException('the order history could not be saved');
        }
        Order::cleanHistoryCache();
        $this->sendPaidEmail($history, $orderId);
    }

    private function sendPaidEmail(OrderHistory $history, int $orderId): void
    {
        try {
            $sent = $history->sendEmail(new Order($orderId));
        } catch (Throwable $failure) {
            $this->log->record(new MailFailure('the paid order email for order ' . $orderId . ' could not be sent, the order is paid: ' . $failure->getMessage(), 0, $failure));
            return;
        }
        if (!$sent) {
            $this->log->record(new MailFailure('the paid order email for order ' . $orderId . ' could not be sent, the order is paid'));
        }
    }

    public function relabel(int $orderId, string $label): void
    {
        $db = Db::getInstance();
        $value = pSQL(mb_substr($label, 0, 255));
        $orders = '`' . _DB_PREFIX_ . 'orders`';
        if ($db->execute('UPDATE ' . $orders . " SET `payment` = '" . $value . "' WHERE `id_order` = " . $orderId, true) === false
            || $db->execute(
                'UPDATE `' . _DB_PREFIX_ . 'order_payment` `op` INNER JOIN ' . $orders . ' `o` ON `o`.`reference` = `op`.`order_reference`'
                . " SET `op`.`payment_method` = '" . $value . "' WHERE `o`.`id_order` = " . $orderId,
                true
            ) === false
        ) {
            throw new RuntimeException('the payment label could not be saved: ' . $db->getMsgError());
        }
        Cache::clean('objectmodel_Order_' . $orderId . '_*');
    }

    public function addNote(int $orderId, string $comment): void
    {
        $note = new Message();
        $note->message = $comment;
        $note->id_order = $orderId;
        $note->private = 1;
        $note->add();
    }
}
