<?php
/**
 * Segments: named audiences built from subscriber consent state + WooCommerce order data.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Segments
{
    /** Segment key => label (for the admin dropdown). */
    public static function definitions(): array
    {
        $defs = [
            'all_active'      => 'All consenting customers',
            'buyers'          => 'Opted-in customers (1+ orders)',
            'non_buyers'      => 'Opted-in, never ordered',
            'vip'             => 'VIP (3+ orders or $500+ spent)',
            'recent_buyers_30'=> 'Ordered in the last 30 days',
            'lapsed_60'       => 'Lapsed (no order in 60+ days)',
            'region_ke'       => 'Kenya & Africa subscribers',
            'region_us'       => 'US & international subscribers',
            'service_customers'=> 'Garage service customers (have a booking)',
        ];
        // Category segments from the live catalog.
        if (function_exists('get_terms')) {
            $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 40]);
            if (is_array($cats)) {
                foreach ($cats as $c) {
                    $defs['category:' . $c->slug] = 'Bought from category: ' . $c->name;
                }
            }
        }
        return $defs;
    }

    /**
     * Resolve a segment to subscriber rows (marketable only), up to $limit.
     * @return array[] subscriber rows
     */
    public static function resolve(string $key, int $limit = 2000): array
    {
        global $wpdb;
        $t = SM_DB::table('subscribers');
        $now = sm_now();

        // Refresh order stats for everyone first so buyer segments are correct.
        self::refresh_all_stats();

        $base = "FROM $t WHERE status = 'active' AND (paused_until IS NULL OR paused_until <= %s)";
        $args = [$now];
        $where = '';

        switch (true) {
            case $key === 'all_active':
                break;
            case $key === 'buyers':
                $where = ' AND orders_count > 0';
                break;
            case $key === 'non_buyers':
                $where = ' AND orders_count = 0';
                break;
            case $key === 'vip':
                $where = ' AND (orders_count >= 3 OR total_spent >= 500)';
                break;
            case $key === 'recent_buyers_30':
                $where = ' AND last_order_at IS NOT NULL AND last_order_at >= DATE_SUB(%s, INTERVAL 30 DAY)';
                $args[] = $now;
                break;
            case $key === 'lapsed_60':
                $where = ' AND orders_count > 0 AND (last_order_at IS NULL OR last_order_at < DATE_SUB(%s, INTERVAL 60 DAY))';
                $args[] = $now;
                break;
            case $key === 'region_ke':
                $where = " AND region IN ('KE','UG','TZ','RW','NG','GH','ZA','EG','ET','DZ','MA','SN','CI','CM','ZM','ZW','MW','BI','CD','AO','MZ')";
                break;
            case $key === 'region_us':
                $where = " AND (region = 'US' OR region = '')";
                break;
            case $key === 'service_customers':
                return self::resolve_service_customers($limit);
            case str_starts_with($key, 'category:'):
                $slug = substr($key, strlen('category:'));
                return self::resolve_category_buyers($slug, $base, $args, $limit);
            default:
                return [];
        }

        // Zero is an explicit all-audience request, not a hidden first-2,000 cap.
        $sql = "SELECT * $base $where ORDER BY id ASC";
        if ($limit > 0) {
            $sql .= ' LIMIT %d';
            $args[] = $limit;
        }
        return (array) $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    }

    /** Category buyers: opted-in subscribers with an order containing a product in this category. */
    private static function resolve_category_buyers(string $slug, string $base, array $args, int $limit): array
    {
        global $wpdb;
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        $emails = [];
        $orders = wc_get_orders(['status' => ['wc-processing', 'wc-completed'], 'limit' => 500, 'return' => 'objects']);
        foreach ($orders as $o) {
            foreach ($o->get_items() as $item) {
                $pid = $item->get_product_id();
                if ($pid && has_term($slug, 'product_cat', $pid)) {
                    $emails[strtolower((string) $o->get_billing_email())] = true;
                    break;
                }
            }
        }
        if (!$emails) {
            return [];
        }
        $t = SM_DB::table('subscribers');
        $placeholders = implode(',', array_fill(0, count($emails), '%s'));
        $now = sm_now();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE status = 'active' AND (paused_until IS NULL OR paused_until <= %s) AND email IN ($placeholders) LIMIT %d",
            ...array_merge([$now], array_keys($emails), [$limit])
        ), ARRAY_A);
        return (array) $rows;
    }

    /** Garage customers: opted-in subscribers with a service booking on file. */
    private static function resolve_service_customers(int $limit): array
    {
        global $wpdb;
        $bookings = get_posts([
            'post_type'   => 'sa_booking',
            'post_status' => 'publish',
            'numberposts' => 1000,
            'fields'      => 'ids',
        ]);
        $emails = [];
        foreach ((array) $bookings as $bid) {
            $e = strtolower((string) get_post_meta((int) $bid, '_sa_bk_email', true));
            if (is_email($e)) {
                $emails[$e] = true;
            }
        }
        if (!$emails) {
            return [];
        }
        $t = SM_DB::table('subscribers');
        $placeholders = implode(',', array_fill(0, count($emails), '%s'));
        $now = sm_now();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE status = 'active' AND (paused_until IS NULL OR paused_until <= %s) AND email IN ($placeholders) LIMIT %d",
            ...array_merge([$now], array_keys($emails), [$limit])
        ), ARRAY_A);
        return (array) $rows;
    }

    /** Approximate recipient count for the admin UI. */
    public static function count(string $key): int
    {
        return count(self::resolve($key, 5000));
    }

    /** Keep order stats fresh for segmentation (cheap: only active subscribers). */
    public static function refresh_all_stats(): void
    {
        global $wpdb;
        $t = SM_DB::table('subscribers');
        $stale = $wpdb->get_col(
            "SELECT email FROM $t WHERE status = 'active' AND updated_at < DATE_SUB('" . sm_now() . "', INTERVAL 1 DAY) LIMIT 300"
        );
        foreach ((array) $stale as $email) {
            SM_Subscribers::refresh_order_stats((string) $email);
        }
    }
}
