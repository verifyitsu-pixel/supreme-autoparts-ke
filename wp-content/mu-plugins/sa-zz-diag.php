<?php
/* TEMPORARY search-request memory profiler for the live OOM diagnosis (v3).
 * Logs per-stage memory + the wp_footer callback registry for front-end /?s=
 * requests into the WC "sa-diag" log. REMOVE after diagnosis. Read-only. */
if (!function_exists('sa_diag_on')) {
    function sa_diag_on(): bool
    {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON)) {
            return false;
        }
        $s = isset($_GET['s']) ? (string) $_GET['s'] : ''; // phpcs:ignore
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($uri, '/wp-content/plugins/matomo/') !== false) {
            return false;
        }
        return $s !== '';
    }
    function sa_diag_log(string $stage, string $extra = ''): void
    {
        if (!sa_diag_on() || !function_exists('wc_get_logger')) {
            return;
        }
        $m = memory_get_usage(true) / 1048576;
        $p = memory_get_peak_usage(true) / 1048576;
        wc_get_logger()->info(
            sprintf('%s | %s | usage %.1f peak %.1f %s', $stage, (string) ($_SERVER['REQUEST_URI'] ?? ''), $m, $p, $extra),
            ['source' => 'sa-diag']
        );
    }
    function sa_diag_cb_name($cb): string
    {
        try {
            if (is_string($cb)) { return $cb; }
            if (is_array($cb)) {
                $c = is_object($cb[0]) ? get_class($cb[0]) : (string) $cb[0];
                return $c . '::' . (string) ($cb[1] ?? '?');
            }
            if ($cb instanceof Closure) {
                $r = new ReflectionFunction($cb);
                return 'closure@' . basename((string) $r->getFileName()) . ':' . $r->getStartLine();
            }
            if (is_object($cb) && method_exists($cb, '__invoke')) { return get_class($cb) . '::__invoke'; }
        } catch (Throwable $t) { /* ignore */ }
        return 'unknown';
    }
    add_filter('posts_results', static function (array $posts, $q): array {
        if ($q instanceof WP_Query && $q->is_main_query()) {
            sa_diag_log('posts_results', sprintf('found=%s ppp=%s got=%d', $q->found_posts, $q->get('posts_per_page'), count($posts)));
        }
        return $posts;
    }, 9999, 2);
    add_action('loop_end', static function ($q): void {
        if ($q instanceof WP_Query && $q->is_main_query()) {
            sa_diag_log('loop_end:main');
        }
    });
    add_action('wp_footer', static function (): void {
        global $wp_filter;
        $names = [];
        if (isset($wp_filter['wp_footer']) && $wp_filter['wp_footer'] instanceof WP_Hook) {
            foreach ($wp_filter['wp_footer']->callbacks as $pri => $cbs) {
                foreach ($cbs as $cb) {
                    $names[] = $pri . ':' . sa_diag_cb_name($cb['function'] ?? null);
                }
            }
        }
        sa_diag_log('wp_footer:0', 'cbs=' . implode(',', $names));
    }, 0);
    foreach ([6, 11, 19, 21, 25, 31, 41, 50, 99, 500, 9999] as $sa_diag_pri) {
        add_action('wp_footer', static function () use ($sa_diag_pri): void { sa_diag_log('wp_footer:' . $sa_diag_pri); }, $sa_diag_pri);
    }
    add_action('shutdown', static function (): void {
        $e = error_get_last();
        sa_diag_log('shutdown', is_array($e) ? ('last=' . substr((string) $e['message'] . ' @' . ($e['file'] ?? '') . ':' . ($e['line'] ?? ''), 0, 220)) : 'clean');
    }, 1);
}
