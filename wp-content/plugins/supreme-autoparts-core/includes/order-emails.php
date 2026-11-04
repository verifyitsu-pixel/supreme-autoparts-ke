<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Successful transport marker only. Legacy completed deliveries are retained
 * to prevent an unrequested retroactive resend; on-hold never satisfies this. */
function sa_core_order_is_verified_paid(WC_Order $order): bool
{
    return (bool)$order->get_date_paid() && $order->is_paid();
}
function sa_core_paid_confirmation_sent(WC_Order $order): bool
{
    if ($order->get_meta('_sa_paid_confirmation_sent') === '1') return true;
    return $order->get_meta('_sa_customer_order_email_sent') === '1' && in_array(
        (string)$order->get_meta('_sa_customer_order_email_class'),
        ['customer_processing_order','WC_Email_Customer_Processing_Order','customer_completed_order','WC_Email_Customer_Completed_Order'], true
    );
}

/** Serialize both automatic Woo sends and explicit backfills on one DB
 * connection. Zero wait fails closed; the lock is released after transport. */
function sa_core_confirmation_lock(WC_Order $order): bool
{
    global $wpdb;
    $held = $GLOBALS['sa_confirmation_locks'] ?? [];
    $id=(int)$order->get_id();
    if (isset($held[$id])) return false; // Same-request recursion must not double-send.
    if (!is_object($wpdb) || !method_exists($wpdb,'get_var') || !method_exists($wpdb,'prepare')) return false;
    $name='sa_paid_confirmation_'.$id;
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') return false;
    $GLOBALS['sa_confirmation_locks'][$id]=true;
    // Refresh metadata after acquiring so another process's send is visible.
    if (method_exists($order,'read_meta_data')) $order->read_meta_data(true);
    return true;
}
function sa_core_confirmation_unlock(WC_Order $order): void
{
    global $wpdb;
    unset($GLOBALS['sa_confirmation_locks'][(int)$order->get_id()]);
    if (is_object($wpdb) && method_exists($wpdb,'get_var') && method_exists($wpdb,'prepare')) {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'sa_paid_confirmation_'.(int)$order->get_id()));
    }
}
// Inspection is side-effect free. The transport wrapper owns serialization.
add_filter('woocommerce_email_enabled_customer_processing_order', static function($enabled,$order) {
    if (!$order instanceof WC_Order) return $enabled;
    if (($GLOBALS['sa_force_paid_confirmation'] ?? 0) === (int)$order->get_id()) $enabled = true;
    return $enabled && sa_core_order_is_verified_paid($order) && !sa_core_paid_confirmation_sent($order);
}, 60, 2);

function sa_core_mark_paid_confirmation(WC_Order $order): void
{
    $order->update_meta_data('_sa_paid_confirmation_pending', '');
    $order->update_meta_data('_sa_paid_confirmation_sent', '1');
    $order->update_meta_data('_sa_customer_order_email_sent', '1');
    $order->update_meta_data('_sa_customer_order_email_at', (string)time());
    $order->update_meta_data('_sa_customer_order_email_class', 'customer_processing_order');
    $order->add_order_note('Paid order confirmation accepted by mail transport via approved processing template.');
    $order->save();
}
/** Canonical snapshot of all rendered order data, including all item kinds and metadata.
 * Only modification timestamps and delivery bookkeeping are non-content state. */
function sa_core_confirmation_snapshot(WC_Order $order): string
{
    $normalize = static function($value) use (&$normalize) {
        if ($value instanceof DateTimeInterface) return $value->format('U.u');
        if (is_object($value) && method_exists($value,'get_data')) return $normalize(array_replace_recursive($value->get_data(),$value instanceof WC_Data ? $value->get_changes() : []));
        if (is_array($value)) { foreach ($value as $key=>$part) $value[$key]=$normalize($part); ksort($value); }
        return $value;
    };
    $data=array_replace_recursive($order->get_data(),$order->get_changes());
    foreach (['date_modified','order_stock_reduced','download_permissions_granted','recorded_sales','recorded_coupon_usage_counts'] as $key) unset($data[$key]);
    foreach (['discount_total','discount_tax','shipping_total','shipping_tax','cart_tax','total_tax','total'] as $key) $data[$key]=wc_format_decimal($data[$key],wc_get_price_decimals());
    $meta=[];
    foreach ($data['meta_data'] ?? [] as $m) {
        $row=$m->get_data(); $key=$row['key'];
        if (in_array($key,['_sa_timeline','_sa_paid_confirmation_pending','_sa_paid_confirmation_sent','_sa_customer_order_email_sent','_sa_customer_order_email_at','_sa_customer_order_email_class'],true)) continue;
        $meta[$key]=$order->get_meta($key,true,'edit');
    }
    $data['meta_data']=$meta;
    return hash('sha256',serialize($normalize($data)));
}
/** Return a separate authoritative object. Never overwrite the rendered object.
 * CPT path is runtime-tested. HPOS cache eviction uses the installed datastore API. */
function sa_core_confirmation_fresh_order(WC_Order $rendered): ?WC_Order
{
    $id=(int)$rendered->get_id();
    $store=$rendered->get_data_store();
    $class=$store->get_current_class_name();
    if (is_a($class,'WC_Order_Data_Store_CPT',true)) {
        clean_post_cache($id);
        wp_cache_delete($id,'post_meta');
    } elseif (is_a($class,'Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore',true) && method_exists($class,'clear_cached_data')) {
        $store->clear_cached_data([$id]);
    } else { return null; } // Unknown storage cannot establish authoritative state.
    // Item rows and item metadata have caches separate from the order header.
    global $wpdb;
    wp_cache_delete('order-items-'.$id,'orders');
    $item_ids=$wpdb->get_col($wpdb->prepare("SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d",$id));
    if (!is_array($item_ids)) return null;
    foreach ($item_ids as $item_id) {
        wp_cache_delete('item-'.(int)$item_id,'order-items');
        wp_cache_delete((int)$item_id,'order_item_meta');
        wp_cache_delete(WC_Data::generate_meta_cache_key((int)$item_id,'order-items'),'order-items');
    }
    // Empty object forces the selected datastore read and has no loaded item arrays.
    // The factory returns false for ID zero and may reuse cached objects for real IDs.
    $fresh=new WC_Order();
    if (!$fresh instanceof WC_Order) return null;
    $fresh->set_id($id);
    $fresh->get_data_store()->read($fresh);
    $fresh->read_meta_data(true);
    return $fresh;
}

// Woo send_notification evaluates get_subject before get_content. Capture the
// exact order state before any content rendering, without changing the template.
add_filter('woocommerce_email_subject_customer_processing_order', static function($subject,$order,$email=null) {
    if ($order instanceof WC_Order && is_object($email) && !isset($GLOBALS['sa_confirmation_render_state'][spl_object_id($email)])) {
        $GLOBALS['sa_confirmation_render_state'][spl_object_id($email)] = [
            'order'=>$order, 'id'=>(int)$order->get_id(),
            'fingerprint'=>sa_core_confirmation_snapshot($order),
        ];
    }
    return $subject;
}, PHP_INT_MIN, 3);

add_filter('woocommerce_mail_callback', static function($callback,$email) {
    if (!is_object($email) || ($email->id ?? '') !== 'customer_processing_order' ||
        !isset($email->object) || !$email->object instanceof WC_Order) return $callback;
    $order = $email->object;
    $oid = (int)$order->get_id();
    $captured=$GLOBALS['sa_confirmation_render_state'][spl_object_id($email)] ?? null;
    unset($GLOBALS['sa_confirmation_render_state'][spl_object_id($email)]);
    if (!$captured || $captured['order'] !== $order || $captured['id'] !== $oid) return static function(...$params): bool { return false; };
    $rendered_fingerprint=$captured['fingerprint'];
    return static function(...$params) use ($callback,$email,$order,$oid,$rendered_fingerprint): bool {
        // Bind the rendered message, recipient and mutable Woo email object.
        if (!is_callable($callback) || $email->object !== $order || (int)$order->get_id() !== $oid ||
            !hash_equals($rendered_fingerprint,sa_core_confirmation_snapshot($order)) ||
            count($params) !== 5 || !is_string($params[0]) ||
            strcasecmp(trim($params[0]),trim((string)$order->get_billing_email())) !== 0 ||
            !is_email($params[0]) || !sa_core_order_is_verified_paid($order)) return false;
        if (!sa_core_confirmation_lock($order)) return false;
        try {
            $fresh=sa_core_confirmation_fresh_order($order);
            if (!$fresh || !hash_equals($rendered_fingerprint,sa_core_confirmation_snapshot($fresh)) || !sa_core_order_is_verified_paid($fresh)) return false;
            // Use only fresh state for bookkeeping; the rendered message stays unchanged.
            if (sa_core_paid_confirmation_sent($fresh) || !sa_core_order_is_verified_paid($fresh) || $fresh->get_meta('_sa_paid_confirmation_pending') === '1') return false;
            // A crash/exception after acceptance is ambiguous. Never retry it blindly.
            $fresh->update_meta_data('_sa_paid_confirmation_pending','1');
            $fresh->save();
            $accepted = call_user_func_array($callback,$params);
            // Woo requires a boolean callback. Only literal true proves acceptance.
            if ($accepted !== true) {
                if ($accepted === false) {
                    $fresh->update_meta_data('_sa_paid_confirmation_pending','');
                    $fresh->save();
                }
                // Non-boolean transport results are ambiguous, not a safe retry.
                return false;
            }
            // Persist while still serialized, before Woo emits email_sent.
            sa_core_mark_paid_confirmation($fresh);
            $order->read_meta_data(true);
            return true;
        } finally { sa_core_confirmation_unlock($order); }
    };
}, PHP_INT_MAX, 2);

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

    // A successful on-hold notice is not a paid confirmation.
    if (!sa_core_order_is_verified_paid($order)) return false;
    if (sa_core_paid_confirmation_sent($order)) return true;

    $to = (string) $order->get_billing_email();
    if ($to === '' || !is_email($to)) {
        $order->add_order_note(__('Skipped customer order email — billing email missing.', 'supreme-autoparts-core'));
        return false;
    }

    // The first paid email always uses the approved confirmation, even if
    // a fee-only/virtual order moves directly to completed.
    $class = 'WC_Email_Customer_Processing_Order';

    $emails = WC()->mailer()->get_emails();
    if (!isset($emails[$class]) || !is_object($emails[$class])) {
        return false;
    }

    /** @var WC_Email $email */
    $email = $emails[$class];
    // Enabling is scoped to this exact order and restored after trigger.
    // The paid-confirmation policy is mandatory, subject to that safety filter.
    $previous_enabled = $email->enabled;
    $email->enabled = 'yes';

    $previous_force = $GLOBALS['sa_force_paid_confirmation'] ?? 0;
    $GLOBALS['sa_force_paid_confirmation'] = $order_id;
    try {
        $email->trigger($order_id, $order);
    } catch (Throwable $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[sa-core] order email trigger failed #' . $order_id . ': ' . $e->getMessage());
        $order->add_order_note('Customer order email failed: ' . $e->getMessage());
        return false;
    } finally {
        unset($GLOBALS['sa_confirmation_render_state'][spl_object_id($email)]);
        $GLOBALS['sa_force_paid_confirmation'] = $previous_force;
        $email->enabled = $previous_enabled;
    }

    // trigger() returns void. Only woocommerce_email_sent(true) proves success.
    return sa_core_paid_confirmation_sent($order);
}

/**
 * Mark when Woo (or our ensure) successfully sends a customer order email,
 * so the payment_complete backfill does not double-send.
 *
 * @param bool     $sent
 * @param string   $email_id
 * @param WC_Email $email
 */

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
