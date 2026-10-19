<?php
/**
 * Subscriber store: consent states, lookup, stats, import of existing customers.
 *
 * Statuses:
 *  - transactional_only: known customer (account/order/import) who never opted into marketing.
 *  - unconfirmed: asked to join, double opt-in email sent, not yet confirmed.
 *  - active: confirmed marketing consent. Only status that receives marketing flows/campaigns.
 *  - paused: active but paused_until in the future (acts as inactive for marketing).
 *  - unsubscribed / bounced / complained: never emailed again except pure transactional site mail.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Subscribers
{
    public static function get_by_email(string $email): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . SM_DB::table('subscribers') . ' WHERE email = %s',
            strtolower(trim($email))
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . SM_DB::table('subscribers') . ' WHERE id = %d',
            $id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** Create or update a subscriber row; returns the row. Never downgrades consent. */
    public static function ensure(string $email, array $args = []): ?array
    {
        global $wpdb;
        $email = strtolower(trim($email));
        if (!is_email($email)) {
            return null;
        }
        $now = sm_now();
        $existing = self::get_by_email($email);
        if ($existing) {
            $updates = ['updated_at' => $now];
            foreach (['user_id', 'first_name', 'last_name', 'region'] as $f) {
                if (isset($args[$f]) && $args[$f] !== '' && $args[$f] !== null) {
                    $updates[$f] = $args[$f];
                }
            }
            // Upgrade transactional_only -> unconfirmed/active happens only via consent flows.
            $wpdb->update(SM_DB::table('subscribers'), $updates, ['id' => (int) $existing['id']]);
            return self::get((int) $existing['id']);
        }
        $wpdb->insert(SM_DB::table('subscribers'), [
            'email'      => $email,
            'user_id'    => isset($args['user_id']) ? (int) $args['user_id'] : null,
            'first_name' => sanitize_text_field((string) ($args['first_name'] ?? '')),
            'last_name'  => sanitize_text_field((string) ($args['last_name'] ?? '')),
            'status'     => in_array(($args['status'] ?? ''), ['transactional_only', 'unconfirmed', 'active'], true)
                ? $args['status'] : 'transactional_only',
            'source'     => sanitize_key((string) ($args['source'] ?? 'unknown')),
            'region'     => strtoupper(substr(sanitize_text_field((string) ($args['region'] ?? '')), 0, 2)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        return $id > 0 ? self::get($id) : null;
    }

    /** Record explicit marketing consent (double opt-in complete or checked box). */
    public static function activate(string $email, string $consent_source): void
    {
        global $wpdb;
        $sub = self::ensure($email);
        if (!$sub) {
            return;
        }
        if (in_array($sub['status'], ['unsubscribed', 'bounced', 'complained'], true)) {
            // A fresh explicit opt-in re-activates (e.g. they re-joined on purpose).
        }
        $wpdb->update(SM_DB::table('subscribers'), [
            'status'         => 'active',
            'consented_at'   => sm_now(),
            'consent_source' => substr($consent_source, 0, 60),
            'consent_ip'     => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'paused_until'   => null,
            'updated_at'     => sm_now(),
        ], ['id' => (int) $sub['id']]);
    }

    public static function mark_unconfirmed(string $email): void
    {
        global $wpdb;
        $sub = self::get_by_email($email);
        if (!$sub || $sub['status'] !== 'active') {
            $wpdb->update(SM_DB::table('subscribers'), [
                'status'     => 'unconfirmed',
                'updated_at' => sm_now(),
            ], ['email' => strtolower(trim($email))]);
        }
    }

    public static function unsubscribe(string $email, string $reason = 'unsubscribed'): void
    {
        global $wpdb;
        $wpdb->update(SM_DB::table('subscribers'), [
            'status'     => in_array($reason, ['unsubscribed', 'bounced', 'complained'], true) ? $reason : 'unsubscribed',
            'updated_at' => sm_now(),
        ], ['email' => strtolower(trim($email))]);
        SM_Queue::cancel_pending_for_email($email, 'subscriber_exited');
    }

    public static function pause(string $email, int $days): void
    {
        global $wpdb;
        $wpdb->update(SM_DB::table('subscribers'), [
            'paused_until' => sm_dt(time() + $days * DAY_IN_SECONDS),
            'updated_at'   => sm_now(),
        ], ['email' => strtolower(trim($email)), 'status' => 'active']);
    }

    /** True when this subscriber may receive MARKETING mail right now. */
    public static function is_marketable(?array $sub): bool
    {
        if (!$sub || $sub['status'] !== 'active') {
            return false;
        }
        if (!empty($sub['paused_until']) && strtotime((string) $sub['paused_until']) > sm_ts()) {
            return false;
        }
        return true;
    }

    /** True when this address may receive service-level flow mail (cart, post-purchase). */
    public static function is_contactable(?array $sub): bool
    {
        if (!$sub) {
            return true; // guest with a live cart/checkout: soft opt-in via their own action
        }
        return !in_array($sub['status'], ['unsubscribed', 'bounced', 'complained'], true);
    }

    /** Refresh order stats from WooCommerce (used by segments and win-back). */
    public static function refresh_order_stats(string $email): void
    {
        global $wpdb;
        if (!function_exists('wc_get_orders')) {
            return;
        }
        $orders = wc_get_orders([
            'billing_email' => $email,
            'status'        => ['wc-processing', 'wc-completed', 'wc-on-hold'],
            'limit'         => -1,
            'return'        => 'objects',
        ]);
        $count = 0;
        $total = 0.0;
        $last = null;
        foreach ($orders as $o) {
            $count++;
            $total += (float) $o->get_total();
            $d = $o->get_date_paid() ?: $o->get_date_created();
            if ($d && ($last === null || $d->getTimestamp() > $last)) {
                $last = $d->getTimestamp();
            }
        }
        $wpdb->update(SM_DB::table('subscribers'), [
            'orders_count'  => $count,
            'total_spent'   => $total,
            'last_order_at' => $last ? sm_dt($last) : null,
            'updated_at'    => sm_now(),
        ], ['email' => strtolower(trim($email))]);
    }

    /** One-time import of existing WooCommerce customers as transactional_only (never marketed until opt-in). */
    public static function import_existing_customers(): void
    {
        if (!function_exists('wc_get_orders')) {
            return;
        }
        if (get_option('sm_customers_imported')) {
            return;
        }
        $users = get_users(['role' => 'customer', 'fields' => ['ID', 'user_email', 'display_name'], 'number' => 2000]);
        foreach ($users as $u) {
            $first = (string) get_user_meta($u->ID, 'first_name', true);
            $last  = (string) get_user_meta($u->ID, 'last_name', true);
            $sub = self::ensure($u->user_email, [
                'user_id'    => (int) $u->ID,
                'first_name' => $first,
                'last_name'  => $last,
                'status'     => 'transactional_only',
                'source'     => 'customer_import',
            ]);
            if ($sub) {
                self::refresh_order_stats($u->user_email);
            }
        }
        update_option('sm_customers_imported', 1, false);
    }

    public static function counts(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . SM_DB::table('subscribers') . ' GROUP BY status', ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
