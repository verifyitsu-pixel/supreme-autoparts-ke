<?php
/**
 * SA boot profiler (temporary diagnostic). Only active with ?sa_prof=1.
 * Emits an X-SA-Boot header with per-phase milliseconds (since previous mark)
 * and the total DB query count, so cold-render bottlenecks can be located
 * without server access. Registered at shutdown priority 0 so the header is
 * set before wp_ob_end_flush_all (priority 1) flushes the page-cache buffer.
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!isset($_GET['sa_prof'])) {
    return;
}

$GLOBALS['sa_prof_t0'] = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
$GLOBALS['sa_prof_marks'] = ['mu_begin' => microtime(true)];

foreach (['muplugins_loaded', 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'template_redirect'] as $sa_prof_hook) {
    add_action($sa_prof_hook, static function () use ($sa_prof_hook): void {
        $GLOBALS['sa_prof_marks'][$sa_prof_hook] = microtime(true);
    }, PHP_INT_MAX);
}

add_action('shutdown', static function (): void {
    global $wpdb;
    $marks = $GLOBALS['sa_prof_marks'];
    $marks['shutdown'] = microtime(true);
    $parts = [];
    $prev = (float) $GLOBALS['sa_prof_t0'];
    foreach ($marks as $name => $ts) {
        $parts[] = $name . '=' . (int) round(($ts - $prev) * 1000);
        $prev = $ts;
    }
    $parts[] = 'total=' . (int) round((microtime(true) - (float) $GLOBALS['sa_prof_t0']) * 1000);
    $parts[] = 'queries=' . (isset($wpdb->num_queries) ? (int) $wpdb->num_queries : -1);
    if (!headers_sent()) {
        header('X-SA-Boot: ' . implode(' ', $parts), true);
    }
}, 0);
