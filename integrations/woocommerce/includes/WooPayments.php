<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class WooPayments
{
    public const RENEWED = 'renewed';
    public const ALREADY_PAID = 'paid';

    private \Closure $qrSource;

    public function __construct(private WooOrderStore $store, private OrderLock $lock, callable $qrSource)
    {
        $this->qrSource = \Closure::fromCallable($qrSource);
    }

    public static function live(): self
    {
        global $wpdb;
        return new self(
            new WooOrderStore(),
            new WpOrderLock($wpdb),
            static fn (string $ticker, string $address, string $amount): ?string => Settings::client()->qrCode($ticker, $address, $amount)
        );
    }

    public function open(\WC_Order $order, GatewayClient $client, string $ticker, string $payoutAddress, string $amount): string
    {
        $orderId = (string) $order->get_id();
        if ($order->is_paid()) {
            return self::ALREADY_PAID;
        }
        $attempt = Checkout::open(
            $client,
            $ticker,
            $payoutAddress,
            $amount,
            static fn (string $nonce): string => Settings::callbackUrl('woo', $orderId, $nonce)
        );
        $renewed = $this->lock->transactional(
            self::lockKey($orderId),
            fn (Totals $totals): array => [$this->store->renew($orderId, $attempt, $totals), $totals]
        );
        if (!$renewed) {
            return self::ALREADY_PAID;
        }
        if ($attempt['expires_at'] !== '') {
            wp_schedule_single_event((int) $attempt['expires_at'] + 1, WooOrderStore::EXPIRY_HOOK, [(int) $orderId]);
        }
        return self::RENEWED;
    }

    public function closeIfExpired(string $orderId, int $now): bool
    {
        if (!$this->store->isCloseDue($orderId, $now)) {
            return false;
        }
        return (bool) $this->lock->transactional(
            self::lockKey($orderId),
            fn (Totals $totals): array => [$this->store->closeExpired($orderId, $now, $totals), $totals]
        );
    }

    public function closeFromSchedule($orderId): void
    {
        try {
            $this->closeIfExpired((string) $orderId, time());
        } catch (LockUnavailable) {
            wp_schedule_single_event(time() + 300, WooOrderStore::EXPIRY_HOOK, [(int) $orderId]);
        }
    }

    public function html(\WC_Order $order, bool $withQr, int $now, \DateTimeZone $zone, bool $forEmail = false): string
    {
        [$meta, $paid] = $this->current($order, $now);
        $qr = $withQr ? fn (): ?string => $this->qrImage($order, $meta) : null;
        return InstructionsView::html($meta, $paid, $now, $zone, (string) $order->get_order_number(), $qr, $forEmail);
    }

    public function text(\WC_Order $order, int $now, \DateTimeZone $zone): string
    {
        [$meta, $paid] = $this->current($order, $now);
        return InstructionsView::text($meta, $paid, $now, $zone, (string) $order->get_order_number());
    }

    private function current(\WC_Order $order, int $now): array
    {
        $orderId = (string) $order->get_id();
        try {
            $this->closeIfExpired($orderId, $now);
        } catch (LockUnavailable) {
        }
        $fresh = $this->store->find($orderId) ?? $order;
        $meta = [];
        foreach (['address', 'amount', 'ticker', 'minimum', 'received', 'expires_at'] as $field) {
            $meta[$field] = (string) $fresh->get_meta('_suawave_' . $field);
        }
        return [$meta, $fresh->is_paid()];
    }

    private function qrImage(\WC_Order $order, array $meta): ?string
    {
        $fresh = $this->store->find((string) $order->get_id()) ?? $order;
        $cached = (string) $fresh->get_meta('_suawave_qr');
        if ($cached !== '') {
            return $cached;
        }
        $image = ($this->qrSource)($meta['ticker'], $meta['address'], InstructionsView::shownAmount($meta));
        if ($image !== null) {
            $fresh->update_meta_data('_suawave_qr', $image);
            $fresh->save();
        }
        return $image;
    }

    private static function lockKey(string $orderId): string
    {
        return CallbackProcessor::lockKey(['src' => 'woo'], $orderId);
    }
}
