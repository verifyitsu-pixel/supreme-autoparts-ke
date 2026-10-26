<?php
/**
 * Queue: scheduled emails. Cron processing every 5 minutes plus daily scans
 * (win-back detection, reorder checks, subscriber stat refresh).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Queue
{
    public static function init(): void
    {
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action('sm_process_queue', [self::class, 'process']);
        add_action('sm_daily_scan', [self::class, 'daily_scan']);
        if (!wp_next_scheduled('sm_process_queue')) {
            wp_schedule_event(time() + 120, 'sm_five_min', 'sm_process_queue');
        }
        if (!wp_next_scheduled('sm_daily_scan')) {
            wp_schedule_event(time() + 300, 'daily', 'sm_daily_scan');
        }
    }

    public static function schedule_events(): void
    {
        if (!wp_next_scheduled('sm_process_queue')) {
            wp_schedule_event(time() + 120, 'sm_five_min', 'sm_process_queue');
        }
        if (!wp_next_scheduled('sm_daily_scan')) {
            wp_schedule_event(time() + 300, 'daily', 'sm_daily_scan');
        }
    }

    public static function unschedule_events(): void
    {
        wp_clear_scheduled_hook('sm_process_queue');
        wp_clear_scheduled_hook('sm_daily_scan');
    }

    public static function schedules(array $schedules): array
    {
        $schedules['sm_five_min'] = ['interval' => 300, 'display' => 'Every five minutes (Supreme Marketing)'];
        return $schedules;
    }

    /** Insert a queue row; false when the dedupe key already exists. */
    public static function add(array $row): bool
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . SM_DB::table('queue') . ' WHERE dedupe_key = %s',
            (string) $row['dedupe_key']
        ));
        if ($exists) {
            return false;
        }
        $ok = $wpdb->insert(SM_DB::table('queue'), [
            'subscriber_id'      => $row['subscriber_id'] ?? null,
            'email'              => strtolower((string) $row['email']),
            'flow'               => (string) ($row['flow'] ?? ''),
            'step'               => (string) ($row['step'] ?? ''),
            'template'           => (string) $row['template'],
            'campaign_id'        => $row['campaign_id'] ?? null,
            'payload'            => wp_json_encode($row['payload'] ?? []),
            'send_at'            => (string) $row['send_at'],
            'status'             => 'pending',
            'requires_approval'  => !empty($row['requires_approval']) ? 1 : 0,
            'dedupe_key'         => (string) $row['dedupe_key'],
            'created_at'         => sm_now(),
        ]);
        return $ok !== false;
    }

    public static function cancel_pending_for_email(string $email, string $reason = ''): int
    {
        global $wpdb;
        sm_release_email_products($email);
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE " . SM_DB::table('queue') . " SET status = 'cancelled', last_error = %s WHERE email = %s AND status = 'pending'",
            $reason,
            strtolower($email)
        ));
    }

    /** Cancel abandonment flows when an order arrives. */
    public static function cancel_abandonment_for_email(string $email): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . SM_DB::table('queue') . " SET status = 'cancelled', last_error = 'order_placed' WHERE email = %s AND status = 'pending' AND flow IN ('abandoned_cart','abandoned_checkout','browse_abandonment')",
            strtolower($email)
        ));
    }

    /** Process due queue rows (called by WP-Cron every 5 minutes). */
    public static function process(): void
    {
        SM_Welcome::backfill_order_norms(); // small batch per cron run; never fatals (guarded internally)
        if (!sm_master_on()) {
            return; // owner switch off: everything waits, nothing is lost
        }
        global $wpdb;
        // Staleness guard: mail queued long ago (e.g. while the master switch was off)
        // is cancelled instead of landing out of context when sending resumes.
        $wpdb->query($wpdb->prepare(
            "UPDATE " . SM_DB::table('queue') . " SET status = 'cancelled', last_error = 'stale'
             WHERE status = 'pending' AND (
                   (flow IN ('abandoned_cart','abandoned_checkout','browse_abandonment') AND send_at < DATE_SUB(%s, INTERVAL 5 DAY))
                OR (flow = 'consent' AND send_at < DATE_SUB(%s, INTERVAL 3 DAY))
                OR (send_at < DATE_SUB(%s, INTERVAL 21 DAY))
             )",
            sm_now(), sm_now(), sm_now()
        ));
        $batch = max(1, (int) sm_setting('send_batch'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . SM_DB::table('queue') . "
             WHERE status = 'pending' AND send_at <= %s
               AND (requires_approval = 0 OR approved_at IS NOT NULL)
             ORDER BY send_at ASC LIMIT %d",
            sm_now(),
            $batch
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            SM_Sender::send_queued($row);
        }
    }

    /** Daily: win-back detection, service reminders, stale stat refresh. */
    public static function daily_scan(): void
    {
        global $wpdb;
        SM_Segments::refresh_all_stats();
        self::service_reminder_scan();
        self::transient_gc();
        if (!SM_Flows::enabled('winback')) {
            return;
        }
        $t = SM_DB::table('subscribers');
        $w1 = (int) sm_setting('winback_1_days');
        $w2 = (int) sm_setting('winback_2_days');
        $subs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE status = 'active'
               AND orders_count > 0
               AND last_order_at IS NOT NULL
               AND last_order_at < DATE_SUB(%s, INTERVAL %d DAY)
             LIMIT 500",
            sm_now(),
            $w1
        ), ARRAY_A);
        foreach ((array) $subs as $sub) {
            $last = strtotime((string) $sub['last_order_at']);
            $age = sm_ts() - $last;
            if ($age >= $w2 * DAY_IN_SECONDS) {
                // Step 2 only, once per lapse episode (scope = last order date).
                SM_Flows::start($sub, 'winback', 'lapse|' . gmdate('Y-m-d', $last), [], ['winback_2']);
            } elseif ($age >= $w1 * DAY_IN_SECONDS) {
                // Step 1, once per lapse episode.
                SM_Flows::start($sub, 'winback', 'lapse|' . gmdate('Y-m-d', $last), [], ['winback_1']);
            }
        }
    }

    /**
     * WordPress never garbage-collects expired transients. On this store that let
     * wp_options grow to ~240MB (27k+ dead sa_fx currency rows), which OOM'd options.php.
     * Daily sweep of expired transient value + timeout rows; cron-only, never on page load.
     */
    public static function transient_gc(): void
    {
        global $wpdb;
        try {
            $now = time();
            // Delete expired timeout rows first, collecting their transient names.
            $expired = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM $wpdb->options
                  WHERE option_name LIKE '\_transient\_timeout\_%'
                    AND CAST(option_value AS UNSIGNED) > 0
                    AND CAST(option_value AS UNSIGNED) < %d
                  LIMIT 20000",
                $now
            ));
            foreach ((array) $expired as $tname) {
                $base = substr((string) $tname, strlen('_transient_timeout_'));
                $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name = %s", $tname));
                $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name = %s", '_transient_' . $base));
            }
        } catch (\Throwable $e) {
            if (function_exists('sa_core_audit_log')) {
                sa_core_audit_log('marketing.transient_gc_failed', ['error' => $e->getMessage()], 'warning');
            }
        }
    }

    /**
     * Service reminders: garage bookings about sm_setting('service_reminder_days')
     * old get one "time for your next service" email (opted-in customers only).
     */
    private static function service_reminder_scan(): void
    {
        if (!SM_Flows::enabled('service_reminder') || !function_exists('get_posts')) {
            return;
        }
        $days = max(30, (int) sm_setting('service_reminder_days'));
        $from = gmdate('Y-m-d', time() - ($days + 1) * DAY_IN_SECONDS);
        $to   = gmdate('Y-m-d', time() - $days * DAY_IN_SECONDS);
        $ids = get_posts([
            'post_type'   => 'sa_booking',
            'post_status' => 'publish',
            'numberposts' => 100,
            'fields'      => 'ids',
            'meta_query'  => [
                ['key' => '_sa_bk_date', 'value' => [$from, $to], 'compare' => 'BETWEEN', 'type' => 'DATE'],
                ['key' => '_sa_bk_status', 'value' => ['confirmed', 'in_progress', 'done'], 'compare' => 'IN'],
            ],
        ]);
        foreach ((array) $ids as $bid) {
            $bid = (int) $bid;
            $email = strtolower((string) get_post_meta($bid, '_sa_bk_email', true));
            if (!is_email($email)) {
                continue;
            }
            $location = (string) get_post_meta($bid, '_sa_bk_location', true);
            if ($location === 'usa') {
                continue; // garage service reminders are a Kenya offer
            }
            $sub = SM_Subscribers::get_by_email($email);
            if (!SM_Subscribers::is_marketable($sub)) {
                continue;
            }
            $car = trim(((string) get_post_meta($bid, '_sa_bk_year', true)) . ' ' . ((string) get_post_meta($bid, '_sa_bk_make', true)) . ' ' . ((string) get_post_meta($bid, '_sa_bk_model', true)));
            SM_Flows::start($sub, 'service_reminder', 'booking|' . $bid, [
                'car'      => $car,
                'booking'  => (string) get_post_meta($bid, '_sa_bk_ref', true),
                'products' => SM_Sender::payload_for(array_slice(sm_marketing_eligible(SM_Recommend::best_sellers(40)), 0, 2), 'KE'),
            ]);
        }
    }

    public static function stats(): array
    {
        global $wpdb;
        $q = SM_DB::table('queue');
        $s = SM_DB::table('sends');
        return [
            'pending'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status = 'pending'"),
            'sent'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status = 'sent'"),
            'cancelled' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status = 'cancelled'"),
            'failed'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status = 'failed'"),
            'sent_7d'   => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $s WHERE sent_at >= DATE_SUB(%s, INTERVAL 7 DAY)", sm_now())),
            'opens_7d'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $s WHERE opened_at IS NOT NULL AND sent_at >= DATE_SUB(%s, INTERVAL 7 DAY)", sm_now())),
            'clicks_7d' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $s WHERE clicked_at IS NOT NULL AND sent_at >= DATE_SUB(%s, INTERVAL 7 DAY)", sm_now())),
        ];
    }
}
