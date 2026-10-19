<?php
/**
 * Recommendation engine: personalized product picks per subscriber.
 *
 * Score = 3 x category affinity (share of the subscriber's past items in that category)
 *       + 2 x co-purchase signal (how often a candidate appears in orders with something they bought)
 *       + 1 x global popularity (total sales), with a small boost for recently listed products.
 * Already-purchased and out-of-stock products are excluded. Fallback: best sellers.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Recommend
{
    /** @return WC_Product[] */
    public static function for_subscriber(array $sub, int $count = 4): array
    {
        if (!function_exists('wc_get_products')) {
            return [];
        }
        $email = strtolower((string) ($sub['email'] ?? ''));
        $bought_ids = self::purchased_product_ids($email);
        $affinity = self::category_affinity($bought_ids);
        $co = self::co_purchase_counts($email, $bought_ids);

        $candidates = wc_get_products([
            'status'       => 'publish',
            'stock_status' => 'instock',
            'limit'        => 200,
            'exclude'      => $bought_ids ?: [0],
        ]);

        $scored = [];
        foreach ((array) $candidates as $p) {
            $score = 0.0;
            $cats = wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'slugs']);
            foreach ((array) $cats as $slug) {
                $score += 3.0 * (float) ($affinity[$slug] ?? 0);
            }
            $score += 2.0 * (float) ($co[$p->get_id()] ?? 0);
            $score += min(1.0, ((float) $p->get_total_sales()) / 50.0);
            $created = $p->get_date_created();
            if ($created && $created->getTimestamp() > (sm_ts() - 30 * DAY_IN_SECONDS)) {
                $score += 0.5; // new listing boost
            }
            $scored[] = ['score' => $score, 'product' => $p];
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $out = [];
        foreach ($scored as $row) {
            $out[] = $row['product'];
            if (count($out) >= $count) {
                break;
            }
        }
        if (count($out) < $count) {
            foreach (self::best_sellers($count, $bought_ids) as $p) {
                $out[] = $p;
                if (count($out) >= $count) {
                    break;
                }
            }
        }
        return array_slice($out, 0, $count);
    }

    /** @return WC_Product[] */
    public static function best_sellers(int $count = 4, array $exclude_ids = []): array
    {
        if (!function_exists('wc_get_products')) {
            return [];
        }
        $products = wc_get_products([
            'status'       => 'publish',
            'stock_status' => 'instock',
            'limit'        => $count + count($exclude_ids),
            'orderby'      => 'popularity',
            'order'        => 'DESC',
            'exclude'      => $exclude_ids ?: [0],
        ]);
        return array_slice((array) $products, 0, $count);
    }

    /** All product IDs this email has ever ordered. */
    public static function purchased_product_ids(string $email): array
    {
        if (!function_exists('wc_get_orders') || $email === '') {
            return [];
        }
        $ids = [];
        $orders = wc_get_orders([
            'billing_email' => $email,
            'status'        => ['wc-processing', 'wc-completed', 'wc-on-hold'],
            'limit'         => 100,
            'return'        => 'objects',
        ]);
        foreach ($orders as $o) {
            foreach ($o->get_items() as $item) {
                $pid = $item->get_product_id();
                if ($pid) {
                    $ids[$pid] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /** slug => share of purchased items in that category. */
    private static function category_affinity(array $bought_ids): array
    {
        $counts = [];
        $total = 0;
        foreach ($bought_ids as $pid) {
            $cats = wp_get_post_terms($pid, 'product_cat', ['fields' => 'slugs']);
            foreach ((array) $cats as $slug) {
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
                $total++;
            }
        }
        if ($total === 0) {
            return [];
        }
        foreach ($counts as $slug => $n) {
            $counts[$slug] = $n / $total;
        }
        return $counts;
    }

    /** product_id => times it co-occurred in orders containing something the subscriber bought. */
    private static function co_purchase_counts(string $email, array $bought_ids): array
    {
        if (!$bought_ids || !function_exists('wc_get_orders')) {
            return [];
        }
        $counts = [];
        $orders = wc_get_orders([
            'status' => ['wc-processing', 'wc-completed'],
            'limit'  => 300,
            'return' => 'objects',
        ]);
        $bought = array_fill_keys($bought_ids, true);
        foreach ($orders as $o) {
            if (strtolower((string) $o->get_billing_email()) === $email) {
                continue; // their own orders define affinity, not co-purchase
            }
            $pids = [];
            $overlap = false;
            foreach ($o->get_items() as $item) {
                $pid = $item->get_product_id();
                if (!$pid) {
                    continue;
                }
                $pids[] = $pid;
                if (isset($bought[$pid])) {
                    $overlap = true;
                }
            }
            if ($overlap) {
                foreach ($pids as $pid) {
                    if (!isset($bought[$pid])) {
                        $counts[$pid] = ($counts[$pid] ?? 0) + 1;
                    }
                }
            }
        }
        return $counts;
    }
}
