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
    $custom = (int) get_theme_mod('custom_logo');
    if ($custom > 0) {
        $src = wp_get_attachment_image_url($custom, 'full');
        if (is_string($src) && $src !== '') {
            return set_url_scheme($src, 'https');
        }
    }

    $theme_uri = get_template_directory_uri();
    $candidates = [
        get_template_directory() . '/assets/logo-light.jpg' => $theme_uri . '/assets/logo-light.jpg',
        get_template_directory() . '/assets/logo-light.png' => $theme_uri . '/assets/logo-light.png',
        get_template_directory() . '/assets/logo.png'       => $theme_uri . '/assets/logo.png',
        get_template_directory() . '/assets/logo.jpg'       => $theme_uri . '/assets/logo.jpg',
    ];
    foreach ($candidates as $path => $url) {
        if (is_readable($path)) {
            return set_url_scheme($url, 'https');
        }
    }

    return set_url_scheme(home_url('/wp-content/themes/supreme-autoparts/assets/logo-light.jpg'), 'https');
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

add_action('init', static function (): void {
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
    if (!$is_staff && !$is_owner && !$key_ok) {
        if (!is_user_logged_in()) {
            // Prompt login then return to this invoice URL.
            $redirect = sa_core_invoice_url($order_id, true);
            wp_safe_redirect(wp_login_url($redirect));
            exit;
        }
        wp_die(
            esc_html__('You do not have permission to view this invoice. Open it from My Account → Orders, or use the link from your order confirmation.', 'supreme-autoparts-core'),
            esc_html__('Not allowed', 'supreme-autoparts-core'),
            ['response' => 403]
        );
    }

    // Owner, staff, or valid stable/order key: render.
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
    $store     = 'Supreme Autoparts';
    $email     = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    $currency  = $order->get_currency();
    $logo      = sa_core_invoice_logo_url();
    $doc_title = sa_core_invoice_doc_title($order);
    $order_id  = (int) $order->get_id();
    $inv_url   = sa_core_invoice_url($order_id);
    $email_url = sa_core_invoice_email_url($order_id);
    $back_url  = $order->get_view_order_url();
    $status    = $order->get_status();
    $is_paid   = $order->is_paid() || $order->has_status(['processing', 'completed']);

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo esc_html($doc_title); ?> #<?php echo esc_html($order->get_order_number()); ?> — <?php echo esc_html($store); ?></title>
  <style>
    *{box-sizing:border-box}
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;padding:1.25rem;color:#1a1a1c;background:#f4f4f5;line-height:1.5}
    .wrap{max-width:800px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(11,11,13,.08)}
    .header{background:#0B0B0D;color:#F4F4F5;padding:1.35rem 1.5rem;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem}
    .header__brand{display:flex;align-items:center;gap:.85rem}
    .header__logo{height:48px;width:auto;max-width:160px;object-fit:contain;display:block;border-radius:4px}
    .header__meta{text-align:right}
    .header__doc{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:#F5A623;font-weight:700;margin:0 0 .15rem}
    .header__num{font-size:1.35rem;font-weight:700;margin:0;color:#fff}
    .accent{height:4px;background:#F5A623}
    .body{padding:1.5rem}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem}
    .card{border:1px solid #e8e8ea;border-radius:10px;padding:1rem 1.1rem;background:#fafafa}
    .card h3{margin:0 0 .5rem;font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:#8A8A90;font-weight:700}
    .card p{margin:.2rem 0;font-size:.95rem}
    .muted{color:#555;font-size:.88rem}
    table.items{width:100%;border-collapse:collapse;margin:1rem 0;font-size:.95rem}
    table.items th{background:#0B0B0D;color:#F4F4F5;text-align:left;padding:.65rem .55rem;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase}
    table.items th.right,table.items td.right{text-align:right}
    table.items td{border-bottom:1px solid #ececee;padding:.65rem .55rem;vertical-align:top}
    table.items tr:last-child td{border-bottom:0}
    .totals{max-width:300px;margin-left:auto;width:100%}
    .totals td{border:0;padding:.35rem .4rem;font-size:.95rem}
    .totals tr td:last-child{text-align:right;font-weight:600}
    .totals .grand td{border-top:2px solid #0B0B0D;padding-top:.65rem;font-size:1.05rem;color:#0B0B0D}
    .totals .grand td:last-child{color:#F5A623}
    .badge{display:inline-block;padding:.2rem .55rem;border-radius:999px;font-size:.75rem;font-weight:700;background:<?php echo $is_paid ? '#E8F8EF' : '#FFF4E0'; ?>;color:<?php echo $is_paid ? '#0B7A3B' : '#9A6200'; ?>}
    .footer{padding:1rem 1.5rem 1.4rem;border-top:1px solid #ececee;color:#666;font-size:.85rem}
    .no-print{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 auto 1rem;max-width:800px}
    .no-print a,.no-print button{appearance:none;border:0;border-radius:8px;padding:.6rem 1rem;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem}
    .btn-primary{background:#0B0B0D;color:#fff}
    .btn-amber{background:#F5A623;color:#0B0B0D}
    .btn-outline{background:#fff;color:#0B0B0D;border:1px solid #ccc!important}
    @media (max-width:560px){
      body{padding:.65rem}
      .grid{grid-template-columns:1fr}
      .header{flex-direction:column;align-items:flex-start}
      .header__meta{text-align:left}
      table.items,table.items thead,table.items tbody,table.items th,table.items td,table.items tr{display:block;width:100%}
      table.items thead{display:none}
      table.items tr{border-bottom:1px solid #eee;padding:.55rem 0;margin:0}
      table.items td{border:0;padding:.15rem 0;display:flex;justify-content:space-between;gap:1rem}
      table.items td.right{text-align:left}
      .totals{max-width:none}
    }
    @media print{
      body{background:#fff;padding:0}
      .no-print{display:none!important}
      .wrap{box-shadow:none;border-radius:0}
    }
  </style>
</head>
<body>
<?php if (!$for_email) : ?>
  <p class="no-print">
    <button type="button" class="btn-primary" onclick="window.print()"><?php echo esc_html__('Print / Save PDF', 'supreme-autoparts-core'); ?></button>
    <a class="btn-amber" href="<?php echo esc_url($email_url); ?>"><?php
      echo $is_paid
        ? esc_html__('Email me this receipt', 'supreme-autoparts-core')
        : esc_html__('Email me this invoice', 'supreme-autoparts-core');
    ?></a>
    <a class="btn-outline" href="<?php echo esc_url($back_url); ?>"><?php echo esc_html__('Back to order', 'supreme-autoparts-core'); ?></a>
  </p>
<?php endif; ?>
  <div class="wrap">
    <header class="header">
      <div class="header__brand">
        <img class="header__logo" src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($store); ?>" width="160" height="48" />
        <div>
          <strong><?php echo esc_html($store); ?></strong>
          <div class="muted" style="color:#A0A0A8;font-size:.8rem"><?php echo esc_html($email); ?> · Nairobi, Kenya</div>
        </div>
      </div>
      <div class="header__meta">
        <p class="header__doc"><?php echo esc_html($doc_title); ?></p>
        <p class="header__num">#<?php echo esc_html($order->get_order_number()); ?></p>
      </div>
    </header>
    <div class="accent"></div>
    <div class="body">
      <div class="grid">
        <div class="card">
          <h3><?php echo esc_html__('Bill to', 'supreme-autoparts-core'); ?></h3>
          <p><?php echo wp_kses_post($order->get_formatted_billing_address() ?: esc_html($order->get_billing_email())); ?></p>
          <p class="muted"><?php echo esc_html($order->get_billing_email()); ?>
            <?php if ($order->get_billing_phone()) : ?> · <?php echo esc_html($order->get_billing_phone()); ?><?php endif; ?>
          </p>
        </div>
        <div class="card">
          <h3><?php echo esc_html__('Order details', 'supreme-autoparts-core'); ?></h3>
          <p><strong><?php echo esc_html__('Date', 'supreme-autoparts-core'); ?>:</strong> <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></p>
          <p><strong><?php echo esc_html__('Status', 'supreme-autoparts-core'); ?>:</strong> <span class="badge"><?php echo esc_html(wc_get_order_status_name($status)); ?></span></p>
          <p><strong><?php echo esc_html__('Payment', 'supreme-autoparts-core'); ?>:</strong> <?php echo esc_html($order->get_payment_method_title() ?: '—'); ?></p>
        </div>
      </div>

      <table class="items">
        <thead>
          <tr>
            <th><?php echo esc_html__('Item', 'supreme-autoparts-core'); ?></th>
            <th><?php echo esc_html__('Qty', 'supreme-autoparts-core'); ?></th>
            <th class="right"><?php echo esc_html__('Total', 'supreme-autoparts-core'); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($order->get_items() as $item) : ?>
          <tr>
            <td><?php echo esc_html($item->get_name()); ?></td>
            <td><?php echo esc_html((string) $item->get_quantity()); ?></td>
            <td class="right"><?php echo wp_kses_post($order->get_formatted_line_subtotal($item)); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <table class="totals">
        <tr><td><?php echo esc_html__('Subtotal', 'supreme-autoparts-core'); ?></td><td><?php echo wp_kses_post($order->get_subtotal_to_display()); ?></td></tr>
        <?php if ((float) $order->get_shipping_total() > 0) : ?>
          <tr><td><?php echo esc_html__('Shipping', 'supreme-autoparts-core'); ?></td><td><?php echo wp_kses_post(wc_price((float) $order->get_shipping_total(), ['currency' => $currency])); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($order->get_tax_totals() as $tax) : ?>
          <tr><td><?php echo esc_html($tax->label); ?></td><td><?php echo wp_kses_post($tax->formatted_amount); ?></td></tr>
        <?php endforeach; ?>
        <?php if ((float) $order->get_total_discount() > 0) : ?>
          <tr><td><?php echo esc_html__('Discount', 'supreme-autoparts-core'); ?></td><td>−<?php echo wp_kses_post(wc_price((float) $order->get_total_discount(), ['currency' => $currency])); ?></td></tr>
        <?php endif; ?>
        <tr class="grand"><td><?php echo esc_html__('Total', 'supreme-autoparts-core'); ?></td><td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td></tr>
      </table>

      <?php if ($order->get_customer_note()) : ?>
        <div class="card" style="margin-top:1rem">
          <h3><?php echo esc_html__('Note', 'supreme-autoparts-core'); ?></h3>
          <p><?php echo esc_html($order->get_customer_note()); ?></p>
        </div>
      <?php endif; ?>
    </div>
    <footer class="footer">
      <p><?php echo esc_html__('Thank you for shopping with Supreme Autoparts.', 'supreme-autoparts-core'); ?></p>
      <p class="muted"><?php echo esc_html($store); ?> · <?php echo esc_html($email); ?> · Nairobi, Kenya
        <?php if ($for_email) : ?>
          · <a href="<?php echo esc_url($inv_url); ?>"><?php echo esc_html__('View online', 'supreme-autoparts-core'); ?></a>
        <?php endif; ?>
      </p>
    </footer>
  </div>
</body>
</html>
    <?php
    return (string) ob_get_clean();
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
        __('%1$s #%2$s — Supreme Autoparts', 'supreme-autoparts-core'),
        $doc_title,
        $order->get_order_number()
    );
    $body = sa_core_build_invoice_html($order, true);
    $from_email = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Supreme Autoparts <' . $from_email . '>',
    ];
    // Store BCC already applied by woocommerce_email_headers for WC emails;
    // for wp_mail, optionally BCC store if policy option set.
    $bcc = (string) get_option('sa_store_admin_notify_email', $from_email);
    if (is_email($bcc) && strcasecmp($bcc, $to) !== 0) {
        $headers[] = 'Bcc: ' . $bcc;
    }

    $sent = wp_mail($to, $subject, $body, $headers);
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
