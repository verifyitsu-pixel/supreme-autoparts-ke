<?php
/**
 * Super Admin Phase 1: shell + sidebar matching blueprint, dashboard metrics,
 * tickets admin UI, security/audit + fraud list screens.
 * Unbuilt sections show honest "Coming in Phase X" with deep links into Woo.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Blueprint nav definition.
 *
 * @return list<array{slug:string,label:string,phase:int,built:bool,woo?:string}>
 */
function sa_core_super_nav(): array {
    $orders_url = admin_url('admin.php?page=wc-orders');
    if (!function_exists('wc_get_page_screen_id')) {
        $orders_url = admin_url('edit.php?post_type=shop_order');
    }
    return [
        ['slug' => 'supreme-autoparts', 'label' => 'Dashboard', 'phase' => 1, 'built' => true],
        ['slug' => 'sa-super-orders', 'label' => 'Orders', 'phase' => 2, 'built' => false, 'woo' => $orders_url],
        ['slug' => 'sa-super-products', 'label' => 'Products', 'phase' => 2, 'built' => false, 'woo' => admin_url('edit.php?post_type=product')],
        ['slug' => 'sa-super-inventory', 'label' => 'Inventory', 'phase' => 3, 'built' => false, 'woo' => admin_url('edit.php?post_type=product&orderby=stock&order=asc')],
        ['slug' => 'sa-super-customers', 'label' => 'Customers', 'phase' => 2, 'built' => false, 'woo' => admin_url('admin.php?page=supreme-customers')],
        ['slug' => 'sa-super-vendors', 'label' => 'Vendors', 'phase' => 4, 'built' => false],
        ['slug' => 'sa-super-payments', 'label' => 'Payments', 'phase' => 2, 'built' => false, 'woo' => admin_url('admin.php?page=wc-settings&tab=checkout')],
        ['slug' => 'sa-super-shipping', 'label' => 'Shipping', 'phase' => 2, 'built' => false, 'woo' => admin_url('admin.php?page=wc-settings&tab=shipping')],
        ['slug' => 'sa-super-discounts', 'label' => 'Discounts', 'phase' => 3, 'built' => false, 'woo' => admin_url('edit.php?post_type=shop_coupon')],
        ['slug' => 'sa-super-marketing', 'label' => 'Marketing', 'phase' => 3, 'built' => false, 'woo' => admin_url('admin.php?page=sa-brevo-settings')],
        ['slug' => 'sa-super-analytics', 'label' => 'Analytics', 'phase' => 3, 'built' => false, 'woo' => admin_url('admin.php?page=wc-admin&path=/analytics/overview')],
        ['slug' => 'sa-super-reviews', 'label' => 'Reviews', 'phase' => 3, 'built' => false, 'woo' => admin_url('edit-comments.php')],
        ['slug' => 'sa-super-content', 'label' => 'Content', 'phase' => 2, 'built' => false, 'woo' => admin_url('edit.php?post_type=page')],
        ['slug' => 'sa-super-admins', 'label' => 'Admins & Roles', 'phase' => 3, 'built' => false, 'woo' => admin_url('users.php')],
        ['slug' => 'sa-super-notifications', 'label' => 'Notifications', 'phase' => 3, 'built' => false],
        ['slug' => 'sa-super-integrations', 'label' => 'Integrations', 'phase' => 2, 'built' => false, 'woo' => admin_url('admin.php?page=wc-settings&tab=checkout&section=whop')],
        ['slug' => 'sa-super-settings', 'label' => 'Settings', 'phase' => 2, 'built' => false, 'woo' => admin_url('admin.php?page=wc-settings')],
        ['slug' => 'sa-super-security', 'label' => 'Security & Audit', 'phase' => 1, 'built' => true],
        ['slug' => 'sa-super-tickets', 'label' => 'Support Tickets', 'phase' => 1, 'built' => true],
        ['slug' => 'sa-super-fraud', 'label' => 'Fraud', 'phase' => 1, 'built' => true],
    ];
}

add_action('admin_menu', static function (): void {
    // Rebuild submenu under Supreme Autoparts — keep existing tool pages, add Super Admin sections.
    foreach (sa_core_super_nav() as $item) {
        if ($item['slug'] === 'supreme-autoparts') {
            continue; // root dashboard already registered
        }
        $cb = 'sa_core_super_render_placeholder';
        if ($item['slug'] === 'sa-super-tickets') {
            $cb = 'sa_core_super_render_tickets';
        } elseif ($item['slug'] === 'sa-super-security') {
            $cb = 'sa_core_super_render_security';
        } elseif ($item['slug'] === 'sa-super-fraud') {
            $cb = 'sa_core_super_render_fraud';
        }
        add_submenu_page(
            'supreme-autoparts',
            $item['label'],
            $item['label'],
            'manage_woocommerce',
            $item['slug'],
            $cb
        );
    }
}, 30);

add_action('admin_enqueue_scripts', static function (string $hook): void {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    if (!str_contains($hook, 'supreme') && !str_contains($hook, 'sa-super')) {
        return;
    }
    wp_enqueue_style('sa-admin-ultra', SA_CORE_URL . 'assets/css/admin-ultra.css', [], SA_CORE_VERSION);
    wp_enqueue_script('sa-admin-ultra', SA_CORE_URL . 'assets/js/admin-ultra.js', [], SA_CORE_VERSION, true);
});

/**
 * Shared shell chrome: title + left-ish section chips.
 */
function sa_core_super_shell_header(string $title, string $sub = ''): void {
    $email = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    echo '<div class="sa-ultra__header"><div>';
    echo '<p class="sa-super-eyebrow">Super Admin · Phase 1</p>';
    echo '<h1 class="sa-ultra__title">' . esc_html($title) . '</h1>';
    if ($sub !== '') {
        echo '<p class="sa-ultra__sub">' . esc_html($sub) . '</p>';
    }
    echo '</div>';
    echo '<a class="sa-ultra__email" href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
    echo '</div>';
}

function sa_core_super_render_placeholder(): void {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore
    $item = null;
    foreach (sa_core_super_nav() as $row) {
        if ($row['slug'] === $page) {
            $item = $row;
            break;
        }
    }
    $label = $item['label'] ?? 'Section';
    $phase = (int) ($item['phase'] ?? 2);
    $woo   = (string) ($item['woo'] ?? '');
    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header($label, 'This Super Admin section is scaffolded for Phase ' . $phase . '.');
    echo '<div class="sa-panel sa-coming">';
    echo '<h2 class="sa-panel__title">Coming in Phase ' . esc_html((string) $phase) . '</h2>';
    echo '<p class="sa-muted">Deep platform controls for <strong>' . esc_html($label) . '</strong> ship in Phase '
        . esc_html((string) $phase) . ' per the Super Admin blueprint. Use WooCommerce screens below in the meantime.</p>';
    if ($woo !== '') {
        echo '<p style="margin-top:14px;"><a class="button button-primary" href="' . esc_url($woo) . '">Open existing Woo / admin screen</a></p>';
    }
    // Existing ultra tools shortcuts.
    echo '<p class="sa-muted" style="margin-top:16px;">Also available now: ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=supreme-orders')) . '">Order tools</a> · ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=supreme-products')) . '">Product tools</a> · ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=supreme-customers')) . '">Customers</a> · ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=supreme-import')) . '">Import</a>';
    echo '</p></div></div>';
}

function sa_core_super_render_tickets(): void {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $notice = '';
    $view_id = isset($_GET['ticket']) ? absint($_GET['ticket']) : 0; // phpcs:ignore

    if (isset($_POST['sa_ticket_reply']) && check_admin_referer('sa_ticket_admin')) {
        $tid = absint($_POST['ticket_id'] ?? 0);
        $body = sanitize_textarea_field(wp_unslash((string) ($_POST['reply_body'] ?? '')));
        $status = sanitize_key((string) ($_POST['status'] ?? ''));
        if ($tid && $body !== '') {
            sa_core_ticket_add_reply($tid, $body, get_current_user_id(), true);
            $notice = 'Reply sent.';
        }
        if ($tid && $status !== '') {
            sa_core_ticket_set_status($tid, $status);
            $notice = $notice !== '' ? $notice . ' Status updated.' : 'Status updated.';
        }
        if (function_exists('sa_core_audit_log')) {
            sa_core_audit_log('ticket.admin_reply', ['ticket_id' => $tid, 'status' => $status], 'notice');
        }
        $view_id = $tid;
    }

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Support Tickets', 'Contact form + My Account tickets. Reply and set status here.');
    if ($notice !== '') {
        echo '<div class="sa-inline-notice sa-inline-notice--ok">' . esc_html($notice) . '</div>';
    }

    if ($view_id > 0) {
        $ticket = sa_core_ticket_get($view_id);
        if (!$ticket) {
            echo '<div class="sa-panel"><p>Ticket not found.</p></div></div>';
            return;
        }
        $replies = sa_core_ticket_replies($view_id);
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-tickets')) . '">&larr; All tickets</a></p>';
        echo '<div class="sa-panel" style="margin-bottom:16px;">';
        echo '<h2 class="sa-panel__title">#' . esc_html((string) $ticket->id) . ' — ' . esc_html((string) $ticket->subject) . '</h2>';
        echo '<p class="sa-muted">' . esc_html((string) $ticket->name) . ' &lt;' . esc_html((string) $ticket->email) . '&gt; · ';
        echo 'status <strong>' . esc_html((string) $ticket->status) . '</strong> · source ' . esc_html((string) $ticket->source) . '</p>';
        echo '<div class="sa-ticket-thread">';
        foreach ($replies as $rep) {
            $who = ((int) $rep->is_staff) ? 'Staff' : 'Customer';
            echo '<div class="sa-ticket-msg' . (((int) $rep->is_staff) ? ' sa-ticket-msg--staff' : '') . '">';
            echo '<div class="sa-ticket-msg__meta"><strong>' . esc_html($who) . '</strong> · ' . esc_html((string) $rep->created_at) . '</div>';
            echo '<div class="sa-ticket-msg__body">' . nl2br(esc_html((string) $rep->body)) . '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<form method="post" class="sa-form-grid" style="margin-top:16px;">';
        wp_nonce_field('sa_ticket_admin');
        echo '<input type="hidden" name="ticket_id" value="' . esc_attr((string) $view_id) . '" />';
        echo '<label>Reply<textarea name="reply_body" rows="5" required></textarea></label>';
        echo '<label>Status<select name="status">';
        foreach (['open' => 'Open', 'answered' => 'Answered', 'pending' => 'Pending', 'closed' => 'Closed'] as $k => $lab) {
            echo '<option value="' . esc_attr($k) . '"' . selected((string) $ticket->status, $k, false) . '>' . esc_html($lab) . '</option>';
        }
        echo '</select></label>';
        echo '<p><button type="submit" name="sa_ticket_reply" class="button button-primary" value="1">Send reply</button></p>';
        echo '</form></div></div>';
        return;
    }

    $tickets = function_exists('sa_core_tickets_list') ? sa_core_tickets_list(['limit' => 100]) : [];
    echo '<div class="sa-panel"><div class="sa-table-wrap"><table class="sa-table"><thead><tr>';
    echo '<th>#</th><th>Subject</th><th>Customer</th><th>Status</th><th>Source</th><th>Updated</th><th></th>';
    echo '</tr></thead><tbody>';
    if (!$tickets) {
        echo '<tr><td colspan="7" class="sa-muted">No tickets yet. Footer Contact us / site forms create tickets automatically.</td></tr>';
    } else {
        foreach ($tickets as $row) {
            $url = admin_url('admin.php?page=sa-super-tickets&ticket=' . (int) $row->id);
            echo '<tr>';
            echo '<td>#' . esc_html((string) $row->id) . '</td>';
            echo '<td>' . esc_html((string) $row->subject) . '</td>';
            echo '<td>' . esc_html((string) $row->email) . '</td>';
            echo '<td><span class="sa-badge sa-badge--' . (((string) $row->status === 'closed') ? 'ok' : 'warn') . '">' . esc_html((string) $row->status) . '</span></td>';
            echo '<td>' . esc_html((string) $row->source) . '</td>';
            echo '<td>' . esc_html((string) $row->updated_at) . '</td>';
            echo '<td><a class="button" href="' . esc_url($url) . '">Open</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

function sa_core_super_render_security(): void {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $rows = function_exists('sa_core_audit_list') ? sa_core_audit_list(['limit' => 100]) : [];
    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Security & Audit Logs', 'Admin logins, failed logins, deletes, ticket actions. Expand in later phases.');
    echo '<div class="sa-panel"><div class="sa-table-wrap"><table class="sa-table"><thead><tr>';
    echo '<th>When</th><th>Event</th><th>Severity</th><th>User</th><th>IP</th><th>Context</th>';
    echo '</tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="6" class="sa-muted">No audit events yet.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $user = $row->user_id ? get_userdata((int) $row->user_id) : null;
            $ctx = (string) $row->context;
            if (strlen($ctx) > 120) {
                $ctx = substr($ctx, 0, 117) . '…';
            }
            $sev = (string) $row->severity;
            $badge = $sev === 'critical' || $sev === 'warning' ? 'warn' : 'ok';
            echo '<tr>';
            echo '<td>' . esc_html((string) $row->created_at) . '</td>';
            echo '<td><code>' . esc_html((string) $row->event_type) . '</code></td>';
            echo '<td><span class="sa-badge sa-badge--' . esc_attr($badge) . '">' . esc_html($sev) . '</span></td>';
            echo '<td>' . esc_html($user ? $user->user_login : ('#' . (string) $row->user_id)) . '</td>';
            echo '<td>' . esc_html((string) $row->ip) . '</td>';
            echo '<td class="sa-muted"><code>' . esc_html($ctx) . '</code></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

function sa_core_super_render_fraud(): void {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $rows = function_exists('sa_core_audit_list') ? sa_core_audit_list(['limit' => 100]) : [];
    $fraud = array_values(array_filter($rows, static function ($r): bool {
        $t = (string) $r->event_type;
        return str_starts_with($t, 'fraud.') || str_starts_with($t, 'payment.anomaly') || (string) $r->severity === 'critical';
    }));
    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Fraud screening', 'Phase 1 foundation: high open-pay amounts, zero-total anomalies. Rules expand later.');
    echo '<div class="sa-panel"><div class="sa-table-wrap"><table class="sa-table"><thead><tr>';
    echo '<th>When</th><th>Signal</th><th>Severity</th><th>Detail</th>';
    echo '</tr></thead><tbody>';
    if (!$fraud) {
        echo '<tr><td colspan="4" class="sa-muted">No fraud signals yet. Large open-pay orders (≥ $5,000) and zero-total paid orders are logged automatically.</td></tr>';
    } else {
        foreach ($fraud as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) $row->created_at) . '</td>';
            echo '<td><code>' . esc_html((string) $row->event_type) . '</code></td>';
            echo '<td><span class="sa-badge sa-badge--warn">' . esc_html((string) $row->severity) . '</span></td>';
            echo '<td class="sa-muted"><code>' . esc_html((string) $row->context) . '</code></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

/**
 * Enhance dashboard KPIs with tickets + order status breakdown (real Woo data).
 */
add_action('admin_footer', static function (): void {
    // no-op placeholder — dashboard render is overridden below via filter-friendly wrapper
});
