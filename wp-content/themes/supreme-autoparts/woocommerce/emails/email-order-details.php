<?php
/**
 * Order details in WooCommerce emails: the shared Supreme Autoparts receipt table
 * (thumbnail, item, part #, qty, unit price, amount, right-aligned totals).
 *
 * @version 9.8.0 (overrides woocommerce/templates/emails/email-order-details.php)
 */
defined('ABSPATH') || exit;

do_action('woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email);

if (function_exists('sa_rcpt_order_data')) {
    $d = sa_rcpt_order_data($order);
    $font = SA_RCPT_FONT;
    echo '<div style="font-family:' . esc_attr($font) . ';font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6B6B6B;font-weight:600;margin:18px 0 10px">'
        . esc_html(sprintf('%s %s · %s', $d['doc'], $d['number'], $d['date'])) . '</div>';
    echo sa_rcpt_items_html($d['items'], 'email', $font); // phpcs:ignore
    echo '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:10px 0 18px;border-collapse:collapse">' . sa_rcpt_totals_html($d['totals'], $font) . '</table>'; // phpcs:ignore
    if ($order->get_payment_method_title()) {
        echo '<p style="font-family:' . esc_attr($font) . ';font-size:13px;color:#6B6B6B;margin:0 0 16px">' . esc_html__('Payment method', 'woocommerce') . ': ' . esc_html($order->get_payment_method_title()) . '</p>';
    }
    if (!$sent_to_admin) {
        echo '<p style="font-family:' . esc_attr($font) . ';font-size:13px;margin:0 0 18px"><a href="' . esc_url($d['view_url']) . '" style="color:#111111;text-decoration:underline">' . esc_html($d['paid'] ? 'View or print your receipt' : 'View or print your invoice') . '</a> · ' . esc_html__('PDF copy attached', 'supreme-autoparts') . '</p>';
    }
} else {
    include WC()->plugin_path() . '/templates/emails/email-order-details.php';
    return;
}

do_action('woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email);
