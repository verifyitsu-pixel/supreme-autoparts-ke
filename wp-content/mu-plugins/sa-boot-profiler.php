<?php
/**
 * SA boot profiler (temporary diagnostic). Only active with ?sa_prof=1.
 * X-SA-Boot: per-phase ms and DB query counts.
 * X-SA-Init: slowest individual init callbacks (wrapped and timed).
 * Headers are set at shutdown priority 0, before wp_ob_end_flush_all (1)
 * flushes the page-cache output buffer.
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!isset($_GET['sa_prof'])) {
    return;
}

$GLOBALS['sa_prof_t0'] = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
$GLOBALS['sa_prof_marks'] = ['mu_begin' => microtime(true)];
$GLOBALS['sa_prof_qmarks'] = [];
$GLOBALS['sa_prof_cb'] = [];

function sa_prof_name($fn): string {
    if (is_string($fn)) {
        return $fn;
    }
    if ($fn instanceof Closure) {
        return '{closure}';
    }
    if (is_array($fn)) {
        $c = is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0];
        return $c . '::' . (string) $fn[1];
    }
    if (is_object($fn) && method_exists($fn, '__invoke')) {
        return get_class($fn) . '::__invoke';
    }
    return 'unknown';
}

foreach (['muplugins_loaded', 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'template_redirect'] as $sa_prof_hook) {
    add_action($sa_prof_hook, static function () use ($sa_prof_hook): void {
        global $wpdb;
        $GLOBALS['sa_prof_marks'][$sa_prof_hook] = microtime(true);
        $GLOBALS['sa_prof_qmarks'][$sa_prof_hook] = isset($wpdb->num_queries) ? (int) $wpdb->num_queries : 0;
    }, PHP_INT_MAX);
}

// Wrap every init callback with a timer once all plugins + theme have registered.
add_action('after_setup_theme', static function (): void {
    if (!isset($GLOBALS['wp_filter']['init']) || !($GLOBALS['wp_filter']['init'] instanceof WP_Hook)) {
        return;
    }
    $hook = $GLOBALS['wp_filter']['init'];
    foreach ($hook->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $id => $cb) {
            if (!is_callable($cb['function'])) {
                continue;
            }
            $original = $cb['function'];
            $name = sa_prof_name($original);
            $hook->callbacks[$priority][$id]['function'] = static function (...$args) use ($original, $name, $cb) {
                $t = microtime(true);
                $args = array_slice($args, 0, (int) $cb['accepted_args']);
                $result = call_user_func_array($original, $args);
                $ms = (microtime(true) - $t) * 1000;
                if (!isset($GLOBALS['sa_prof_cb'][$name])) {
                    $GLOBALS['sa_prof_cb'][$name] = 0.0;
                }
                $GLOBALS['sa_prof_cb'][$name] += $ms;
                return $result;
            };
        }
    }
}, PHP_INT_MAX);

add_action('shutdown', static function (): void {
    global $wpdb;
    $marks = $GLOBALS['sa_prof_marks'];
    $marks['shutdown'] = microtime(true);
    $qmarks = $GLOBALS['sa_prof_qmarks'];
    $qmarks['shutdown'] = isset($wpdb->num_queries) ? (int) $wpdb->num_queries : 0;
    $parts = [];
    $prev = (float) $GLOBALS['sa_prof_t0'];
    $qprev = 0;
    foreach ($marks as $name => $ts) {
        $q = isset($qmarks[$name]) ? $qmarks[$name] : $qprev;
        $parts[] = $name . '=' . (int) round(($ts - $prev) * 1000) . 'ms/' . ($q - $qprev) . 'q';
        $prev = $ts;
        $qprev = $q;
    }
    $parts[] = 'total=' . (int) round((microtime(true) - (float) $GLOBALS['sa_prof_t0']) * 1000) . 'ms';
    arsort($GLOBALS['sa_prof_cb']);
    $top = [];
    foreach (array_slice($GLOBALS['sa_prof_cb'], 0, 12, true) as $name => $ms) {
        if ($ms < 5) {
            break;
        }
        $top[] = preg_replace('/[^A-Za-z0-9_:.{}-]/', '_', $name) . '=' . (int) round($ms);
    }
    if (!headers_sent()) {
        header('X-SA-Boot: ' . implode(' ', $parts), true);
        if ($top !== []) {
            header('X-SA-Init: ' . implode(' ', $top), true);
        }
    }
}, 0);
