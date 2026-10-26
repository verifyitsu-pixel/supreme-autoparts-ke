<?php
/* TEMPORARY search-request memory profiler for the live OOM diagnosis (v2).
 * Logs per-stage memory for front-end /?s= requests into the WC "sa-diag" log.
 * REMOVE after diagnosis. No behavior change: read-only logging. */
if (!function_exists('sa_diag_on')) {
    function sa_diag_on(): bool
    {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON)) {
            return false;
        }
        $s = isset($_GET['s']) ? (string) $_GET['s'] : ''; // phpcs:ignore
        // Exclude the Matomo tracker app (its s= param is seconds) - only real site URLs.
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
    add_filter('posts_results', static function (array $posts, $q): array {
        if ($q instanceof WP_Query && $q->is_main_query()) {
            sa_diag_log('posts_results', sprintf('found=%s ppp=%s got=%d', $q->found_posts, $q->get('posts_per_page'), count($posts)));
        }
        return $posts;
    }, 9999, 2);
    add_action('wp', static function (): void { sa_diag_log('wp'); });
    add_action('loop_start', static function ($q): void {
        if ($q instanceof WP_Query) {
            sa_diag_log('loop_start' . ($q->is_main_query() ? ':main' : ':secondary'), 'count=' . count((array) $q->posts));
        }
    });
    add_action('loop_end', static function ($q): void {
        if ($q instanceof WP_Query) {
            sa_diag_log('loop_end' . ($q->is_main_query() ? ':main' : ':secondary'));
        }
    });
    add_action('get_footer', static function (): void { sa_diag_log('get_footer:0'); }, 0);
    add_action('get_footer', static function (): void { sa_diag_log('get_footer:9999'); }, 9999);
    add_action('wp_footer', static function (): void { sa_diag_log('wp_footer:0'); }, 0);
    add_action('wp_footer', static function (): void { sa_diag_log('wp_footer:9999'); }, 9999);
    add_action('shutdown', static function (): void {
        $e = error_get_last();
        sa_diag_log('shutdown', is_array($e) ? ('last=' . substr((string) $e['message'] . ' @' . ($e['file'] ?? '') . ':' . ($e['line'] ?? ''), 0, 220)) : 'clean');
    }, 1);
}
