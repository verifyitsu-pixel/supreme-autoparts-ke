<?php
/**
 * Plugin Name: SA bounded catalog attribution
 * Description: Default-off, private query-fingerprint/request-path correlation. Never changes SQL.
 */
if (!defined('ABSPATH')) { exit; }

function sa_cat_trace_config(string $directory, int $now): ?array {
    if (is_link($directory) || !is_dir($directory)) { return null; }
    $mode = @fileperms($directory);
    if ($mode === false || ($mode & 0077) !== 0) { return null; }
    $file = $directory . '/control.json';
    if (is_link($file) || !is_file($file) || @filesize($file) > 2048) { return null; }
    $mode = @fileperms($file);
    if ($mode === false || ($mode & 0077) !== 0) { return null; }
    $raw = @file_get_contents($file);
    $c = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($c) || !isset($c['start'], $c['end']) || !is_int($c['start']) || !is_int($c['end'])) { return null; }
    if ($c['end'] <= $c['start'] || $c['end'] - $c['start'] > 10800 || $now < $c['start'] || $now >= $c['end']) { return null; }
    return ['start' => $c['start'], 'end' => $c['end']];
}

function sa_cat_trace_fingerprint(string $sql): string {
    // Same literal-removal normalization as the reviewed slow-log summary, with optional terminal semicolon removed.
    $s = preg_replace("/'(?:\\\\.|''|[^'\\\\])*'/s", '?', $sql);
    $s = preg_replace('/"(?:\\\\.|[^"\\\\])*"/s', '?', (string) $s);
    $s = preg_replace('/\b0x[a-fA-F0-9]+\b/', '?', (string) $s);
    $s = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', (string) $s);
    $s = preg_replace('/\s+/', ' ', (string) $s);
    return hash('sha256', rtrim(trim((string) $s), ';'));
}

function sa_cat_trace_route(): array {
    // Never persist request URI, query values, headers, identity, cookies or request body.
    if (defined('DOING_CRON') && DOING_CRON) { return ['class' => 'cron']; }
    if (defined('WP_CLI') && WP_CLI) { return ['class' => 'cli']; }
    if (defined('REST_REQUEST') && REST_REQUEST) { return ['class' => 'rest']; }
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) { return ['class' => 'ajax']; }
    if (function_exists('is_admin') && is_admin()) { return ['class' => 'admin']; }
    if ((function_exists('is_account_page') && is_account_page()) || (function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout())) { return ['class' => 'private-commerce']; }
    $r = ['class' => 'other'];
    $url = null;
    if (function_exists('is_front_page') && is_front_page()) {
        $r['class'] = 'home'; $url = home_url('/');
    } elseif (function_exists('is_shop') && is_shop()) {
        $r['class'] = 'shop'; $url = get_permalink(wc_get_page_id('shop'));
    } elseif (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
        $object = get_queried_object();
        if ($object instanceof WP_Term) { $url = get_term_link($object); $r['class'] = 'product-taxonomy'; }
    } elseif (function_exists('is_product') && is_product()) {
        $id = get_queried_object_id();
        if (get_post_status($id) === 'publish') { $url = get_permalink($id); $r['class'] = 'product'; }
    } elseif (function_exists('is_search') && is_search()) {
        $r['class'] = 'search';
    }
    if (is_string($url)) {
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && strlen($path) <= 512 && !preg_match('/[\x00-\x1f]/', $path)) { $r['public_path'] = $path; }
    }
    $keys = [];
    foreach (['filter_brand','filter_year','filter_make','filter_model','min_price','max_price','orderby','paged','s'] as $key) {
        if (isset($_GET[$key])) { $keys[] = $key; }
    }
    if ($keys !== []) { $r['filter_keys_only'] = $keys; }
    return $r;
}

function sa_cat_trace_append(string $directory, array $config, array $record, int $now): bool {
    if ($now >= $config['end'] || sa_cat_trace_config($directory, $now) !== $config) { return false; }
    $path = $directory . '/events.jsonl';
    if (is_link($path)) { return false; }
    $h = @fopen($path, 'c');
    if ($h === false) { return false; }
    @chmod($path, 0600);
    try {
        if (!@flock($h, LOCK_EX | LOCK_NB)) { return false; }
        $stat = fstat($h);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($line) || strlen($line) > 4096 || !is_array($stat) || $stat['size'] + strlen($line) + 1 > 1048576) { return false; }
        if (@fseek($h, 0, SEEK_END) !== 0) { return false; }
        return @fwrite($h, $line . "\n") === strlen($line) + 1;
    } finally { @flock($h, LOCK_UN); @fclose($h); }
}

function sa_cat_trace_boot(string $directory): void {
    $config = sa_cat_trace_config($directory, time());
    if ($config === null) { return; }
    $events = []; $closed = false;
    add_filter('query', static function ($sql) use (&$events, &$closed, $config) {
        if ($closed || time() >= $config['end'] || count($events) >= 16 || !is_string($sql) || strlen($sql) > 131072 || strpos($sql, 'tt1.term_taxonomy_id') === false || strpos($sql, 'wp_postmeta') === false) { return $sql; }
        try {
            if (sa_cat_trace_fingerprint($sql) !== '8fa7fe3455d866c7988a8a89fc09a07be809b0415e7a8a027c88f90aca5a140f') { return $sql; }
            global $wpdb;
            // Uses the existing connection handle, performs no additional SQL, and never records a connection secret.
            $dbh = isset($wpdb->dbh) ? $wpdb->dbh : null;
            if (!($dbh instanceof mysqli)) { return $sql; }
            $thread = mysqli_thread_id($dbh);
            if ($thread > 0) { $events[] = ['query_start_epoch' => microtime(true), 'mysql_thread_id' => $thread, 'fingerprint' => '8fa7fe3455d866c7988a8a89fc09a07be809b0415e7a8a027c88f90aca5a140f']; }
        } catch (Throwable $e) { /* Diagnostic failure never changes the query or request. */ }
        return $sql;
    }, PHP_INT_MAX);
    add_action('shutdown', static function () use (&$events, &$closed, $directory, $config): void {
        $closed = true;
        if ($events === []) { return; }
        try {
            $record = ['schema' => 1, 'request_finished_epoch' => microtime(true), 'route' => sa_cat_trace_route(), 'queries' => $events];
            sa_cat_trace_append($directory, $config, $record, time());
        } catch (Throwable $e) { /* Fail open; partial/failed requests can be absent. */ }
    }, PHP_INT_MAX);
}

if (!defined('SA_CATALOG_TRACE_TEST')) { sa_cat_trace_boot('/tmp/sa-catalog-attribution'); }
