<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Branded HTML invoice / receipt: ?sa_invoice=ORDER_ID&key=TOKEN[&order_key=...]
 *
 * Access (any one of):
 * - Logged-in order owner (matching order->get_user_id())
 * - Staff with manage_woocommerce / edit_shop_orders
 * - Stable HMAC key (non-expiring) OR Woo order_key (guest thank-you pattern)
 * - Legacy wp_verify_nonce still accepted for old bookmarks
 */

/**
 * Absolute HTTPS logo URL for print / email embedding.
 */
function sa_core_invoice_logo_url(): string
{
    // Official logo bundled with the theme (same file as the site header). Media-library
    // uploads are not used: they can disappear on redeploy and show as broken images.
    return 'https://www.supremeautoparts.co.ke/wp-content/themes/supreme-autoparts/assets/logo-light.jpg?v=emblem2';
}

/** Same logo as a data: URI, for files opened offline (downloaded invoices, attachments, PDFs). */
function sa_core_logo_data_uri(): string
{
    static $u = null;
    if ($u === null) {
        $f = get_template_directory() . '/assets/logo-light.jpg';
        $u = is_readable($f) ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($f)) : sa_core_invoice_logo_url();
    }
    return $u;
}

/**
 * Stable, non-expiring HMAC token for an order invoice link.
 */
function sa_core_invoice_token(int $order_id, string $order_key = '', int $user_id = 0): string
{
    if ($order_key === '' || $user_id <= 0) {
        if (!function_exists('wc_get_order')) {
            return '';
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return '';
        }
        $order_key = (string) $order->get_order_key();
        $user_id   = (int) $order->get_user_id();
    }
    $payload = $order_id . '|' . $user_id . '|' . $order_key;
    return substr(hash_hmac('sha256', $payload, wp_salt('auth')), 0, 32);
}

function sa_core_invoice_url(int $order_id, bool $include_order_key = false): string
{
    $args = [
        'sa_invoice' => $order_id,
        'key'        => sa_core_invoice_token($order_id),
    ];
    if ($include_order_key && function_exists('wc_get_order')) {
        $order = wc_get_order($order_id);
        if ($order) {
            $args['order_key'] = $order->get_order_key();
        }
    }
    return add_query_arg($args, home_url('/'));
}

/**
 * Email-me action URL (admin-post).
 */
function sa_core_invoice_email_url(int $order_id): string
{
    return wp_nonce_url(
        add_query_arg(
            [
                'action'     => 'sa_email_invoice',
                'sa_invoice' => $order_id,
            ],
            admin_url('admin-post.php')
        ),
        'sa_email_invoice_' . $order_id
    );
}

/**
 * Document title: Receipt when paid/processing/completed; Invoice otherwise.
 *
 * @param WC_Order $order
 */
function sa_core_invoice_doc_title($order): string
{
    $paid_statuses = ['processing', 'completed'];
    if ($order->is_paid() || $order->has_status($paid_statuses)) {
        return __('Receipt', 'supreme-autoparts-core');
    }
    return __('Invoice', 'supreme-autoparts-core');
}

/**
 * @param WC_Order $order
 */
function sa_core_user_can_view_invoice_order($order, int $user_id = 0): bool
{
    if (!$order instanceof WC_Order) {
        return false;
    }
    $user_id = $user_id ?: get_current_user_id();
    if ($user_id && (user_can($user_id, 'manage_woocommerce') || user_can($user_id, 'edit_shop_orders'))) {
        return true;
    }
    if ($user_id && (int) $order->get_user_id() === $user_id) {
        return true;
    }
    return false;
}

function sa_core_user_can_view_invoice(int $order_id, int $user_id = 0): bool
{
    if (!function_exists('wc_get_order')) {
        return false;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }
    return sa_core_user_can_view_invoice_order($order, $user_id);
}

/**
 * Validate request key: stable HMAC, Woo order_key, or legacy nonce.
 *
 * @param WC_Order $order
 */
function sa_core_invoice_key_valid($order, string $key, string $order_key_param = ''): bool
{
    if (!$order instanceof WC_Order) {
        return false;
    }
    $order_id = (int) $order->get_id();

    if ($key !== '') {
        $expected = sa_core_invoice_token($order_id, (string) $order->get_order_key(), (int) $order->get_user_id());
        if ($expected !== '' && hash_equals($expected, $key)) {
            return true;
        }
        // Legacy short-lived nonce (old links / emails).
        if (wp_verify_nonce($key, 'sa_invoice_' . $order_id)) {
            return true;
        }
    }

    $woo_key = $order_key_param !== '' ? $order_key_param : $key;
    if ($woo_key !== '' && hash_equals((string) $order->get_order_key(), $woo_key)) {
        return true;
    }

    return false;
}

add_action('template_redirect', static function (): void {
    if (empty($_GET['sa_invoice'])) {
        return;
    }
    $order_id = absint($_GET['sa_invoice']);
    if ($order_id <= 0 || !function_exists('wc_get_order')) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die(esc_html__('Order not found.', 'supreme-autoparts-core'), 404);
    }

    $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
    $order_key_param = isset($_GET['order_key']) ? sanitize_text_field(wp_unslash((string) $_GET['order_key'])) : '';

    $is_staff = is_user_logged_in() && (current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders'));
    $is_owner = is_user_logged_in() && (int) $order->get_user_id() === get_current_user_id() && get_current_user_id() > 0;
    $key_ok   = sa_core_invoice_key_valid($order, $key, $order_key_param);

    // Guest with valid order_key / stable token: allow without login (Woo thank-you pattern).
    // Never mint a fresh token into a login redirect — that would leak access to anyone who knows an order ID.
    if (!$is_staff && !$is_owner && !$key_ok) {
        if (!is_user_logged_in() && $key === '' && $order_key_param === '') {
            $account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();
            wp_safe_redirect(wp_login_url($account ?: home_url('/my-account/')));
            exit;
        }
        wp_die(
            esc_html__('You do not have permission to view this invoice. Open it from My Account → Orders, or use the link from your order confirmation.', 'supreme-autoparts-core'),
            esc_html__('Not allowed', 'supreme-autoparts-core'),
            ['response' => 403]
        );
    }

    // Owner, staff, or valid stable/order key: render (PDF on ?format=pdf, falls back to HTML).
    if (isset($_GET['format']) && $_GET['format'] === 'pdf' && function_exists('sa_rcpt_send_pdf_download')) {
        sa_rcpt_send_pdf_download(sa_rcpt_order_data($order));
    }
    sa_core_render_invoice_html($order);
    exit;
}, 1);

/**
 * Build invoice HTML string (for page render + email body).
 *
 * @param WC_Order $order
 */
function sa_core_build_invoice_html($order, bool $for_email = false): string
{
    $d = sa_rcpt_order_data($order);
    if ($for_email) {
        return sa_rcpt_render($d, 'email');
    }
    $id = (int) $order->get_id();
    $inv = sa_core_invoice_url($id);
    $tb = [
        ['label' => __('Print', 'supreme-autoparts-core'), 'print' => true, 'primary' => true],
        ['label' => __('Download PDF', 'supreme-autoparts-core'), 'url' => add_query_arg('format', 'pdf', $inv)],
        ['label' => $d['paid'] ? __('Email me this receipt', 'supreme-autoparts-core') : __('Email me this invoice', 'supreme-autoparts-core'), 'url' => sa_core_invoice_email_url($id)],
    ];
    if (is_user_logged_in()) {
        $tb[] = ['label' => __('Back to order', 'supreme-autoparts-core'), 'url' => $order->get_view_order_url()];
    }
    return sa_rcpt_render($d, 'page', $tb);
}

/**
 * @param WC_Order $order
 */
function sa_core_render_invoice_html($order): void
{
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    // Optional download hint when ?download=1
    if (!empty($_GET['download'])) {
        $name = 'supreme-autoparts-' . sa_core_invoice_doc_title($order) . '-' . $order->get_order_number() . '.html';
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($name) . '"');
    }
    echo sa_core_build_invoice_html($order, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Email invoice/receipt to customer billing email.
 *
 * @param WC_Order $order
 * @return true|WP_Error
 */
function sa_core_email_invoice_to_customer($order)
{
    if (!$order instanceof WC_Order) {
        return new WP_Error('sa_invoice_bad_order', __('Invalid order.', 'supreme-autoparts-core'));
    }

    $to = $order->get_billing_email();
    if (!is_email($to)) {
        $uid = (int) $order->get_user_id();
        if ($uid) {
            $user = get_userdata($uid);
            if ($user && is_email($user->user_email)) {
                $to = $user->user_email;
            }
        }
    }
    if (!is_email($to)) {
        return new WP_Error('sa_invoice_no_email', __('No customer email on this order.', 'supreme-autoparts-core'));
    }

    $order_id  = (int) $order->get_id();
    $throttle_key = 'sa_inv_mail_' . $order_id;
    $last = (int) get_transient($throttle_key);
    if ($last && (time() - $last) < 120) {
        return new WP_Error('sa_invoice_rate', __('Please wait a couple of minutes before requesting another email.', 'supreme-autoparts-core'));
    }

    $doc_title = sa_core_invoice_doc_title($order);
    $subject   = sprintf(
        /* translators: 1: Invoice|Receipt 2: order number */
        __('%1$s #%2$s from Supreme Autoparts', 'supreme-autoparts-core'),
        $doc_title,
        $order->get_order_number()
    );
    $body = sa_core_build_invoice_html($order, true);
    $from_email = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Supreme Autoparts <' . $from_email . '>',
    ];
    // Do NOT BCC store owner on customer invoices — keeps admin/customer mailboxes separated.
    unset($from_email);

    $pdf = function_exists('sa_rcpt_order_pdf') ? sa_rcpt_order_pdf($order) : '';
    $sent = wp_mail($to, $subject, $body, $headers, $pdf !== '' ? [$pdf] : []);
    if ($pdf !== '') {
        sa_rcpt_cleanup($pdf);
    }
    if (!$sent) {
        return new WP_Error('sa_invoice_send_fail', __('Could not send email. Please try again or contact support.', 'supreme-autoparts-core'));
    }

    set_transient($throttle_key, time(), 180);
    return true;
}

/**
 * admin-post handlers for "Email me this invoice/receipt".
 */
function sa_core_handle_email_invoice_request(): void
{
    $order_id = isset($_REQUEST['sa_invoice']) ? absint($_REQUEST['sa_invoice']) : 0;
    if ($order_id <= 0 || !function_exists('wc_get_order')) {
        wp_die(esc_html__('Invalid request.', 'supreme-autoparts-core'), 400);
    }

    check_admin_referer('sa_email_invoice_' . $order_id);

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die(esc_html__('Order not found.', 'supreme-autoparts-core'), 404);
    }

    $key = isset($_REQUEST['key']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['key'])) : '';
    $order_key_param = isset($_REQUEST['order_key']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['order_key'])) : '';
    $allowed = sa_core_user_can_view_invoice_order($order) || sa_core_invoice_key_valid($order, $key, $order_key_param);
    if (!$allowed) {
        wp_die(esc_html__('You do not have permission to email this invoice.', 'supreme-autoparts-core'), 403);
    }

    $result = sa_core_email_invoice_to_customer($order);
    $redirect = wp_get_referer() ?: $order->get_view_order_url();
    if (is_wp_error($result)) {
        $redirect = add_query_arg('sa_invoice_mail', 'err', $redirect);
        $redirect = add_query_arg('sa_invoice_mail_msg', rawurlencode($result->get_error_message()), $redirect);
    } else {
        $redirect = add_query_arg('sa_invoice_mail', 'ok', $redirect);
    }
    wp_safe_redirect($redirect);
    exit;
}

add_action('admin_post_sa_email_invoice', 'sa_core_handle_email_invoice_request');
add_action('admin_post_nopriv_sa_email_invoice', 'sa_core_handle_email_invoice_request');

/**
 * Flash notices on My Account after email request.
 */
add_action('woocommerce_before_account_navigation', static function (): void {
    if (empty($_GET['sa_invoice_mail'])) {
        return;
    }
    $status = sanitize_text_field(wp_unslash((string) $_GET['sa_invoice_mail']));
    if ($status === 'ok') {
        wc_print_notice(__('Invoice / receipt emailed to your billing address.', 'supreme-autoparts-core'), 'success');
        return;
    }
    $msg = isset($_GET['sa_invoice_mail_msg'])
        ? sanitize_text_field(rawurldecode(wp_unslash((string) $_GET['sa_invoice_mail_msg'])))
        : __('Could not send the invoice email.', 'supreme-autoparts-core');
    wc_print_notice($msg, 'error');
}, 5);

add_action('woocommerce_before_thankyou', static function (): void {
    if (empty($_GET['sa_invoice_mail'])) {
        return;
    }
    // Notices also useful if redirected from thank-you context — no-op if not WC account.
}, 5);

/**
 * Admin: copy invoice / payment links on order edit screen.
 */
add_action('woocommerce_admin_order_data_after_order_details', static function ($order): void {
    if (!$order instanceof WC_Order || !current_user_can('manage_woocommerce')) {
        return;
    }
    $oid = (int) $order->get_id();
    $invoice = sa_core_invoice_url($oid);
    $pay = $order->get_checkout_payment_url();
    $view = $order->get_view_order_url();
    $inv_id = 'sa-order-inv-' . $oid;
    $pay_id = 'sa-order-pay-' . $oid;
    $view_id = 'sa-order-view-' . $oid;
    echo '<div class="order_data_column sa-invoice-box">';
    echo '<h3>' . esc_html__('Supreme invoice & payment links', 'supreme-autoparts-core') . '</h3>';
    echo '<div class="sa-copy-row"><label>Invoice</label><input id="' . esc_attr($inv_id) . '" type="text" class="widefat" readonly value="' . esc_attr($invoice) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy="#' . esc_attr($inv_id) . '">Copy</button></div>';
    echo '<div class="sa-copy-row"><label>Pay</label><input id="' . esc_attr($pay_id) . '" type="text" class="widefat" readonly value="' . esc_attr($pay) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy="#' . esc_attr($pay_id) . '">Copy</button></div>';
    echo '<div class="sa-copy-row"><label>Customer</label><input id="' . esc_attr($view_id) . '" type="text" class="widefat" readonly value="' . esc_attr($view) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy="#' . esc_attr($view_id) . '">Copy</button></div>';
    echo '<p style="margin:8px 0 0;"><a class="button button-primary" href="' . esc_url($invoice) . '" target="_blank" rel="noopener">Open invoice</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=supreme-orders')) . '">Order tools</a></p>';
    echo '</div>';
});

add_filter('woocommerce_order_actions', static function (array $actions): array {
    $actions['sa_open_invoice'] = __('Open Supreme invoice', 'supreme-autoparts-core');
    return $actions;
});

add_action('woocommerce_order_action_sa_open_invoice', static function ($order): void {
    if (!$order instanceof WC_Order) {
        return;
    }
    $url = sa_core_invoice_url((int) $order->get_id());
    wp_safe_redirect($url);
    exit;
});
