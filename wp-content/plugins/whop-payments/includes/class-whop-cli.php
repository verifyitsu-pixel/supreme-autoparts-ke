<?php
/**
 * WP-CLI: wp whop simulate-open-pay --amount=5 --email=test@example.com
 * Creates a pending open-pay order then marks it paid (webhook path) without charging a card.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class Whop_CLI_Command {

    /**
     * Simulate an open-amount /pay payment → Woo order (no real card charge).
     *
     * ## OPTIONS
     * [--amount=<usd>]
     * : USD amount (default 5.00)
     * [--email=<email>]
     * : Customer email
     * [--note=<note>]
     * : Optional note
     * [--pending-only]
     * : Create pending order only (skip payment_complete)
     *
     * ## EXAMPLES
     *     wp whop simulate-open-pay --amount=12.50 --email=proof@supremeautoparts.co.ke
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function simulate_open_pay(array $args, array $assoc_args): void {
        if (!class_exists('Whop_Open_Pay') || !function_exists('wc_get_order')) {
            WP_CLI::error('Whop_Open_Pay / WooCommerce not available.');
        }

        $amount = isset($assoc_args['amount']) ? round((float) $assoc_args['amount'], 2) : 5.00;
        $email  = sanitize_email((string) ($assoc_args['email'] ?? 'openpay-proof@supremeautoparts.co.ke'));
        $note   = sanitize_text_field((string) ($assoc_args['note'] ?? 'CLI simulate-open-pay proof'));

        if ($amount < 1) {
            WP_CLI::error('Amount must be >= 1.');
        }
        if (!is_email($email)) {
            WP_CLI::error('Invalid email.');
        }

        $order = Whop_Open_Pay::create_pending_order($amount, $email, $note, [
            '_sa_open_pay_simulated' => '1',
        ]);
        if (!$order instanceof WC_Order) {
            WP_CLI::error('Failed to create pending order.');
        }

        $oid = (int) $order->get_id();
        WP_CLI::log(sprintf('Created pending open-pay order #%d total=%s email=%s', $oid, $order->get_total(), $email));

        if (!empty($assoc_args['pending-only'])) {
            WP_CLI::success('Pending only. Order ID: ' . $oid);
            return;
        }

        $txn = 'sim_whop_' . wp_generate_password(10, false);
        $order->payment_complete($txn);
        if ($order->get_meta('_sa_open_pay') === '1' && $order->has_status('processing')) {
            $order->update_status('completed', __('Open-pay simulated payment marked completed.', 'whop-payments'));
        }
        $order->add_order_note(sprintf(
            __('Whop simulate-open-pay — payment %s (CLI, no card charged).', 'whop-payments'),
            $txn
        ));
        $order->update_meta_data('_whop_payment_id', $txn);
        $order->save();

        $fresh = wc_get_order($oid);
        WP_CLI::success(sprintf(
            'Order #%d status=%s total=%s payment_id=%s',
            $oid,
            $fresh ? $fresh->get_status() : '?',
            $fresh ? $fresh->get_total() : '?',
            $txn
        ));
    }
}

WP_CLI::add_command('whop', 'Whop_CLI_Command');
