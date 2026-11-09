<?php

declare(strict_types=1);

namespace Suawave;

if (!defined('ABSPATH')) {
    exit;
}

final class InstructionsView
{
    public static function html(array $meta, bool $paid, int $now, \DateTimeZone $zone, string $orderNumber, ?callable $qr, bool $forEmail = false): string
    {
        $address = (string) ($meta['address'] ?? '');
        $amount = (string) ($meta['amount'] ?? '');
        if ($address === '' || $amount === '') {
            return '';
        }
        $ticker = (string) ($meta['ticker'] ?? '');
        $symbol = strtoupper(substr($ticker, (int) strpos($ticker, '/') + 1));
        $amount = self::shownAmount($meta);
        $minimum = self::rounded((string) ($meta['minimum'] ?? ''), $ticker);
        $received = (string) ($meta['received'] ?? '');
        $expiresAt = PaymentWindow::fromMeta($meta['expires_at'] ?? null);
        $html = '<section class="suawave-payment-instructions"><h2>' . esc_html(__('Suawave payment', 'suawave-payment')) . '</h2>';
        if ($paid) {
            return $html . '<p>' . esc_html(__('Payment received. Thank you.', 'suawave-payment')) . '</p></section>';
        }
        if (PaymentWindow::isClosed($expiresAt, $now)) {
            return $html . '<p>' . esc_html(sprintf(
                __('The payment window closed on %1$s. Do not send funds to this address. Please contact the shop and quote order %2$s.', 'suawave-payment'),
                PaymentWindow::format((int) $expiresAt, $zone),
                $orderNumber
            )) . '</p></section>';
        }
        $html .= '<ul>';
        $html .= '<li>' . esc_html(__('Send exactly:', 'suawave-payment')) . ' <strong>' . esc_html($amount . ' ' . $symbol) . '</strong></li>';
        $html .= '<li>' . esc_html(__('To address:', 'suawave-payment')) . ' <strong style="word-break:break-all">' . esc_html($address) . '</strong></li>';
        $html .= '<li>' . esc_html(__('Network:', 'suawave-payment')) . ' ' . esc_html($ticker) . '</li>';
        $html .= '<li>' . esc_html(__('Minimum deposit:', 'suawave-payment')) . ' ' . esc_html($minimum . ' ' . $symbol) . '</li>';
        if ($expiresAt !== null) {
            $html .= '<li>' . esc_html(__('Pay before:', 'suawave-payment')) . ' <strong>' . esc_html(PaymentWindow::format($expiresAt, $zone)) . '</strong></li>';
        }
        if ($received !== '' && $received !== '0') {
            $html .= '<li>' . esc_html(__('Received so far:', 'suawave-payment')) . ' ' . esc_html(self::exact($received) . ' ' . $symbol) . '</li>';
        }
        $html .= '</ul>';
        $image = $qr === null ? null : $qr();
        if ($image !== null) {
            $html .= '<p><img alt="' . esc_attr(__('Payment QR code', 'suawave-payment')) . '" width="256" height="256" src="' . esc_attr($image) . '"></p>';
        }
        if ($forEmail) {
            return $html . '</section>';
        }
        return $html . '<p>' . esc_html(__('This page does not update by itself. The order is marked paid once the gateway confirms your deposit.', 'suawave-payment')) . '</p></section>';
    }

    public static function text(array $meta, bool $paid, int $now, \DateTimeZone $zone, string $orderNumber): string
    {
        $address = (string) ($meta['address'] ?? '');
        $amount = (string) ($meta['amount'] ?? '');
        if ($address === '' || $amount === '') {
            return '';
        }
        $ticker = (string) ($meta['ticker'] ?? '');
        $symbol = strtoupper(substr($ticker, (int) strpos($ticker, '/') + 1));
        $amount = self::shownAmount($meta);
        $expiresAt = PaymentWindow::fromMeta($meta['expires_at'] ?? null);
        $lines = [strtoupper(__('Suawave payment', 'suawave-payment'))];
        if ($paid) {
            $lines[] = __('Payment received. Thank you.', 'suawave-payment');
        } elseif (PaymentWindow::isClosed($expiresAt, $now)) {
            $lines[] = sprintf(
                __('The payment window closed on %1$s. Do not send funds to this address. Please contact the shop and quote order %2$s.', 'suawave-payment'),
                PaymentWindow::format((int) $expiresAt, $zone),
                $orderNumber
            );
        } else {
            $lines[] = __('Send exactly:', 'suawave-payment') . ' ' . $amount . ' ' . $symbol;
            $lines[] = __('To address:', 'suawave-payment') . ' ' . $address;
            $lines[] = __('Network:', 'suawave-payment') . ' ' . $ticker;
            $lines[] = __('Minimum deposit:', 'suawave-payment') . ' ' . self::rounded((string) ($meta['minimum'] ?? ''), $ticker) . ' ' . $symbol;
            if ($expiresAt !== null) {
                $lines[] = __('Pay before:', 'suawave-payment') . ' ' . PaymentWindow::format($expiresAt, $zone);
            }
            $received = (string) ($meta['received'] ?? '');
            if ($received !== '' && $received !== '0') {
                $lines[] = __('Received so far:', 'suawave-payment') . ' ' . self::exact($received) . ' ' . $symbol;
            }
        }
        return implode("\n", $lines) . "\n\n";
    }

    public static function shownAmount(array $meta): string
    {
        return self::rounded((string) ($meta['amount'] ?? ''), (string) ($meta['ticker'] ?? ''));
    }

    private static function rounded(string $value, string $ticker): string
    {
        return Money::isDecimal($value) ? Money::display($value, Money::displayPlaces($ticker)) : $value;
    }

    private static function exact(string $value): string
    {
        return Money::isDecimal($value) ? Money::plain($value) : $value;
    }
}
