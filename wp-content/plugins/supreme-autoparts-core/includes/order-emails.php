<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ensure the customer gets a store order-confirmation email after payment.
 * Whop buyer receipts are off by design — this is the only customer receipt path.
 *
 * Safe to call more than once: meta _sa_customer_order_email_sent guards duplicates.
 *
 * @param WC_Order|int $order Order object or ID.
 * @return bool True when an email was triggered (or already sent previously).
 */
function sa_core_ensure_customer_order_email($order): bool
{
    if (!function_exists('WC') || !WC()->mailer()) {
        return false;
    }
    if (is_numeric($order)) {
        $order = wc_get_order((int) $order);
    }
    if (!$order instanceof WC_Order) {
        return false;
    }

    $order_id = (int) $order->get_id();
    if ($order_id <= 0) {
        return false;
    }

    if ($order->get_meta('_sa_customer_order_email_sent') === '1') {
        return true;
    }

    $to = (string) $order->get_billing_email();
    if ($to === '' || !is_email($to)) {
        $order->add_order_note(__('Skipped customer order email — billing email missing.', 'supreme-autoparts-core'));
        return false;
    }

    // Prefer processing (paid) confirmation; fall back to completed / on-hold.
    $status = $order->get_status();
    $map = [
        'processing' => 'WC_Email_Customer_Processing_Order',
        'completed'  => 'WC_Email_Customer_Completed_Order',
        'on-hold'    => 'WC_Email_Customer_On_Hold_Order',
    ];
    $class = $map[$status] ?? 'WC_Email_Customer_Processing_Order';

    $emails = WC()->mailer()->get_emails();
    if (!isset($emails[$class]) || !is_object($emails[$class])) {
        return false;
    }

    /** @var WC_Email $email */
    $email = $emails[$class];
    if (method_exists($email, 'is_enabled') && !$email->is_enabled()) {
        // Force-enable for this send — store settings should already enable, but do not silently skip.
        $email->enabled = 'yes';
    }

    try {
        $email->trigger($order_id, $order);
    } catch (Throwable $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[sa-core] order email trigger failed #' . $order_id . ': ' . $e->getMessage());
        $order->add_order_note('Customer order email failed: ' . $e->getMessage());
        return false;
    }

    $order->update_meta_data('_sa_customer_order_email_sent', '1');
    $order->update_meta_data('_sa_customer_order_email_at', (string) time());
    $order->update_meta_data('_sa_customer_order_email_class', $class);
    $order->add_order_note(sprintf(
        /* translators: 1: email class 2: recipient */
        __('Customer order confirmation emailed via %1$s to %2$s.', 'supreme-autoparts-core'),
        $class,
        $to
    ));
    $order->save();
    return true;
}

/**
 * Mark when Woo (or our ensure) successfully sends a customer order email,
 * so the payment_complete backfill does not double-send.
 *
 * @param bool     $sent
 * @param string   $email_id
 * @param WC_Email $email
 */
add_action('woocommerce_email_sent', static function ($sent, $email_id, $email = null): void {
    if (!$sent) {
        return;
    }
    $ids = [
        'customer_processing_order',
        'customer_completed_order',
        'customer_on_hold_order',
    ];
    if (!in_array((string) $email_id, $ids, true)) {
        return;
    }
    $order = null;
    if (is_object($email) && isset($email->object) && $email->object instanceof WC_Order) {
        $order = $email->object;
    }
    if (!$order instanceof WC_Order) {
        return;
    }
    if ($order->get_meta('_sa_customer_order_email_sent') === '1') {
        return;
    }
    $order->update_meta_data('_sa_customer_order_email_sent', '1');
    $order->update_meta_data('_sa_customer_order_email_at', (string) time());
    $order->update_meta_data('_sa_customer_order_email_class', (string) $email_id);
    $order->save();
}, 10, 3);

/**
 * After Woo marks an order paid, guarantee the customer confirmation email.
 * Covers Whop webhook payment_complete and any other gateway that pays the order.
 */
add_action('woocommerce_payment_complete', static function ($order_id): void {
    $order_id = (int) $order_id;
    if ($order_id <= 0) {
        return;
    }
    // Defer one tick so status/meta from payment_complete are fully saved.
    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) {
        return;
    }
    // Only when actually paid / processing / completed.
    if (!$order->is_paid() && !$order->has_status(['processing', 'completed'])) {
        return;
    }
    sa_core_ensure_customer_order_email($order);
}, 40);

/** Clearer subject so customers recognize the buy-success / confirmation mail. */
add_filter('woocommerce_email_subject_customer_processing_order', static function ($subject, $order) {
    if (!$order instanceof WC_Order) {
        return $subject;
    }
    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    return sprintf(
        /* translators: 1: site name 2: order number */
        __('[%1$s] Order confirmation #%2$s', 'supreme-autoparts-core'),
        $site,
        $order->get_order_number()
    );
}, 20, 2);

add_filter('woocommerce_email_heading_customer_processing_order', static function ($heading, $order) {
    unset($order);
    return __('Thank you — your order is confirmed', 'supreme-autoparts-core');
}, 20, 2);
