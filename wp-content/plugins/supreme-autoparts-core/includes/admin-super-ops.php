<?php
/**
 * Super Admin ops console — Customers CRM, Payments/invoices/links, Orders fulfil.
 * Real Woo actions only. Phase 4 multi-vendor/KYC stays honest elsewhere.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** User meta key: banned customers cannot authenticate. */
const SA_SUPER_BANNED_META = '_sa_banned';

/**
 * Block banned customers from logging in. Never blocks admins / shop_managers.
 *
 * @param WP_User|WP_Error|null $user
 * @return WP_User|WP_Error|null
 */
function sa_core_super_block_banned_authenticate($user, string $username = '', string $password = '')
{
    if ($user instanceof WP_User) {
        if (user_can($user, 'manage_woocommerce') || user_can($user, 'manage_options')) {
            return $user;
        }
        if (get_user_meta((int) $user->ID, SA_SUPER_BANNED_META, true)) {
            return new WP_Error(
                'sa_banned',
                __('This account has been suspended. Contact support if you believe this is an error.', 'supreme-autoparts-core')
            );
        }
    }
    return $user;
}
add_filter('authenticate', 'sa_core_super_block_banned_authenticate', 99, 3);

/** Whether a user may be banned (customers only — never staff). */
function sa_core_super_user_bannable(int $user_id): bool
{
    if ($user_id <= 0) {
        return false;
    }
    $u = get_userdata($user_id);
    if (!$u) {
        return false;
    }
    if (user_can($u, 'manage_woocommerce') || user_can($u, 'manage_options') || user_can($u, 'edit_shop_orders')) {
        return false;
    }
    return true;
}

function sa_core_super_user_is_banned(int $user_id): bool
{
    return $user_id > 0 && (bool) get_user_meta($user_id, SA_SUPER_BANNED_META, true);
}

/** Public /pay URL optionally prefilled (open-amount Whop page). */
function sa_core_super_open_pay_url(float $amount = 0.0, string $email = '', string $note = ''): string
{
    $args = [];
    if ($amount >= 1) {
        $args['amount'] = number_format($amount, 2, '.', '');
    }
    if ($email !== '' && is_email($email)) {
        $args['email'] = $email;
    }
    if ($note !== '') {
        $args['note'] = substr($note, 0, 120);
    }
    $base = home_url('/pay/');
    return $args ? add_query_arg($args, $base) : $base;
}

/**
 * @return array{notice:string,type:string}
 */
function sa_core_super_ops_flash(): array
{
    $key = 'sa_super_ops_flash_' . get_current_user_id();
    $flash = get_transient($key);
    if (is_array($flash) && isset($flash['notice'])) {
        delete_transient($key);
        return [
            'notice' => (string) $flash['notice'],
            'type'   => (string) ($flash['type'] ?? 'ok'),
        ];
    }
    return ['notice' => '', 'type' => 'ok'];
}

function sa_core_super_ops_set_flash(string $notice, string $type = 'ok'): void
{
    set_transient('sa_super_ops_flash_' . get_current_user_id(), [
        'notice' => $notice,
        'type'   => $type,
    ], 60);
}

/** Redirect back to current Super Admin page preserving useful GET args. */
function sa_core_super_ops_redirect(string $page, array $extra = []): void
{
    $args = array_merge(['page' => $page], $extra);
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

/**
 * Handle POST actions for Orders / Customers / Payments (admin_init).
 */
function sa_core_super_ops_handle_post(): void
{
    if (!is_admin() || !current_user_can('manage_woocommerce')) {
        return;
    }
    $page = isset($_REQUEST['page']) ? sanitize_key((string) $_REQUEST['page']) : '';
    if (!in_array($page, ['sa-super-orders', 'sa-super-customers', 'sa-super-payments'], true)) {
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    // —— Orders: status / fulfil / tracking ——
    if ($page === 'sa-super-orders' && isset($_POST['sa_order_action'])) {
        if (!check_admin_referer('sa_super_orders_action')) {
            return;
        }
        $oid = absint($_POST['order_id'] ?? 0);
        $action = sanitize_key((string) ($_POST['sa_order_action'] ?? ''));
        $order = $oid && function_exists('wc_get_order') ? wc_get_order($oid) : false;
        if (!$order instanceof WC_Order) {
            sa_core_super_ops_set_flash('Order not found.', 'warn');
            sa_core_super_ops_redirect('sa-super-orders');
        }
        $allowed_status = ['processing', 'completed', 'on-hold', 'cancelled', 'pending'];
        if (in_array($action, $allowed_status, true)) {
            $order->update_status($action, sprintf('Super Admin: status → %s.', $action), true);
            if (function_exists('sa_core_audit_log')) {
                sa_core_audit_log('order.status_update', ['order_id' => $oid, 'status' => $action], 'notice');
            }
            sa_core_super_ops_set_flash('Order #' . $order->get_order_number() . ' → ' . wc_get_order_status_name($action));
            sa_core_super_ops_redirect('sa-super-orders', array_filter([
                'status' => isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '',
                's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '',
            ]));
        }
        if ($action === 'fulfil') {
            $tracking = sanitize_text_field(wp_unslash((string) ($_POST['tracking'] ?? '')));
            $note = 'Shipped';
            if ($tracking !== '') {
                $note .= ' — tracking: ' . $tracking;
                $order->update_meta_data('_sa_tracking', $tracking);
            }
            $order->add_order_note($note, 1, true); // customer-visible
            $order->update_status('completed', 'Super Admin: marked shipped / completed.', true);
            $order->save();
            if (function_exists('sa_core_audit_log')) {
                sa_core_audit_log('order.fulfil', ['order_id' => $oid, 'tracking' => $tracking], 'notice');
            }
            sa_core_super_ops_set_flash('Order #' . $order->get_order_number() . ' marked shipped' . ($tracking ? ' (' . $tracking . ')' : '') . '.');
            sa_core_super_ops_redirect('sa-super-orders');
        }
        if ($action === 'note') {
            $note = sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? '')));
            if ($note === '') {
                sa_core_super_ops_set_flash('Note empty.', 'warn');
                sa_core_super_ops_redirect('sa-super-orders');
            }
            $customer = !empty($_POST['note_customer']);
            $order->add_order_note($note, $customer ? 1 : 0, true);
            sa_core_super_ops_set_flash('Note added to #' . $order->get_order_number() . ($customer ? ' (customer-visible)' : ' (private)'));
            sa_core_super_ops_redirect('sa-super-orders');
        }
        sa_core_super_ops_set_flash('Unknown order action.', 'warn');
        sa_core_super_ops_redirect('sa-super-orders');
    }

    // —— Customers: ban / note / OTP / password ——
    if ($page === 'sa-super-customers' && isset($_POST['sa_customer_action'])) {
        if (!check_admin_referer('sa_super_customers_action')) {
            return;
        }
        $uid = absint($_POST['user_id'] ?? 0);
        $action = sanitize_key((string) ($_POST['sa_customer_action'] ?? ''));
        $user = $uid ? get_userdata($uid) : false;
        if (!$user) {
            sa_core_super_ops_set_flash('Customer not found.', 'warn');
            sa_core_super_ops_redirect('sa-super-customers');
        }

        if ($action === 'ban') {
            if (!sa_core_super_user_bannable($uid)) {
                sa_core_super_ops_set_flash('Cannot ban staff / admin accounts.', 'warn');
                sa_core_super_ops_redirect('sa-super-customers', ['user' => $uid]);
            }
            update_user_meta($uid, SA_SUPER_BANNED_META, '1');
            update_user_meta($uid, '_sa_banned_at', gmdate('c'));
            update_user_meta($uid, '_sa_banned_by', get_current_user_id());
            if (function_exists('sa_core_audit_log')) {
                sa_core_audit_log('customer.ban', ['user_id' => $uid, 'email' => $user->user_email], 'warning');
            }
            sa_core_super_ops_set_flash('Banned ' . $user->user_email . ' — login blocked.');
            sa_core_super_ops_redirect('sa-super-customers', ['user' => $uid]);
        }
        if ($action === 'unban') {
            delete_user_meta($uid, SA_SUPER_BANNED_META);
            delete_user_meta($uid, '_sa_banned_at');
            if (function_exists('sa_core_audit_log')) {
                sa_core_audit_log('customer.unban', ['user_id' => $uid, 'email' => $user->user_email], 'notice');
            }
            sa_core_super_ops_set_flash('Unbanned ' . $user->user_email . '.');
            sa_core_super_ops_redirect('sa-super-customers', ['user' => $uid]);
        }
        if ($action === 'note') {
            $note = sanitize_textarea_field(wp_unslash((string) ($_POST['sa_note'] ?? '')));
            if ($note === '') {
                sa_core_super_ops_set_flash('Note empty.', 'warn');
                sa_core_super_ops_redirect('sa-super-customers', ['user' => $uid]);
            }
            $notes = function_exists('sa_core_ultra_customer_notes') ? sa_core_ultra_customer_notes() : (array) get_option('sa_ultra_customer_notes', []);
            if (!is_array($notes)) {
                $notes = [];
            }
            array_unshift($notes, [
                'user_id' => $uid,
                'note'    => $note,
                'author'  => wp_get_current_user()->user_login ?: 'admin',
                'at'      => time(),
            ]);
            update_option('sa_ultra_customer_notes', array_slice($notes, 0, 200), false);
            sa_core_super_ops_set_flash('Note saved for #' . $uid);
            sa_core_super_ops_redirect('sa-super-customers', ['user' => $uid]);
        }
        if ($action === 'send_otp') {
            if (!function_exists('sa_core_otp_send')) {
                sa_core_super_ops_set_flash('OTP helper not available.', 'warn');
                sa_core_super_ops_redirect('sa-super-customers', ['user' => $uid]);
            }
            $result = sa_core_otp_send($user->user_email);
            if (is_wp_error($result)) {
                sa_core_super_ops_set_flash('OTP failed: ' . $result->get_error_message(), 'warn');
            } elseif ($result === true || $result === null) {
                sa_core_super_ops_set_flash('OTP email sent to ' . $user->user_email);
            } else {
                // Some helpers return truthy string / array
                sa_core_super_ops_set_flash('OTP email triggered for ' . $user->user_email);
            }
            if (function_exists('sa_core_audit_log')) {
                sa_core_audit_log('customer.otp_send', ['user_id' => $uid], 'notice');
            }
            sa_core_super_ops_redirect('sa-super-customers', ['user' => $uid]);
        }
        if ($action === 'send_password') {
            // WP lost-password mail (honest existing path).
            $sent = retrieve_password($user->user_login);
            if ($sent === true) {
                sa_core_super_ops_set_flash('Password reset email sent to ' . $user->user_email);
            } elseif (is_wp_error($sent)) {
                sa_core_super_ops_set_flash('Password mail failed: ' . $sent->get_error_message(), 'warn');
            } else {
                sa_core_super_ops_set_flash('Password reset triggered for ' . $user->user_email);
            }
            if (function_exists('sa_core_audit_log')) {
                sa_core_audit_log('customer.password_reset', ['user_id' => $uid], 'notice');
            }
            sa_core_super_ops_redirect('sa-super-customers', ['user' => $uid]);
        }
        sa_core_super_ops_set_flash('Unknown customer action.', 'warn');
        sa_core_super_ops_redirect('sa-super-customers', ['user' => $uid]);
    }

    // —— Payments: create shareable link ——
    if ($page === 'sa-super-payments' && isset($_POST['sa_create_pay_link'])) {
        if (!check_admin_referer('sa_super_payments_link')) {
            return;
        }
        $amount = round((float) wp_unslash((string) ($_POST['amount'] ?? '0')), 2);
        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
        $note = sanitize_text_field(wp_unslash((string) ($_POST['note'] ?? '')));
        $oid = absint($_POST['order_id'] ?? 0);

        $link = '';
        $label = '';
        if ($oid > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($oid);
            if ($order instanceof WC_Order) {
                $link = $order->get_checkout_payment_url();
                $label = 'Order #' . $order->get_order_number() . ' pay link';
                if ($amount < 1) {
                    $amount = (float) $order->get_total();
                }
                if ($email === '') {
                    $email = (string) $order->get_billing_email();
                }
            } else {
                sa_core_super_ops_set_flash('Order #' . $oid . ' not found.', 'warn');
                sa_core_super_ops_redirect('sa-super-payments');
            }
        }
        if ($link === '') {
            if ($amount < 1) {
                sa_core_super_ops_set_flash('Enter an amount ≥ $1.00 (or a valid order ID).', 'warn');
                sa_core_super_ops_redirect('sa-super-payments');
            }
            $link = sa_core_super_open_pay_url($amount, $email, $note !== '' ? $note : ('Payment $' . number_format($amount, 2)));
            $label = 'Open-pay $' . number_format($amount, 2);
        }
        // Store last generated link for the UI to show.
        set_transient('sa_super_last_pay_link_' . get_current_user_id(), [
            'url'    => $link,
            'label'  => $label,
            'amount' => $amount,
            'email'  => $email,
            'order'  => $oid,
            'at'     => time(),
        ], HOUR_IN_SECONDS);
        if (function_exists('sa_core_audit_log')) {
            sa_core_audit_log('payment.link_create', [
                'amount' => $amount,
                'email'  => $email,
                'order'  => $oid,
                'url'    => $link,
            ], 'notice');
        }
        sa_core_super_ops_set_flash('Payment link ready — copy below.');
        sa_core_super_ops_redirect('sa-super-payments', ['link' => '1']);
    }
}
add_action('admin_init', 'sa_core_super_ops_handle_post', 20);

/** Compact POST button form for order row actions. */
function sa_core_super_order_action_btn(int $oid, string $action, string $label, string $class = ''): void
{
    echo '<form method="post" style="display:inline;margin:0 2px;">';
    wp_nonce_field('sa_super_orders_action');
    echo '<input type="hidden" name="page" value="sa-super-orders" />';
    echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $oid) . '" />';
    echo '<button type="submit" name="sa_order_action" value="' . esc_attr($action) . '" class="button ' . esc_attr($class) . '" style="margin:1px;">'
        . esc_html($label) . '</button>';
    echo '</form>';
}

/** Render Orders ops console. */
function sa_core_super_render_orders(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $flash = sa_core_super_ops_flash();
    $counts = function_exists('sa_core_super_order_status_counts') ? sa_core_super_order_status_counts() : [];

    $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : ''; // phpcs:ignore
    $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : ''; // phpcs:ignore
    $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash((string) $_GET['date_from'])) : ''; // phpcs:ignore
    $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash((string) $_GET['date_to'])) : ''; // phpcs:ignore
    $pay_method = isset($_GET['payment_method']) ? sanitize_key((string) $_GET['payment_method']) : ''; // phpcs:ignore
    $min_total = isset($_GET['min_total']) ? (float) wp_unslash((string) $_GET['min_total']) : 0.0; // phpcs:ignore
    $max_total = isset($_GET['max_total']) ? (float) wp_unslash((string) $_GET['max_total']) : 0.0; // phpcs:ignore

    $args = [
        'limit'   => 40,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
    ];
    if ($status !== '' && $status !== 'all') {
        $args['status'] = $status;
    } else {
        $args['status'] = array_keys(array_filter($counts ?: ['pending' => 1, 'processing' => 1, 'on-hold' => 1, 'completed' => 1, 'cancelled' => 1, 'failed' => 1, 'refunded' => 1], static fn ($n) => true));
    }
    if ($q !== '') {
        // Woo search: order number / email / billing name
        $args['s'] = $q;
        if (is_numeric($q)) {
            $args['s'] = $q;
        }
    }
    if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $args['date_created'] = $date_from . '...' . ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) ? $date_to : gmdate('Y-m-d'));
    } elseif ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $args['date_created'] = '1970-01-01...' . $date_to;
    }
    if ($pay_method !== '') {
        $args['payment_method'] = $pay_method;
    }

    $recent = function_exists('wc_get_orders') ? wc_get_orders($args) : [];
    // Client-side min/max total filter (Woo query lacks reliable min/max across HPOS/legacy).
    if (($min_total > 0 || $max_total > 0) && is_array($recent)) {
        $recent = array_values(array_filter($recent, static function ($o) use ($min_total, $max_total) {
            if (!$o instanceof WC_Order) {
                return false;
            }
            $t = (float) $o->get_total();
            if ($min_total > 0 && $t < $min_total) {
                return false;
            }
            if ($max_total > 0 && $t > $max_total) {
                return false;
            }
            return true;
        }));
    }

    // Gateways for filter dropdown
    $gateways = [];
    if (function_exists('WC') && WC()->payment_gateways()) {
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gw) {
            $gateways[$id] = $gw->get_method_title() ?: $id;
        }
    }

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Orders', 'Ops console — filters, status updates, fulfil + tracking, invoice/pay copy. Real Woo writes.');
    if ($flash['notice'] !== '') {
        echo '<div class="sa-inline-notice sa-inline-notice--' . esc_attr($flash['type']) . '">' . esc_html($flash['notice']) . '</div>';
    }

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button button-primary" href="' . esc_url(function_exists('sa_core_super_orders_url') ? sa_core_super_orders_url() : admin_url('admin.php?page=wc-orders')) . '">All Woo orders</a>';
    foreach (['processing' => 'Processing', 'on-hold' => 'On hold', 'pending' => 'Pending pay', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'failed' => 'Failed'] as $st => $lab) {
        $n = (int) ($counts[$st] ?? 0);
        $cls = ($status === $st) ? 'button-primary' : '';
        echo '<a class="button ' . esc_attr($cls) . '" href="' . esc_url(admin_url('admin.php?page=sa-super-orders&status=' . $st)) . '">' . esc_html($lab) . ' (' . esc_html((string) $n) . ')</a>';
    }
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-orders')) . '">Clear</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-payments')) . '">Payments</a>';
    echo '</div>';

    echo '<div class="sa-ultra__grid" style="margin-bottom:16px;">';
    foreach (['processing' => 'Processing', 'pending' => 'Awaiting payment', 'on-hold' => 'On hold', 'completed' => 'Completed'] as $st => $lab) {
        echo '<div class="sa-panel"><p class="sa-muted" style="margin:0;">' . esc_html($lab) . '</p>';
        echo '<p style="font-size:1.75rem;margin:4px 0 0;font-weight:700;">' . esc_html((string) ($counts[$st] ?? 0)) . '</p></div>';
    }
    echo '</div>';

    echo '<form method="get" class="sa-form-grid sa-ops-filters" style="margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="sa-super-orders" />';
    echo '<label>Status <select name="status"><option value="">All</option>';
    foreach (['pending', 'on-hold', 'processing', 'completed', 'cancelled', 'failed', 'refunded'] as $st) {
        echo '<option value="' . esc_attr($st) . '"' . selected($status, $st, false) . '>' . esc_html(wc_get_order_status_name($st)) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Search <input type="search" name="s" value="' . esc_attr($q) . '" placeholder="order # / email" /></label>';
    echo '<label>From <input type="date" name="date_from" value="' . esc_attr($date_from) . '" /></label>';
    echo '<label>To <input type="date" name="date_to" value="' . esc_attr($date_to) . '" /></label>';
    echo '<label>Payment <select name="payment_method"><option value="">Any</option>';
    foreach ($gateways as $gid => $gtitle) {
        echo '<option value="' . esc_attr($gid) . '"' . selected($pay_method, $gid, false) . '>' . esc_html($gtitle) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Min total <input type="number" step="0.01" min="0" name="min_total" value="' . esc_attr($min_total > 0 ? (string) $min_total : '') . '" /></label>';
    echo '<label>Max total <input type="number" step="0.01" min="0" name="max_total" value="' . esc_attr($max_total > 0 ? (string) $max_total : '') . '" /></label>';
    echo '<button class="button button-primary" type="submit">Filter</button>';
    echo '</form>';

    echo '<div class="sa-panel"><div class="sa-panel__head"><h2 class="sa-panel__title">Orders</h2>';
    echo '<span class="sa-muted">' . esc_html((string) count($recent)) . ' shown</span></div>';
    echo '<div class="sa-table-wrap"><table class="sa-table sa-ops-table"><thead><tr>';
    echo '<th>Order</th><th>Date</th><th>Customer</th><th>Status</th><th>Total</th><th>Method</th><th>Invoice / Pay</th><th>Actions</th>';
    echo '</tr></thead><tbody>';
    if (!$recent) {
        echo '<tr><td colspan="8" class="sa-muted">No orders match filters.</td></tr>';
    } else {
        foreach ($recent as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            $oid = (int) $order->get_id();
            $inv = function_exists('sa_core_invoice_url') ? sa_core_invoice_url($oid) : '';
            $pay = $order->get_checkout_payment_url();
            $name = trim($order->get_formatted_billing_full_name());
            if ($name === '') {
                $name = $order->get_billing_email() ?: '—';
            }
            $cid = (int) $order->get_customer_id();
            $st = $order->get_status();
            $needs_pay = in_array($st, ['pending', 'on-hold', 'failed'], true) || (!$order->is_paid() && !in_array($st, ['completed', 'cancelled', 'refunded'], true));
            echo '<tr>';
            echo '<td><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a></td>';
            echo '<td>' . esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : '') . '</td>';
            echo '<td>' . esc_html($name);
            if ($cid > 0) {
                echo '<br><a href="' . esc_url(admin_url('admin.php?page=sa-super-customers&user=' . $cid)) . '">Customer #' . esc_html((string) $cid) . '</a>';
            }
            echo '<br><span class="sa-muted" style="font-size:11px;">' . esc_html((string) $order->get_billing_email()) . '</span></td>';
            echo '<td>' . esc_html(wc_get_order_status_name($st)) . '</td>';
            echo '<td>' . wp_kses_post($order->get_formatted_order_total()) . '</td>';
            echo '<td>' . esc_html($order->get_payment_method_title() ?: $order->get_payment_method() ?: '—') . '</td>';
            echo '<td>';
            if ($inv !== '') {
                echo '<div class="sa-copy-row"><input type="text" readonly value="' . esc_attr($inv) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy>Copy inv</button>';
                echo ' <a class="button" href="' . esc_url($inv) . '" target="_blank" rel="noopener">Open</a></div>';
            }
            if ($needs_pay) {
                echo '<div class="sa-copy-row" style="margin-top:4px;"><input type="text" readonly value="' . esc_attr($pay) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy>Copy pay</button></div>';
            }
            echo '</td><td class="sa-ops-actions">';
            if ($st !== 'processing') {
                sa_core_super_order_action_btn($oid, 'processing', 'Processing');
            }
            if ($st !== 'completed') {
                sa_core_super_order_action_btn($oid, 'completed', 'Complete', 'button-primary');
            }
            if ($st !== 'on-hold') {
                sa_core_super_order_action_btn($oid, 'on-hold', 'Hold');
            }
            if ($st !== 'cancelled') {
                sa_core_super_order_action_btn($oid, 'cancelled', 'Cancel');
            }
            // Quick fulfil
            echo '<details class="sa-ops-details"><summary class="button">Fulfil…</summary>';
            echo '<form method="post" class="sa-ops-inline-form">';
            wp_nonce_field('sa_super_orders_action');
            echo '<input type="hidden" name="page" value="sa-super-orders" />';
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $oid) . '" />';
            echo '<input type="text" name="tracking" placeholder="Tracking #" style="width:140px;" />';
            echo '<button type="submit" name="sa_order_action" value="fulfil" class="button button-primary">Ship + complete</button>';
            echo '</form></details>';
            echo ' <a class="button" href="' . esc_url($order->get_edit_order_url()) . '">Edit</a>';
            if ($order->get_total() > 0 && in_array($st, ['processing', 'completed', 'on-hold'], true)) {
                echo ' <a class="button" href="' . esc_url($order->get_edit_order_url() . '#woocommerce-order-items') . '">Refunds</a>';
            }
            echo '</td></tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

/** @return list<WP_User> */
function sa_core_super_customers_query(array $filters): array
{
    $args = [
        'role'    => 'customer',
        'number'  => 60,
        'orderby' => 'registered',
        'order'   => 'DESC',
    ];
    $q = (string) ($filters['s'] ?? '');
    if ($q !== '') {
        $args['search'] = '*' . $q . '*';
        $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }
    $meta_query = [];
    $origin = (string) ($filters['origin'] ?? '');
    if ($origin === 'guest') {
        $meta_query[] = ['key' => '_sa_guest_checkout_account', 'value' => '1'];
    } elseif ($origin === 'register') {
        $meta_query[] = [
            'relation' => 'OR',
            ['key' => '_sa_guest_checkout_account', 'compare' => 'NOT EXISTS'],
            ['key' => '_sa_guest_checkout_account', 'value' => '1', 'compare' => '!='],
        ];
    }
    $banned = (string) ($filters['banned'] ?? '');
    if ($banned === 'yes') {
        $meta_query[] = ['key' => SA_SUPER_BANNED_META, 'value' => '1'];
    } elseif ($banned === 'no') {
        $meta_query[] = [
            'relation' => 'OR',
            ['key' => SA_SUPER_BANNED_META, 'compare' => 'NOT EXISTS'],
            ['key' => SA_SUPER_BANNED_META, 'value' => '1', 'compare' => '!='],
        ];
    }
    if ($meta_query) {
        if (count($meta_query) > 1) {
            $args['meta_query'] = array_merge(['relation' => 'AND'], $meta_query);
        } else {
            $args['meta_query'] = $meta_query;
        }
    }
    $users = get_users($args);
    $has_orders = (string) ($filters['has_orders'] ?? '');
    if ($has_orders === '' || !function_exists('wc_get_customer_order_count')) {
        return $users;
    }
    return array_values(array_filter($users, static function ($u) use ($has_orders) {
        $n = (int) wc_get_customer_order_count($u->ID);
        if ($has_orders === 'yes') {
            return $n > 0;
        }
        if ($has_orders === 'zero') {
            return $n === 0;
        }
        return true;
    }));
}

/** Render customer detail drawer/page. */
function sa_core_super_render_customer_detail(int $uid): void
{
    $user = get_userdata($uid);
    if (!$user) {
        echo '<div class="sa-inline-notice sa-inline-notice--warn">Customer #' . esc_html((string) $uid) . ' not found.</div>';
        return;
    }
    $banned = sa_core_super_user_is_banned($uid);
    $guest = (bool) get_user_meta($uid, '_sa_guest_checkout_account', true);
    $phone = (string) get_user_meta($uid, 'billing_phone', true);
    $billing = [
        'first'   => (string) get_user_meta($uid, 'billing_first_name', true),
        'last'    => (string) get_user_meta($uid, 'billing_last_name', true),
        'company' => (string) get_user_meta($uid, 'billing_company', true),
        'addr1'   => (string) get_user_meta($uid, 'billing_address_1', true),
        'addr2'   => (string) get_user_meta($uid, 'billing_address_2', true),
        'city'    => (string) get_user_meta($uid, 'billing_city', true),
        'state'   => (string) get_user_meta($uid, 'billing_state', true),
        'postcode'=> (string) get_user_meta($uid, 'billing_postcode', true),
        'country' => (string) get_user_meta($uid, 'billing_country', true),
    ];
    $order_count = function_exists('wc_get_customer_order_count') ? (int) wc_get_customer_order_count($uid) : 0;
    $spent = function_exists('wc_get_customer_total_spent') ? (float) wc_get_customer_total_spent($uid) : 0.0;
    $orders = function_exists('wc_get_orders') ? wc_get_orders([
        'customer_id' => $uid,
        'limit'       => 15,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]) : [];
    $last = $orders[0] ?? null;
    $roles = implode(', ', $user->roles ?: []);
    $notes = function_exists('sa_core_ultra_customer_notes') ? sa_core_ultra_customer_notes() : (array) get_option('sa_ultra_customer_notes', []);
    $user_notes = array_values(array_filter(is_array($notes) ? $notes : [], static fn ($r) => (int) ($r['user_id'] ?? 0) === $uid));

    echo '<div class="sa-panel sa-customer-detail" style="margin-bottom:16px;">';
    echo '<div class="sa-panel__head"><h2 class="sa-panel__title">Customer #' . esc_html((string) $uid) . ' — ' . esc_html($user->display_name) . '</h2>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-customers')) . '">← All customers</a></div>';

    if ($banned) {
        echo '<div class="sa-inline-notice sa-inline-notice--warn">BANNED — login blocked via authenticate filter.</div>';
    }

    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h3 class="sa-panel__title">Profile</h3><ul class="sa-note-list">';
    echo '<li><strong>Email:</strong> <a href="mailto:' . esc_attr($user->user_email) . '">' . esc_html($user->user_email) . '</a></li>';
    echo '<li><strong>Phone:</strong> ' . esc_html($phone !== '' ? $phone : '—') . '</li>';
    echo '<li><strong>Origin:</strong> ' . esc_html($guest ? 'guest checkout' : 'register / admin') . '</li>';
    echo '<li><strong>Roles:</strong> ' . esc_html($roles ?: '—') . '</li>';
    echo '<li><strong>Registered:</strong> ' . esc_html(mysql2date('Y-m-d H:i', $user->user_registered)) . ' EAT</li>';
    echo '<li><strong>Orders:</strong> ' . esc_html((string) $order_count) . '</li>';
    echo '<li><strong>Total spent:</strong> ' . wp_kses_post(function_exists('wc_price') ? wc_price($spent) : '$' . number_format($spent, 2)) . '</li>';
    echo '<li><strong>Last order:</strong> ';
    if ($last instanceof WC_Order) {
        echo '<a href="' . esc_url($last->get_edit_order_url()) . '">#' . esc_html($last->get_order_number()) . '</a> · '
            . esc_html($last->get_date_created() ? $last->get_date_created()->date_i18n('Y-m-d') : '')
            . ' · ' . esc_html(wc_get_order_status_name($last->get_status()));
    } else {
        echo '—';
    }
    echo '</li></ul></div>';

    echo '<div class="sa-panel"><h3 class="sa-panel__title">Billing</h3>';
    $line = trim($billing['first'] . ' ' . $billing['last']);
    echo '<p>' . esc_html($line !== '' ? $line : '—') . '</p>';
    if ($billing['company'] !== '') {
        echo '<p>' . esc_html($billing['company']) . '</p>';
    }
    echo '<p class="sa-muted">' . esc_html(trim(implode(', ', array_filter([
        $billing['addr1'], $billing['addr2'], $billing['city'], $billing['state'], $billing['postcode'], $billing['country'],
    ]))) ?: 'No billing address on file') . '</p>';
    echo '</div>';

    echo '<div class="sa-panel"><h3 class="sa-panel__title">Actions</h3><div class="sa-actions" style="display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button" href="' . esc_url(get_edit_user_link($uid)) . '">Edit user</a>';
    echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=shop_order&_customer_user=' . $uid)) . '">Woo orders</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-orders&search=' . rawurlencode($user->user_email))) . '">HPOS search</a>';

    if (sa_core_super_user_bannable($uid)) {
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('sa_super_customers_action');
        echo '<input type="hidden" name="page" value="sa-super-customers" />';
        echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $uid) . '" />';
        if ($banned) {
            echo '<button type="submit" name="sa_customer_action" value="unban" class="button button-primary">Unban</button>';
        } else {
            echo '<button type="submit" name="sa_customer_action" value="ban" class="button" onclick="return confirm(\'Ban this customer? They will be unable to log in.\');">Ban / suspend</button>';
        }
        echo '</form>';
    }

    if (function_exists('sa_core_otp_send')) {
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('sa_super_customers_action');
        echo '<input type="hidden" name="page" value="sa-super-customers" />';
        echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $uid) . '" />';
        echo '<button type="submit" name="sa_customer_action" value="send_otp" class="button">Send OTP email</button>';
        echo '</form>';
    }
    echo '<form method="post" style="display:inline;">';
    wp_nonce_field('sa_super_customers_action');
    echo '<input type="hidden" name="page" value="sa-super-customers" />';
    echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $uid) . '" />';
    echo '<button type="submit" name="sa_customer_action" value="send_password" class="button">Send password reset</button>';
    echo '</form>';
    echo '</div></div></div>';

    echo '<div class="sa-ultra__grid" style="margin-top:16px;">';
    echo '<div class="sa-panel"><h3 class="sa-panel__title">Recent orders</h3>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th></tr></thead><tbody>';
    if (!$orders) {
        echo '<tr><td colspan="4" class="sa-muted">No orders.</td></tr>';
    } else {
        foreach ($orders as $o) {
            if (!$o instanceof WC_Order) {
                continue;
            }
            echo '<tr>';
            echo '<td><a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a></td>';
            echo '<td>' . esc_html($o->get_date_created() ? $o->get_date_created()->date_i18n('Y-m-d H:i') : '') . '</td>';
            echo '<td>' . esc_html(wc_get_order_status_name($o->get_status())) . '</td>';
            echo '<td>' . wp_kses_post($o->get_formatted_order_total()) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div>';

    echo '<div class="sa-panel"><h3 class="sa-panel__title">Staff notes</h3>';
    echo '<form method="post" class="sa-form-grid" style="margin-bottom:12px;">';
    wp_nonce_field('sa_super_customers_action');
    echo '<input type="hidden" name="page" value="sa-super-customers" />';
    echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $uid) . '" />';
    echo '<label>Note <textarea name="sa_note" rows="3" placeholder="Internal only"></textarea></label>';
    echo '<button type="submit" name="sa_customer_action" value="note" class="button button-primary">Save note</button>';
    echo '</form><ul class="sa-note-list">';
    if (!$user_notes) {
        echo '<li class="sa-muted">No notes for this customer.</li>';
    } else {
        foreach (array_slice($user_notes, 0, 20) as $row) {
            $when = !empty($row['at']) ? wp_date('Y-m-d H:i', (int) $row['at']) : '';
            echo '<li><div class="sa-note-list__meta">' . esc_html((string) ($row['author'] ?? '') . ' · ' . $when) . ' EAT</div>';
            echo '<div>' . esc_html((string) ($row['note'] ?? '')) . '</div></li>';
        }
    }
    echo '</ul></div></div></div>';
}

function sa_core_super_render_customers(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $flash = sa_core_super_ops_flash();
    $detail_id = isset($_GET['user']) ? absint($_GET['user']) : 0; // phpcs:ignore

    $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : ''; // phpcs:ignore
    $origin = isset($_GET['origin']) ? sanitize_key((string) $_GET['origin']) : ''; // phpcs:ignore
    $has_orders = isset($_GET['has_orders']) ? sanitize_key((string) $_GET['has_orders']) : ''; // phpcs:ignore
    $banned_f = isset($_GET['banned']) ? sanitize_key((string) $_GET['banned']) : ''; // phpcs:ignore

    $guest_origin = (int) count(get_users([
        'role' => 'customer', 'number' => -1, 'fields' => 'ID',
        'meta_key' => '_sa_guest_checkout_account', 'meta_value' => '1',
    ]));
    $banned_n = (int) count(get_users([
        'role' => 'customer', 'number' => -1, 'fields' => 'ID',
        'meta_key' => SA_SUPER_BANNED_META, 'meta_value' => '1',
    ]));

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header(
        'Customers',
        'CRM — search, origin, LTV, ban/suspend, OTP/password, staff notes. Ban never applies to admins/shop managers.'
    );
    if ($flash['notice'] !== '') {
        echo '<div class="sa-inline-notice sa-inline-notice--' . esc_attr($flash['type']) . '">' . esc_html($flash['notice']) . '</div>';
    }

    if ($detail_id > 0) {
        sa_core_super_render_customer_detail($detail_id);
        echo '</div>';
        return;
    }

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('users.php?role=customer')) . '">All customers (Users)</a>';
    echo '<a class="button" href="' . esc_url(admin_url('user-new.php')) . '">Add user</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=account')) . '">Account settings</a>';
    echo '<span class="sa-muted">Guest-origin: <strong>' . esc_html((string) $guest_origin) . '</strong> · Banned: <strong>' . esc_html((string) $banned_n) . '</strong></span>';
    echo '</div>';

    echo '<form method="get" class="sa-form-grid sa-ops-filters" style="margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="sa-super-customers" />';
    echo '<label>Search <input type="search" name="s" value="' . esc_attr($q) . '" placeholder="email or name" /></label>';
    echo '<label>Origin <select name="origin"><option value="">Any</option>';
    echo '<option value="guest"' . selected($origin, 'guest', false) . '>Guest checkout</option>';
    echo '<option value="register"' . selected($origin, 'register', false) . '>Register / admin</option>';
    echo '</select></label>';
    echo '<label>Orders <select name="has_orders"><option value="">Any</option>';
    echo '<option value="yes"' . selected($has_orders, 'yes', false) . '>Has orders</option>';
    echo '<option value="zero"' . selected($has_orders, 'zero', false) . '>Zero orders</option>';
    echo '</select></label>';
    echo '<label>Status <select name="banned"><option value="">Any</option>';
    echo '<option value="no"' . selected($banned_f, 'no', false) . '>Active</option>';
    echo '<option value="yes"' . selected($banned_f, 'yes', false) . '>Banned</option>';
    echo '</select></label>';
    echo '<button class="button button-primary" type="submit">Filter</button>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-customers')) . '">Clear</a>';
    echo '</form>';

    $customers = sa_core_super_customers_query([
        's' => $q, 'origin' => $origin, 'has_orders' => $has_orders, 'banned' => $banned_f,
    ]);

    echo '<div class="sa-panel"><div class="sa-panel__head"><h2 class="sa-panel__title">Customers</h2>';
    echo '<span class="sa-muted">' . esc_html((string) count($customers)) . ' shown</span></div>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr>';
    echo '<th>ID</th><th>Name</th><th>Email</th><th>Orders</th><th>Spent</th><th>Origin</th><th>Status</th><th>Registered</th><th></th>';
    echo '</tr></thead><tbody>';
    if (!$customers) {
        echo '<tr><td colspan="9" class="sa-muted">No customers match.</td></tr>';
    } else {
        foreach ($customers as $user) {
            $oid_count = function_exists('wc_get_customer_order_count') ? (int) wc_get_customer_order_count($user->ID) : 0;
            $spent = function_exists('wc_get_customer_total_spent') ? (float) wc_get_customer_total_spent($user->ID) : 0.0;
            $guest = get_user_meta($user->ID, '_sa_guest_checkout_account', true) ? 'guest' : 'register';
            $is_banned = sa_core_super_user_is_banned((int) $user->ID);
            echo '<tr>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=sa-super-customers&user=' . (int) $user->ID)) . '">' . esc_html((string) $user->ID) . '</a></td>';
            echo '<td>' . esc_html($user->display_name) . '</td>';
            echo '<td><a href="mailto:' . esc_attr($user->user_email) . '">' . esc_html($user->user_email) . '</a></td>';
            echo '<td>' . esc_html((string) $oid_count) . '</td>';
            echo '<td>' . wp_kses_post(function_exists('wc_price') ? wc_price($spent) : number_format($spent, 2)) . '</td>';
            echo '<td>' . esc_html($guest) . '</td>';
            echo '<td>' . ($is_banned ? '<strong style="color:#c0392b;">Banned</strong>' : 'Active') . '</td>';
            echo '<td>' . esc_html(mysql2date('Y-m-d', $user->user_registered)) . '</td>';
            echo '<td><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=sa-super-customers&user=' . (int) $user->ID)) . '">Open</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

function sa_core_super_render_payments(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $flash = sa_core_super_ops_flash();

    $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : ''; // phpcs:ignore
    $pay_method = isset($_GET['payment_method']) ? sanitize_key((string) $_GET['payment_method']) : ''; // phpcs:ignore
    $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : ''; // phpcs:ignore
    $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash((string) $_GET['date_from'])) : ''; // phpcs:ignore
    $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash((string) $_GET['date_to'])) : ''; // phpcs:ignore

    $whop = null;
    if (function_exists('WC') && WC()->payment_gateways()) {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $whop = $gateways['whop'] ?? null;
    }
    $whop_on = $whop && isset($whop->enabled) ? ($whop->enabled === 'yes') : false;
    $webhook = class_exists('Whop_Webhook') ? Whop_Webhook::webhook_url() : home_url('/?wc-api=whop_webhook');

    $gateways_list = [];
    if (function_exists('WC') && WC()->payment_gateways()) {
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gw) {
            $gateways_list[$id] = $gw->get_method_title() ?: $id;
        }
    }

    $args = [
        'limit'   => 30,
        'orderby' => 'date',
        'order'   => 'DESC',
    ];
    if ($status !== '') {
        $args['status'] = $status;
    } else {
        $args['status'] = ['pending', 'on-hold', 'processing', 'completed', 'failed', 'cancelled', 'refunded'];
    }
    if ($pay_method !== '') {
        $args['payment_method'] = $pay_method;
    }
    if ($q !== '') {
        $args['s'] = $q;
    }
    if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $args['date_created'] = $date_from . '...' . ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) ? $date_to : gmdate('Y-m-d'));
    }

    $orders = function_exists('wc_get_orders') ? wc_get_orders($args) : [];
    $last_link = get_transient('sa_super_last_pay_link_' . get_current_user_id());

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header(
        'Payments',
        'Whop status, create shareable /pay links, invoice + order pay URLs, filters. Guest checkout stays on.'
    );
    if ($flash['notice'] !== '') {
        echo '<div class="sa-inline-notice sa-inline-notice--' . esc_attr($flash['type']) . '">' . esc_html($flash['notice']) . '</div>';
    }

    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Whop Checkout</h2>';
    echo '<p>Status: <strong>' . ($whop_on ? 'Enabled' : 'Disabled / missing') . '</strong></p>';
    echo '<p class="sa-muted">Title: <code>' . esc_html($whop ? (string) $whop->get_title() : '—') . '</code></p>';
    echo '<p>Webhook:<br><code style="word-break:break-all;">' . esc_html($webhook) . '</code></p>';
    echo '<div class="sa-copy-row"><input type="text" readonly value="' . esc_attr($webhook) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy>Copy webhook</button></div>';
    echo '<p style="margin-top:12px;"><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=whop')) . '">Whop settings</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '">All gateways</a></p>';
    echo '</div>';

    echo '<div class="sa-panel"><h2 class="sa-panel__title">Create payment link</h2>';
    echo '<p class="sa-muted">Shareable open-amount <code>/pay/</code> URL, or an order checkout pay link when Order ID is set.</p>';
    echo '<form method="post" class="sa-form-grid">';
    wp_nonce_field('sa_super_payments_link');
    echo '<input type="hidden" name="page" value="sa-super-payments" />';
    echo '<label>Amount (USD) <input type="number" name="amount" step="0.01" min="0" placeholder="e.g. 49.00" /></label>';
    echo '<label>Order ID (optional) <input type="number" name="order_id" min="1" placeholder="uses order pay URL" /></label>';
    echo '<label>Email (optional) <input type="email" name="email" placeholder="prefill on /pay" /></label>';
    echo '<label>Note (optional) <input type="text" name="note" maxlength="120" placeholder="Invoice ref" /></label>';
    echo '<button type="submit" name="sa_create_pay_link" value="1" class="button button-primary">Generate link</button>';
    echo '</form>';
    if (is_array($last_link) && !empty($last_link['url'])) {
        echo '<div class="sa-inline-notice sa-inline-notice--ok" style="margin-top:12px;">';
        echo '<strong>' . esc_html((string) ($last_link['label'] ?? 'Link')) . '</strong>';
        echo '<div class="sa-copy-row" style="margin-top:8px;"><input id="sa-last-pay-link" type="text" readonly value="' . esc_attr((string) $last_link['url']) . '" onclick="this.select()" />';
        echo '<button type="button" class="button button-primary" data-sa-copy="#sa-last-pay-link">Copy</button>';
        echo ' <a class="button" href="' . esc_url((string) $last_link['url']) . '" target="_blank" rel="noopener">Open</a></div>';
        echo '</div>';
    }
    echo '<p class="sa-muted" style="margin-top:12px;">Base open-pay: <a href="' . esc_url(home_url('/pay/')) . '" target="_blank" rel="noopener">' . esc_html(home_url('/pay/')) . '</a></p>';
    echo '</div></div>';

    echo '<form method="get" class="sa-form-grid sa-ops-filters" style="margin:16px 0;">';
    echo '<input type="hidden" name="page" value="sa-super-payments" />';
    echo '<label>Status <select name="status"><option value="">All</option>';
    foreach (['pending', 'on-hold', 'processing', 'completed', 'failed', 'cancelled', 'refunded'] as $st) {
        echo '<option value="' . esc_attr($st) . '"' . selected($status, $st, false) . '>' . esc_html(function_exists('wc_get_order_status_name') ? wc_get_order_status_name($st) : $st) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Method <select name="payment_method"><option value="">Any</option>';
    foreach ($gateways_list as $gid => $gtitle) {
        echo '<option value="' . esc_attr($gid) . '"' . selected($pay_method, $gid, false) . '>' . esc_html($gtitle) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Search <input type="search" name="s" value="' . esc_attr($q) . '" placeholder="order # / email" /></label>';
    echo '<label>From <input type="date" name="date_from" value="' . esc_attr($date_from) . '" /></label>';
    echo '<label>To <input type="date" name="date_to" value="' . esc_attr($date_to) . '" /></label>';
    echo '<button class="button button-primary" type="submit">Filter</button>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-payments')) . '">Clear</a>';
    echo '</form>';

    echo '<div class="sa-panel"><h2 class="sa-panel__title">Orders — invoices &amp; pay links</h2>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr>';
    echo '<th>Order</th><th>Customer</th><th>Method</th><th>Total</th><th>Status</th><th>When</th><th>Invoice</th><th>Pay / actions</th>';
    echo '</tr></thead><tbody>';
    if (!$orders) {
        echo '<tr><td colspan="8" class="sa-muted">No orders match.</td></tr>';
    } else {
        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            $oid = (int) $order->get_id();
            $inv = function_exists('sa_core_invoice_url') ? sa_core_invoice_url($oid) : '';
            $pay = $order->get_checkout_payment_url();
            $st = $order->get_status();
            $needs_pay = in_array($st, ['pending', 'on-hold', 'failed'], true) || !$order->is_paid();
            $email = (string) $order->get_billing_email();
            echo '<tr>';
            echo '<td><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a></td>';
            echo '<td>' . esc_html($email ?: '—') . '</td>';
            echo '<td>' . esc_html($order->get_payment_method_title() ?: $order->get_payment_method() ?: '—') . '</td>';
            echo '<td>' . wp_kses_post($order->get_formatted_order_total()) . '</td>';
            echo '<td>' . esc_html(wc_get_order_status_name($st)) . '</td>';
            echo '<td>' . esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : '') . '</td>';
            echo '<td>';
            if ($inv !== '') {
                echo '<div class="sa-copy-row"><input type="text" readonly value="' . esc_attr($inv) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy>Copy</button>';
                echo ' <a class="button" href="' . esc_url($inv) . '" target="_blank" rel="noopener">Open</a></div>';
            } else {
                echo '—';
            }
            echo '</td><td>';
            if ($needs_pay && !in_array($st, ['cancelled', 'refunded', 'completed'], true)) {
                echo '<div class="sa-copy-row"><input type="text" readonly value="' . esc_attr($pay) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy>Copy pay</button></div>';
            }
            echo '<a class="button" href="' . esc_url($order->get_edit_order_url()) . '">Edit</a> ';
            if (in_array($st, ['processing', 'completed', 'on-hold'], true)) {
                echo '<a class="button" href="' . esc_url($order->get_edit_order_url() . '#woocommerce-order-items') . '">Refunds</a>';
            }
            echo '</td></tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}
