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
    // Per-priority init timing (callback names only: function, Class::method or file-basename:line for closures).
    $GLOBALS['sa_st_init'] = ['t' => 0.0, 'p' => [], 'names' => []];
    add_action('init', static function (): void {
        global $wp_filter;
        $GLOBALS['sa_st_init']['t'] = microtime(true);
        if (!isset($wp_filter['init']) || !is_object($wp_filter['init']) || !is_array($wp_filter['init']->callbacks)) {
            return;
        }
        foreach ($wp_filter['init']->callbacks as $prio => $cbs) {
            if ((int) $prio <= 0 || (int) $prio === PHP_INT_MAX) {
                continue;
            }
            $names = [];
            foreach ($cbs as $cb) {
                $fn = $cb['function'];
                try {
                    if (is_string($fn)) {
                        $names[] = $fn;
                    } elseif (is_array($fn)) {
                        $names[] = (is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0]) . '::' . (string) $fn[1];
                    } elseif ($fn instanceof Closure) {
                        $r = new ReflectionFunction($fn);
                        $names[] = 'closure@' . basename((string) $r->getFileName()) . ':' . $r->getStartLine();
                    }
                } catch (Throwable $e) {
                    $names[] = 'unknown';
                }
            }
            $GLOBALS['sa_st_init']['names'][(int) $prio] = array_slice($names, 0, 8);
            add_action('init', static function () use ($prio): void {
                $i = &$GLOBALS['sa_st_init'];
                $now = microtime(true);
                $i['p'][(int) $prio] = $now - $i['t'];
                $i['t'] = $now;
            }, (int) $prio);
        }
    }, 0);
    // Cumulative time spent inside each listed hook (all callbacks), first-argument passthrough, for the totals path.
    $GLOBALS['sa_st_hk'] = [];
    foreach (['woocommerce_before_calculate_totals' => 'before_calc', 'woocommerce_cart_shipping_packages' => 'packages', 'woocommerce_package_rates' => 'pkg_rates', 'woocommerce_cart_calculate_fees' => 'fees', 'woocommerce_available_payment_gateways' => 'gateways', 'woocommerce_after_calculate_totals' => 'after_calc', 'woocommerce_cart_needs_shipping' => 'needs_ship', 'woocommerce_cart_loaded_from_session' => 'from_session', 'woocommerce_checkout_update_order_review' => 'update_review', 'woocommerce_cart_calculate_shipping' => 'calc_ship'] as $hook => $short) {
        add_filter($hook, static function ($v = null) use ($short) {
            $GLOBALS['sa_st_hk'][$short]['t'] = microtime(true);
            return $v;
        }, -1000000, 10);
        add_filter($hook, static function ($v = null) use ($short) {
            $h = &$GLOBALS['sa_st_hk'][$short];
            if (!empty($h['t'])) {
                $h['sum'] = ($h['sum'] ?? 0.0) + (microtime(true) - $h['t']);
                $h['n'] = ($h['n'] ?? 0) + 1;
                $h['t'] = 0.0;
            }
            return $v;
        }, 1000000, 10);
    }
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
        $ip = $GLOBALS['sa_st_init']['p'];
        if ($ip) {
            arsort($ip);
            $top = array_slice($ip, 0, 3, true);
            foreach ($top as $prio => $sec) {
                $parts[] = 'init_p' . $prio . ';dur=' . number_format($sec * 1000, 1, '.', '') . ';desc="' . str_replace('"', '', implode('|', $GLOBALS['sa_st_init']['names'][$prio] ?? [])) . '"';
            }
        }
        foreach ($GLOBALS['sa_st_hk'] as $short => $h) {
            if (isset($h['sum'])) {
                $parts[] = 'hk_' . $short . ';dur=' . number_format($h['sum'] * 1000, 1, '.', '') . ';desc="n=' . (int) $h['n'] . '"';
            }
        }
        if (isset($GLOBALS['sa_fx_fetch_ms'])) {
            $parts[] = 'fx_fetch;dur=' . number_format((float) $GLOBALS['sa_fx_fetch_ms'], 1, '.', '');
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
