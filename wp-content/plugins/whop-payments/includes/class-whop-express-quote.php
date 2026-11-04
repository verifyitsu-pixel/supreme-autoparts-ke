<?php
/** Private express preparation. Not loaded by the live plugin, no API/payment effects. */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
final class Whop_Express_Quote {
    /** Freeze only server-calculated Woo totals, no client prices. USD cap matches existing route. */
    public static function freeze(array $server, int $now): array {
        $total = $server['total_minor'] ?? null;
        if (($server['currency'] ?? '') !== 'USD' || !is_int($total) || $total < 1 || $total > 250000) throw new InvalidArgumentException('Unsupported express amount or currency.');
        if (empty($server['cart_hash']) || !is_string($server['cart_hash']) || empty($server['stock_valid'])) throw new InvalidArgumentException('Cart unavailable.');
        if (!empty($server['requires_shipping']) && (empty($server['shipping_option']) || empty($server['shipping_quoted']))) throw new InvalidArgumentException('Delivery quote required.');
        $quote = [
            'cart_hash' => $server['cart_hash'], 'currency' => 'USD', 'total_minor' => $total,
            'shipping_option' => (string)($server['shipping_option'] ?? ''),
            'requires_shipping' => !empty($server['requires_shipping']),
            'issued_at' => $now, 'expires_at' => $now + 300,
        ];
        $quote['fingerprint'] = hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
        return $quote;
    }
    /** Refuse changed totals/cart/delivery before any token can be sent to charge. */
    public static function validate(array $quote, array $current, array $authorized, int $now): void {
        if ($now > ($quote['expires_at'] ?? 0) || $now < ($quote['issued_at'] ?? PHP_INT_MAX)) throw new InvalidArgumentException('Quote expired.');
        $fresh = self::freeze($current, (int)$quote['issued_at']);
        if (!hash_equals((string)($quote['fingerprint'] ?? ''), $fresh['fingerprint'])) throw new InvalidArgumentException('Cart or delivery changed.');
        if (($authorized['currency'] ?? '') !== $quote['currency'] || ($authorized['total_minor'] ?? null) !== $quote['total_minor']) throw new InvalidArgumentException('Authorized total differs.');
        if (empty($authorized['terms_accepted']) || !filter_var($authorized['email'] ?? '', FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Customer confirmation required.');
        if ($quote['requires_shipping']) {
            $a=$authorized['shipping'] ?? [];
            foreach (['name','address1','city','country'] as $key) if (!is_string($a[$key] ?? null) || trim($a[$key]) === '') throw new InvalidArgumentException('Complete delivery address required.');
            if (($authorized['shipping_option'] ?? '') !== $quote['shipping_option']) throw new InvalidArgumentException('Delivery option differs.');
        }
        if (!is_string($authorized['token'] ?? null) || !preg_match('/^ctok_[A-Za-z0-9]+$/D', $authorized['token'])) throw new InvalidArgumentException('Invalid wallet token.');
    }
}
