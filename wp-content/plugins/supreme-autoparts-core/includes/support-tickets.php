<?php
/**
 * Support tickets (Phase 1): Contact/email forms → ticket;
 * My Account list/reply; Super Admin manage.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_TICKETS_DB_VER = '1';

/**
 * Install tickets + replies tables.
 */
function sa_core_tickets_install(): void {
    global $wpdb;
    $ver = get_option('sa_tickets_db_ver');
    if ($ver === SA_TICKETS_DB_VER) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $t = $wpdb->prefix . 'sa_tickets';
    $r = $wpdb->prefix . 'sa_ticket_replies';
    dbDelta("CREATE TABLE {$t} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        email varchar(190) NOT NULL DEFAULT '',
        name varchar(190) NOT NULL DEFAULT '',
        subject varchar(255) NOT NULL DEFAULT '',
        status varchar(32) NOT NULL DEFAULT 'open',
        source varchar(64) NOT NULL DEFAULT 'contact',
        priority varchar(16) NOT NULL DEFAULT 'normal',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY email (email),
        KEY status (status),
        KEY updated_at (updated_at)
    ) {$charset};");
    dbDelta("CREATE TABLE {$r} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        ticket_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        is_staff tinyint(1) NOT NULL DEFAULT 0,
        body longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY ticket_id (ticket_id)
    ) {$charset};");
    update_option('sa_tickets_db_ver', SA_TICKETS_DB_VER, false);
}

add_action('plugins_loaded', 'sa_core_tickets_install', 5);

/**
 * @param array{email:string,name?:string,subject?:string,body:string,source?:string,user_id?:int} $args
 * @return int ticket id (0 on failure)
 */
function sa_core_ticket_create(array $args): int {
    global $wpdb;
    sa_core_tickets_install();
    $email = sanitize_email((string) ($args['email'] ?? ''));
    $body  = trim((string) ($args['body'] ?? ''));
    if ($email === '' || !is_email($email) || $body === '') {
        return 0;
    }
    $name    = sanitize_text_field((string) ($args['name'] ?? ''));
    $subject = sanitize_text_field((string) ($args['subject'] ?? ''));
    if ($subject === '') {
        $subject = wp_trim_words($body, 8, '…');
    }
    $source  = sanitize_key((string) ($args['source'] ?? 'contact'));
    $user_id = absint($args['user_id'] ?? 0);
    if (!$user_id) {
        $u = get_user_by('email', $email);
        $user_id = $u ? (int) $u->ID : 0;
    }
    $now = current_time('mysql');
    $t = $wpdb->prefix . 'sa_tickets';
    $ok = $wpdb->insert(
        $t,
        [
            'user_id'    => $user_id,
            'email'      => $email,
            'name'       => $name,
            'subject'    => $subject,
            'status'     => 'open',
            'source'     => $source,
            'priority'   => 'normal',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );
    if (!$ok) {
        return 0;
    }
    $tid = (int) $wpdb->insert_id;
    sa_core_ticket_add_reply($tid, $body, $user_id, false);
    if (function_exists('sa_core_audit_log')) {
        sa_core_audit_log('ticket.created', [
            'ticket_id' => $tid,
            'email'     => $email,
            'source'    => $source,
        ]);
    }
    return $tid;
}

/**
 * @return int reply id
 */
function sa_core_ticket_add_reply(int $ticket_id, string $body, int $user_id = 0, bool $is_staff = false): int {
    global $wpdb;
    $body = trim($body);
    if ($ticket_id < 1 || $body === '') {
        return 0;
    }
    $r = $wpdb->prefix . 'sa_ticket_replies';
    $now = current_time('mysql');
    $ok = $wpdb->insert(
        $r,
        [
            'ticket_id'  => $ticket_id,
            'user_id'    => $user_id,
            'is_staff'   => $is_staff ? 1 : 0,
            'body'       => $body,
            'created_at' => $now,
        ],
        ['%d', '%d', '%d', '%s', '%s']
    );
    if (!$ok) {
        return 0;
    }
    $wpdb->update(
        $wpdb->prefix . 'sa_tickets',
        [
            'updated_at' => $now,
            'status'     => $is_staff ? 'answered' : 'open',
        ],
        ['id' => $ticket_id],
        ['%s', '%s'],
        ['%d']
    );
    return (int) $wpdb->insert_id;
}

/**
 * @return object|null
 */
function sa_core_ticket_get(int $id) {
    global $wpdb;
    if ($id < 1) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . $wpdb->prefix . 'sa_tickets WHERE id = %d',
        $id
    ));
    return $row ?: null;
}

/**
 * @return list<object>
 */
function sa_core_ticket_replies(int $ticket_id): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . $wpdb->prefix . 'sa_ticket_replies WHERE ticket_id = %d ORDER BY id ASC',
        $ticket_id
    ));
    return is_array($rows) ? $rows : [];
}

/**
 * @param array{status?:string,email?:string,user_id?:int,limit?:int,offset?:int} $args
 * @return list<object>
 */
function sa_core_tickets_list(array $args = []): array {
    global $wpdb;
    $t = $wpdb->prefix . 'sa_tickets';
    $where = ['1=1'];
    $params = [];
    if (!empty($args['status'])) {
        $where[] = 'status = %s';
        $params[] = sanitize_key((string) $args['status']);
    }
    if (!empty($args['email'])) {
        $where[] = 'email = %s';
        $params[] = sanitize_email((string) $args['email']);
    }
    if (!empty($args['user_id'])) {
        $where[] = 'user_id = %d';
        $params[] = absint($args['user_id']);
    }
    $limit  = max(1, min(200, absint($args['limit'] ?? 50)));
    $offset = max(0, absint($args['offset'] ?? 0));
    $sql = 'SELECT * FROM ' . $t . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';
    $params[] = $limit;
    $params[] = $offset;
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
    return is_array($rows) ? $rows : [];
}

function sa_core_ticket_set_status(int $id, string $status): bool {
    global $wpdb;
    $allowed = ['open', 'answered', 'pending', 'closed'];
    $status = sanitize_key($status);
    if (!in_array($status, $allowed, true) || $id < 1) {
        return false;
    }
    $ok = $wpdb->update(
        $wpdb->prefix . 'sa_tickets',
        ['status' => $status, 'updated_at' => current_time('mysql')],
        ['id' => $id],
        ['%s', '%s'],
        ['%d']
    );
    return $ok !== false;
}

/**
 * Customer can access ticket if owner by user_id or email.
 */
function sa_core_ticket_user_can_access(object $ticket, ?WP_User $user = null): bool {
    if (current_user_can('manage_woocommerce')) {
        return true;
    }
    $user = $user ?: wp_get_current_user();
    if (!$user || !$user->ID) {
        return false;
    }
    if ((int) $ticket->user_id === (int) $user->ID) {
        return true;
    }
    return strcasecmp((string) $ticket->email, (string) $user->user_email) === 0;
}

/**
 * Hook: after footer contact email succeeds, also create a ticket.
 */
function sa_core_ticket_from_contact(string $name, string $email, string $phone, string $message, string $page_url = ''): int {
    $body = $message;
    if ($phone !== '') {
        $body .= "\n\nPhone: " . $phone;
    }
    if ($page_url !== '') {
        $body .= "\nPage: " . $page_url;
    }
    return sa_core_ticket_create([
        'email'   => $email,
        'name'    => $name,
        'subject' => 'Website contact — ' . $name,
        'body'    => $body,
        'source'  => 'contact_form',
        'user_id' => get_current_user_id(),
    ]);
}

/**
 * Open ticket count for dashboard KPI.
 */
function sa_core_tickets_open_count(): int {
    global $wpdb;
    sa_core_tickets_install();
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}sa_tickets WHERE status IN ('open','pending','answered')"
    );
}
