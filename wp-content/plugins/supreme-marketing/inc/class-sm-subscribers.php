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

    /**
     * Record marketing consent the owner received OUTSIDE the website
     * (in person, phone, WhatsApp, ...). Directly marks the subscriber active:
     * sends no confirmation link and starts no flow. Never overrides
     * bounce/complaint suppressions. Every call is audit-logged to the events table.
     *
     * @return string 'ok' | 'invalid' | 'suppressed'
     */
    public static function record_external_consent(string $email, string $source_detail, string $consent_date, int $admin_id): string
    {
        global $wpdb;
        $email = strtolower(trim($email));
        if (!is_email($email)) {
            return 'invalid';
        }
        $ts = strtotime($consent_date);
        $today = gmdate('Y-m-d');
        $date = ($ts && gmdate('Y-m-d', $ts) <= $today) ? gmdate('Y-m-d', $ts) : $today;
        $detail = substr(trim($source_detail), 0, 50);
        if ($detail === '') {
            $detail = 'external';
        }
        $sub = self::get_by_email($email);
        if ($sub && in_array($sub['status'], ['bounced', 'complained'], true)) {
            return 'suppressed';
        }
        if (!$sub) {
            $sub = self::ensure($email, ['source' => 'owner_recorded']);
            if (!$sub) {
                return 'invalid';
            }
        }
        $prior = (string) ($sub['status'] ?? '');
        if ($prior !== 'active') {
            $wpdb->update(SM_DB::table('subscribers'), [
                'status'         => 'active',
                'consented_at'   => $date . ' 00:00:00',
                'consent_source' => 'owner: ' . $detail,
                'consent_ip'     => '',
                'paused_until'   => null,
                'updated_at'     => sm_now(),
            ], ['id' => (int) $sub['id']]);
        } else {
            $wpdb->update(SM_DB::table('subscribers'), [
                'consented_at'   => $date . ' 00:00:00',
                'consent_source' => 'owner: ' . $detail,
                'updated_at'     => sm_now(),
            ], ['id' => (int) $sub['id']]);
        }
        $wpdb->insert(SM_DB::table('events'), [
            'email'         => $email,
            'subscriber_id' => (int) $sub['id'],
            'type'          => 'consent_recorded',
            'payload'       => wp_json_encode([
                'source_detail' => $detail,
                'consent_date'  => $date,
                'admin_id'      => $admin_id,
                'prior_status'  => $prior,
            ]),
            'created_at'    => sm_now(),
        ]);
        return 'ok';
    }

    /** Proven gateway-imported people only. Local test accounts sharing customer_import
     * and missing-email placeholders are not evidence of original-store customers.
     */
    public static function imported_consent_candidates(): array
    {
        global $wpdb;
        $t = SM_DB::table('subscribers');
        $e = SM_DB::table('events');
        $rows = $wpdb->get_results("SELECT s.id, s.email FROM $t s WHERE s.source = 'customer_import' AND s.status = 'transactional_only' AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} m WHERE m.user_id = s.user_id AND m.meta_key = '_sa_imported_gateway_customer' AND m.meta_value = '1') AND NOT EXISTS (SELECT 1 FROM $e e WHERE e.email = s.email AND e.type IN ('consent_revoked_admin','unsubscribe','unsubscribed','complaint','complained','bounce','bounced')) ORDER BY s.id ASC", ARRAY_A);
        return array_values(array_filter((array) $rows, static function ($row) {
            $email = strtolower((string) ($row['email'] ?? ''));
            $domain = substr(strrchr($email, '@') ?: '', 1);
            return is_email($email) && !in_array($domain, ['example.com', 'example.org', 'example.net', 'example.invalid'], true)
                && !str_ends_with($domain, '.invalid') && !str_starts_with($email, 'imported+');
        }));
    }

    /** Restore import-lost consent only on the owner's attestation of prior agreement.
     * The original opt-in date is unknown. consented_at records this restoration,
     * while the audit event preserves that distinction and the confirmation reference.
     * Does not enqueue or send mail. Later withdrawals always win.
     */
    public static function restore_imported_consent(string $reference, int $admin_id): int
    {
        global $wpdb;
        if (trim($reference) === '') {
            return 0;
        }
        $t = SM_DB::table('subscribers');
        $e = SM_DB::table('events');
        $rows = self::imported_consent_candidates();
        $n = 0;
        foreach ((array) $rows as $row) {
            $updated = $wpdb->update($t, [
                'status' => 'active', 'consented_at' => sm_now(),
                'consent_source' => 'owner_attested_prior_store', 'updated_at' => sm_now(),
            ], ['id' => (int) $row['id'], 'source' => 'customer_import', 'status' => 'transactional_only']);
            if ($updated !== 1) {
                continue;
            }
            $wpdb->insert($e, [
                'email' => $row['email'], 'subscriber_id' => (int) $row['id'],
                'type' => 'consent_restored_import', 'created_at' => sm_now(),
                'payload' => wp_json_encode([
                    'confirmation_reference' => $reference, 'admin_id' => $admin_id,
                    'prior_status' => 'transactional_only', 'original_optin_at' => null,
                    'basis' => 'Owner confirms prior store marketing agreement was lost in import; timestamp is restoration receipt, not original agreement.',
                ]),
            ]);
            $n++;
        }
        return $n;
    }

    /** Admin revokes marketing consent from the dashboard; audit-logged. */
    public static function admin_revoke_consent(int $id, int $admin_id): bool
    {
        global $wpdb;
        $sub = self::get($id);
        if (!$sub) {
            return false;
        }
        self::unsubscribe((string) $sub['email'], 'unsubscribed');
        $wpdb->insert(SM_DB::table('events'), [
            'email'         => (string) $sub['email'],
            'subscriber_id' => (int) $sub['id'],
            'type'          => 'consent_revoked_admin',
            'payload'       => wp_json_encode([
                'admin_id'     => $admin_id,
                'prior_status' => (string) $sub['status'],
            ]),
            'created_at'    => sm_now(),
        ]);
        return true;
    }
}
