<?php
/**
 * Security & audit log + fraud screening stubs (Phase 1 foundations).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_AUDIT_DB_VER = '1';

function sa_core_audit_install(): void {
    global $wpdb;
    if (get_option('sa_audit_db_ver') === SA_AUDIT_DB_VER) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $t = $wpdb->prefix . 'sa_audit_log';
    dbDelta("CREATE TABLE {$t} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_type varchar(64) NOT NULL DEFAULT '',
        severity varchar(16) NOT NULL DEFAULT 'info',
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        ip varchar(64) NOT NULL DEFAULT '',
        context longtext NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY event_type (event_type),
        KEY severity (severity),
        KEY created_at (created_at),
        KEY user_id (user_id)
    ) {$charset};");
    update_option('sa_audit_db_ver', SA_AUDIT_DB_VER, false);
}

add_action('plugins_loaded', 'sa_core_audit_install', 5);

/**
 * @param array<string,mixed> $context
 */
function sa_core_audit_log(string $event_type, array $context = [], string $severity = 'info'): void {
    global $wpdb;
    sa_core_audit_install();
    $event_type = sanitize_key($event_type);
    if ($event_type === '') {
        return;
    }
    $allowed = ['info', 'notice', 'warning', 'critical'];
    if (!in_array($severity, $allowed, true)) {
        $severity = 'info';
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '';
    $wpdb->insert(
        $wpdb->prefix . 'sa_audit_log',
        [
            'event_type' => $event_type,
            'severity'   => $severity,
            'user_id'    => get_current_user_id(),
            'ip'         => $ip,
            'context'    => wp_json_encode($context),
            'created_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%d', '%s', '%s', '%s']
    );
}

/**
 * @param array{event_type?:string,severity?:string,limit?:int,offset?:int} $args
 * @return list<object>
 */
function sa_core_audit_list(array $args = []): array {
    global $wpdb;
    sa_core_audit_install();
    $t = $wpdb->prefix . 'sa_audit_log';
    $where = ['1=1'];
    $params = [];
    if (!empty($args['event_type'])) {
        $where[] = 'event_type = %s';
        $params[] = sanitize_key((string) $args['event_type']);
    }
    if (!empty($args['severity'])) {
        $where[] = 'severity = %s';
        $params[] = sanitize_key((string) $args['severity']);
    }
    $limit  = max(1, min(200, absint($args['limit'] ?? 50)));
    $offset = max(0, absint($args['offset'] ?? 0));
    $sql = 'SELECT * FROM ' . $t . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY id DESC LIMIT %d OFFSET %d';
    $params[] = $limit;
    $params[] = $offset;
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
    return is_array($rows) ? $rows : [];
}

/** Log successful / failed admin logins. */
add_action('wp_login', static function (string $user_login, WP_User $user): void {
    $roles = (array) $user->roles;
    $is_staff = array_intersect($roles, ['administrator', 'shop_manager', 'editor']);
    if ($is_staff) {
        sa_core_audit_log('auth.login', [
            'user_login' => $user_login,
            'user_id'    => (int) $user->ID,
            'roles'      => $roles,
        ], 'notice');
    }
}, 10, 2);

add_action('wp_login_failed', static function (string $username): void {
    sa_core_audit_log('auth.login_failed', ['username' => $username], 'warning');
});

/** Stub: flag payment amount anomalies when Woo order total vs meta diverge. */
add_action('woocommerce_order_status_changed', static function (int $order_id, string $from, string $to): void {
    if (!in_array($to, ['processing', 'completed'], true)) {
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    $total = (float) $order->get_total();
    if ($total <= 0) {
        sa_core_audit_log('payment.anomaly', [
            'order_id' => $order_id,
            'reason'   => 'zero_or_negative_total',
            'total'    => $total,
            'status'   => $to,
        ], 'warning');
    }
    // Large open-pay spikes (stub threshold).
    if ($order->get_meta('_sa_open_pay') === '1' && $total >= 5000) {
        sa_core_audit_log('fraud.screen', [
            'order_id' => $order_id,
            'reason'   => 'high_open_pay_amount',
            'total'    => $total,
        ], 'warning');
    }
}, 20, 3);

/** Log key admin option / product deletes lightly. */
add_action('deleted_post', static function (int $post_id): void {
    $type = get_post_type($post_id);
    if (in_array($type, ['product', 'shop_order', 'page'], true)) {
        sa_core_audit_log('admin.delete_post', [
            'post_id'   => $post_id,
            'post_type' => $type,
        ], 'notice');
    }
});
