<?php
/**
 * Plugin Name: SA Server-Timing diagnostics
 * Description: Aggregate PHP timing (no customer data) on a few slow routes, to split PHP time from upstream queueing.
 *
 * Emits one Server-Timing response header on: WooCommerce AJAX (wc-ajax), the sa_geo_resolve admin-ajax action and
 * storefront search (?s=). Values are elapsed milliseconds, a query count and peak memory. No URLs, cookies, query text,
 * customer, session or order data. Coverage: PHP's own request clock starts when PHP receives the request, so time spent
 * queued in nginx/php-fpm before that is NOT included, and a request that dies (502) sends no header.
 */
if (!defined('ABSPATH')) {
    exit;
}

function sa_st_route(): string {
    $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
    if (isset($_GET['wc-ajax'])) {
        $w = (string) $_GET['wc-ajax'];
        return $w === 'update_order_review' ? 'update_order_review' : 'wc_ajax_other';
    }
    if (isset($_REQUEST['action']) && (string) $_REQUEST['action'] === 'sa_geo_resolve') {
        return 'sa_geo_resolve';
    }
    if ($method === 'GET' && isset($_GET['s']) && !is_admin()) {
        return 'search';
    }
    return '';
}

$GLOBALS['sa_st_route'] = sa_st_route();
if ($GLOBALS['sa_st_route'] !== '') {
    $GLOBALS['sa_st_marks'] = ['mu' => microtime(true)];
    $sa_st_mark = static function (string $name): callable {
        return static function () use ($name): void {
            if (!isset($GLOBALS['sa_st_marks'][$name])) {
                $GLOBALS['sa_st_marks'][$name] = microtime(true);
            }
        };
    };
    add_action('plugins_loaded', $sa_st_mark('plugins'), PHP_INT_MAX);
    add_action('init', $sa_st_mark('init'), PHP_INT_MAX);
    add_action('wp_loaded', $sa_st_mark('loaded'), PHP_INT_MAX);
    add_action('woocommerce_checkout_update_order_review', $sa_st_mark('review_start'), 0);
    add_action('woocommerce_after_calculate_totals', $sa_st_mark('totals_done'), PHP_INT_MAX);
    $GLOBALS['sa_st_wpq'] = ['sum' => 0.0, 'n' => 0, 'start' => 0.0];
    add_filter('posts_pre_query', static function ($posts) {
        $GLOBALS['sa_st_wpq']['start'] = microtime(true);
        return $posts;
    }, 0);
    add_filter('the_posts', static function ($posts) {
        $w = &$GLOBALS['sa_st_wpq'];
        if ($w['start'] > 0.0) {
            $w['sum'] += microtime(true) - $w['start'];
            $w['n']++;
            $w['start'] = 0.0;
        }
        return $posts;
    }, PHP_INT_MAX);
    ob_start(static function (string $buffer): string {
        if (headers_sent()) {
            return $buffer;
        }
        $now = microtime(true);
        $m = $GLOBALS['sa_st_marks'];
        $t0 = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $m['mu'];
        $ms = static function (float $a, float $b): string {
            return number_format(max(0.0, $b - $a) * 1000, 1, '.', '');
        };
        $parts = ['total;dur=' . $ms($t0, $now), 'before_mu;dur=' . $ms($t0, $m['mu'])];
        $prev = $m['mu'];
        foreach (['plugins' => 'plugins', 'init' => 'init', 'loaded' => 'wp_loaded'] as $key => $label) {
            if (isset($m[$key])) {
                $parts[] = $label . ';dur=' . $ms($prev, $m[$key]);
                $prev = $m[$key];
            }
        }
        if (isset($m['review_start'])) {
            $parts[] = 'to_review;dur=' . $ms($prev, $m['review_start']);
            if (isset($m['totals_done'])) {
                $parts[] = 'review_totals;dur=' . $ms($m['review_start'], $m['totals_done']);
            }
        }
        $w = $GLOBALS['sa_st_wpq'];
        $parts[] = 'wp_query_sum;dur=' . number_format($w['sum'] * 1000, 1, '.', '');
        $parts[] = 'wp_query_n;desc="' . (int) $w['n'] . '"';
        $queries = isset($GLOBALS['wpdb']) && isset($GLOBALS['wpdb']->num_queries) ? (int) $GLOBALS['wpdb']->num_queries : 0;
        $parts[] = 'queries;desc="' . $queries . '"';
        $parts[] = 'peak_mem;desc="' . (int) round(memory_get_peak_usage(true) / 1048576) . 'MB"';
        $parts[] = 'route;desc="' . $GLOBALS['sa_st_route'] . '"';
        header('Server-Timing: ' . implode(', ', $parts), false);
        return $buffer;
    });
}
