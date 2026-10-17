<?php
/**
 * Plugin Name: SA Analytics (self-hosted + DataFast)
 * Description: Server-side analytics owned by the store: logs every page request (humans and bots) to its own DB table,
 *              classifies AI / search / training crawlers, shows an Analytics dashboard in wp-admin, relays bot hits to
 *              DataFast (datafa.st) and injects the DataFast visitor script. No third party required for the core data.
 * Version: 1.0.0
 * Author: Supreme Autoparts
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_ANALYTICS_VERSION   = '1.0.0';
const SA_ANALYTICS_RETENTION = 90; // days of raw events kept

/**
 * DataFast website IDs per host (public tracking identifiers, safe to keep in code).
 * Filled in after the sites are created in the DataFast dashboard; empty = DataFast off for that host,
 * self-hosted tracking keeps working regardless.
 */
function sa_analytics_datafast_sites(): array
{
    return [
        'www.supremeautoparts.co.ke' => '', // dfid_... pending dashboard setup
        'supremeautoparts.co.ke'     => '',
        'maxoutnutrition.com'        => '',
        'www.maxoutnutrition.com'    => '',
    ];
}

/** DataFast website ID for the current host, or '' when not configured. */
function sa_analytics_datafast_id(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $map  = sa_analytics_datafast_sites();
    return $map[$host] ?? '';
}

/**
 * Classify a user agent. Returns ['company'=>, 'crawler'=>, 'category'=>] for bots, null for humans.
 * Categories mirror DataFast: answer_fetch (AI assistant fetching a page for a user),
 * training (model training crawl), search_index (classic search engine), ai_crawler (other AI agent), bot (generic).
 */
function sa_analytics_classify_ua(string $ua): ?array
{
    if ($ua === '') {
        return null;
    }
    static $map = [
        // OpenAI
        'ChatGPT-User'        => ['OpenAI', 'ChatGPT-User', 'answer_fetch'],
        'OAI-SearchBot'       => ['OpenAI', 'OAI-SearchBot', 'search_index'],
        'GPTBot'              => ['OpenAI', 'GPTBot', 'training'],
        // Anthropic
        'Claude-User'         => ['Anthropic', 'Claude-User', 'answer_fetch'],
        'Claude-SearchBot'    => ['Anthropic', 'Claude-SearchBot', 'search_index'],
        'ClaudeBot'           => ['Anthropic', 'ClaudeBot', 'training'],
        // Perplexity
        'Perplexity-User'     => ['Perplexity', 'Perplexity-User', 'answer_fetch'],
        'PerplexityBot'       => ['Perplexity', 'PerplexityBot', 'search_index'],
        // Google
        'Google-Extended'     => ['Google', 'Google-Extended', 'training'],
        'Googlebot'           => ['Google', 'Googlebot', 'search_index'],
        'GoogleInspectionTool' => ['Google', 'GoogleInspectionTool', 'search_index'],
        'GoogleOther'         => ['Google', 'GoogleOther', 'ai_crawler'],
        // Microsoft
        'Bingbot'             => ['Microsoft', 'Bingbot', 'search_index'],
        'bingbot'             => ['Microsoft', 'Bingbot', 'search_index'],
        // Meta / Apple / Amazon / others
        'Meta-ExternalAgent'  => ['Meta', 'Meta-ExternalAgent', 'training'],
        'Meta-ExternalFetcher' => ['Meta', 'Meta-ExternalFetcher', 'answer_fetch'],
        'FacebookBot'         => ['Meta', 'FacebookBot', 'training'],
        'Applebot-Extended'   => ['Apple', 'Applebot-Extended', 'training'],
        'Applebot'            => ['Apple', 'Applebot', 'search_index'],
        'Amazonbot'           => ['Amazon', 'Amazonbot', 'search_index'],
        'Bytespider'          => ['ByteDance', 'Bytespider', 'training'],
        'CCBot'               => ['Common Crawl', 'CCBot', 'training'],
        'cohere-ai'           => ['Cohere', 'cohere-ai', 'training'],
        'cohere-training-data-crawler' => ['Cohere', 'cohere-training', 'training'],
        'Diffbot'             => ['Diffbot', 'Diffbot', 'training'],
        'MistralAI-User'      => ['Mistral', 'MistralAI-User', 'answer_fetch'],
        'YouBot'              => ['You.com', 'YouBot', 'search_index'],
        'Timpibot'            => ['Timpi', 'Timpibot', 'search_index'],
        'ImagesiftBot'        => ['Imagesift', 'ImagesiftBot', 'search_index'],
        'Omgilibot'           => ['Omgili', 'Omgilibot', 'search_index'],
        'DuckDuckBot'         => ['DuckDuckGo', 'DuckDuckBot', 'search_index'],
        'Baiduspider'         => ['Baidu', 'Baiduspider', 'search_index'],
        'YandexBot'           => ['Yandex', 'YandexBot', 'search_index'],
        'Slurp'               => ['Yahoo', 'Slurp', 'search_index'],
    ];
    foreach ($map as $needle => $info) {
        if (stripos($ua, $needle) !== false) {
            return ['company' => $info[0], 'crawler' => $info[1], 'category' => $info[2]];
        }
    }
    if (preg_match('~bot|spider|crawl|slurp|curl/|wget/|python-requests|urllib|httpclient|headless|lighthouse|pingdom|uptimerobot|ahrefs|semrush|mj12bot|dotbot~i', $ua)) {
        return ['company' => 'Other', 'crawler' => substr($ua, 0, 60), 'category' => 'bot'];
    }
    return null;
}

/** Requests we never log: wp-admin, login, REST, ajax, cron, static assets, non-GET/HEAD. */
function sa_analytics_should_track(): bool
{
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON)) {
        return false;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = strtolower((string) parse_url($uri, PHP_URL_PATH));
    if ($path === '' ) {
        return false;
    }
    if (strpos($path, '/wp-admin') === 0 || strpos($path, '/wp-login') === 0 || strpos($path, '/xmlrpc') === 0 || strpos($path, '/wp-json') === 0 || strpos($path, '/wp-includes') === 0 || strpos($path, '/wp-content') === 0) {
        return false;
    }
    if (preg_match('~\.(css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|otf|mp4|webm|mp3|wav|pdf|zip|gz|br|txt)$~', $path) && !preg_match('~/(robots|llms|llms-full)\.txt$~', $path)) {
        return false;
    }
    // Skip shop managers/admins so owner/staff browsing does not pollute customer stats.
    if (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options')) {
        return false;
    }
    return true;
}

/** Best-effort client IP (first X-Forwarded-For hop behind Railway's proxy). */
function sa_analytics_client_ip(): string
{
    $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

add_action('init', static function (): void {
    if (!sa_analytics_should_track()) {
        return;
    }
    $ua  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $ref = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
    $uri = substr((string) ($_SERVER['REQUEST_URI'] ?? '/'), 0, 500);
    $bot = sa_analytics_classify_ua($ua);
    $ip  = sa_analytics_client_ip();

    $GLOBALS['sa_analytics_event'] = [
        'ts'       => gmdate('Y-m-d H:i:s'),
        'host'     => substr(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), 0, 190),
        'uri'      => $uri,
        'referrer' => $ref,
        'ua'       => $ua,
        'ip'       => $ip,
        'ip_hash'  => $ip !== '' ? hash('sha256', $ip . '|' . (defined('AUTH_SALT') ? AUTH_SALT : 'sa')) : '',
        'is_bot'   => $bot ? 1 : 0,
        'company'  => $bot['company'] ?? '',
        'crawler'  => $bot['crawler'] ?? '',
        'category' => $bot['category'] ?? '',
    ];

    add_action('shutdown', 'sa_analytics_record', 1);
}, 1);

/** Store the event (and relay bot hits to DataFast) after the page has been rendered. */
function sa_analytics_record(): void
{
    $e = $GLOBALS['sa_analytics_event'] ?? null;
    if (!is_array($e)) {
        return;
    }
    unset($GLOBALS['sa_analytics_event']);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request(); // harmless no-op under mod_php; frees the visitor first under FPM
    }

    global $wpdb;
    $table = $wpdb->prefix . 'sa_analytics_events';
    // Table is created by the admin_init schema check; suppress if a fresh deploy has not hit wp-admin yet.
    $wpdb->suppress_errors(true);
    $wpdb->insert($table, [
        'ts'       => $e['ts'],
        'host'     => $e['host'],
        'uri'      => $e['uri'],
        'referrer' => $e['referrer'],
        'ua'       => $e['ua'],
        'ip_hash'  => $e['ip_hash'],
        'is_bot'   => $e['is_bot'],
        'company'  => $e['company'],
        'crawler'  => $e['crawler'],
        'category' => $e['category'],
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
    $wpdb->suppress_errors(false);

    // Relay bot hits to DataFast (fire-and-forget) when a website ID is configured.
    $dfid = sa_analytics_datafast_id();
    if ($e['is_bot'] && $dfid !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        wp_remote_post('https://datafa.st/api/ai-crawls', [
            'timeout'  => 2,
            'blocking' => false,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => wp_json_encode([
                'websiteId' => $dfid,
                'domain'    => $e['host'],
                'href'      => $scheme . '://' . $e['host'] . $e['uri'],
                'ai'        => [
                    'userAgent' => $e['ua'],
                    'ip'        => $e['ip'],
                    'source'    => 'server_middleware',
                ],
            ]),
        ]);
    }
}

/* ----------------------------------------------------------------------- */
/* Schema                                                                   */
/* ----------------------------------------------------------------------- */

function sa_analytics_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'sa_analytics_events';
}

add_action('admin_init', static function (): void {
    if (get_option('sa_analytics_db_version') === SA_ANALYTICS_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $table   = sa_analytics_table();
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        ts datetime NOT NULL,
        host varchar(190) NOT NULL DEFAULT '',
        uri varchar(500) NOT NULL DEFAULT '',
        referrer varchar(500) NOT NULL DEFAULT '',
        ua varchar(500) NOT NULL DEFAULT '',
        ip_hash char(64) NOT NULL DEFAULT '',
        is_bot tinyint(1) NOT NULL DEFAULT 0,
        company varchar(64) NOT NULL DEFAULT '',
        crawler varchar(128) NOT NULL DEFAULT '',
        category varchar(32) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY ts (ts),
        KEY is_bot_ts (is_bot, ts),
        KEY company_ts (company, ts)
    ) {$charset};");
    update_option('sa_analytics_db_version', SA_ANALYTICS_VERSION);

    if (!wp_next_scheduled('sa_analytics_prune')) {
        wp_schedule_event(time() + 3600, 'daily', 'sa_analytics_prune');
    }
});

add_action('sa_analytics_prune', static function (): void {
    global $wpdb;
    $table = sa_analytics_table();
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE ts < %s",
        gmdate('Y-m-d H:i:s', time() - SA_ANALYTICS_RETENTION * DAY_IN_SECONDS)
    ));
});

/* ----------------------------------------------------------------------- */
/* DataFast visitor script (humans)                                         */
/* ----------------------------------------------------------------------- */

add_action('wp_head', static function (): void {
    $dfid = sa_analytics_datafast_id();
    if ($dfid === '' || is_admin()) {
        return;
    }
    $domain = preg_replace('~^www\.~', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
    printf(
        '<script defer data-website-id="%s" data-domain="%s" src="https://datafa.st/js/script.js"></script>' . "\n",
        esc_attr($dfid),
        esc_attr($domain)
    );
}, 3);

/* ----------------------------------------------------------------------- */
/* wp-admin dashboard                                                       */
/* ----------------------------------------------------------------------- */

add_action('admin_menu', static function (): void {
    add_menu_page(
        'Analytics',
        'Analytics',
        'manage_options',
        'sa-analytics',
        'sa_analytics_render_admin',
        'dashicons-chart-area',
        3
    );
});

function sa_analytics_range(): array
{
    $r = isset($_GET['r']) ? sanitize_key((string) $_GET['r']) : '7';
    $days = in_array($r, ['1', '7', '30'], true) ? (int) $r : 7;
    return [$r, $days];
}

function sa_analytics_render_admin(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Nope');
    }
    global $wpdb;
    $table  = sa_analytics_table();
    $tab    = (isset($_GET['tab']) && $_GET['tab'] === 'bots') ? 'bots' : 'visitors';
    [$r, $days] = sa_analytics_range();
    $since  = gmdate('Y-m-d 00:00:00', time() - ($days - 1) * DAY_IN_SECONDS);

    $base = admin_url('admin.php?page=sa-analytics');
    echo '<div class="wrap"><h1>Analytics</h1>';
    echo '<p style="color:#666">Self-hosted, stored in this site\'s own database. Server-side: bots and AI crawlers are captured too, even though they never run JavaScript.</p>';
    echo '<h2 class="nav-tab-wrapper">';
    foreach (['visitors' => 'Visitors', 'bots' => 'Bots & AI crawlers'] as $k => $label) {
        $cls = $tab === $k ? ' nav-tab-active' : '';
        echo '<a class="nav-tab' . $cls . '" href="' . esc_url($base . '&tab=' . $k . '&r=' . $r) . '">' . esc_html($label) . '</a>';
    }
    echo '</h2>';
    echo '<p>';
    foreach (['1' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days'] as $k => $label) {
        $style = $r === $k ? 'button button-primary' : 'button';
        echo ' <a class="' . $style . '" href="' . esc_url($base . '&tab=' . $tab . '&r=' . $k) . '">' . esc_html($label) . '</a>';
    }
    echo '</p>';

    $is_bot = $tab === 'bots' ? 1 : 0;
    $total  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE is_bot = %d AND ts >= %s", $is_bot, $since));
    $uniq   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_hash) FROM {$table} WHERE is_bot = %d AND ts >= %s AND ip_hash <> ''", $is_bot, $since));

    echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0 20px">';
    sa_analytics_stat_card($tab === 'bots' ? 'Bot requests' : 'Page views', number_format_i18n($total));
    sa_analytics_stat_card($tab === 'bots' ? 'Distinct bots' : 'Unique visitors (approx.)', number_format_i18n($uniq));
    echo '</div>';

    // Per-day chart
    $perday = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(ts) d, COUNT(*) c FROM {$table} WHERE is_bot = %d AND ts >= %s GROUP BY DATE(ts) ORDER BY d ASC",
        $is_bot, $since
    ), ARRAY_A);
    sa_analytics_bar_chart($perday, $days);

    if ($tab === 'bots') {
        $byco = $wpdb->get_results($wpdb->prepare(
            "SELECT company, category, COUNT(*) c, COUNT(DISTINCT uri) pages FROM {$table} WHERE is_bot = 1 AND ts >= %s GROUP BY company, category ORDER BY c DESC LIMIT 30",
            $since
        ), ARRAY_A);
        echo '<h2>By company</h2>';
        sa_analytics_table_render(['Company', 'Type', 'Requests', 'Pages touched'], array_map(static fn($x) => [
            esc_html($x['company'] !== '' ? $x['company'] : 'Other'),
            esc_html(sa_analytics_category_label($x['category'])),
            number_format_i18n((int) $x['c']),
            number_format_i18n((int) $x['pages']),
        ], $byco));
    }

    $pages = $wpdb->get_results($wpdb->prepare(
        "SELECT uri, COUNT(*) c FROM {$table} WHERE is_bot = %d AND ts >= %s GROUP BY uri ORDER BY c DESC LIMIT 20",
        $is_bot, $since
    ), ARRAY_A);
    echo '<h2>' . ($tab === 'bots' ? 'Pages bots requested' : 'Top pages') . '</h2>';
    sa_analytics_table_render(['Page', 'Hits'], array_map(static fn($x) => [
        '<a href="' . esc_url(home_url($x['uri'])) . '" target="_blank" rel="noopener">' . esc_html($x['uri']) . '</a>',
        number_format_i18n((int) $x['c']),
    ], $pages));

    if ($tab === 'visitors') {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $refs = $wpdb->get_results($wpdb->prepare(
            "SELECT referrer, COUNT(*) c FROM {$table} WHERE is_bot = 0 AND ts >= %s AND referrer <> '' AND referrer NOT LIKE %s GROUP BY referrer ORDER BY c DESC LIMIT 15",
            $since, '%' . $wpdb->esc_like($host) . '%'
        ), ARRAY_A);
        echo '<h2>Top referrers</h2>';
        sa_analytics_table_render(['Referrer', 'Visits'], array_map(static fn($x) => [
            '<a href="' . esc_url($x['referrer']) . '" target="_blank" rel="noopener">' . esc_html($x['referrer']) . '</a>',
            number_format_i18n((int) $x['c']),
        ], $refs));
    }

    $recent = $wpdb->get_results($wpdb->prepare(
        "SELECT ts, uri, referrer, ua, company, crawler FROM {$table} WHERE is_bot = %d ORDER BY ts DESC LIMIT 50",
        $is_bot
    ), ARRAY_A);
    echo '<h2>Latest ' . ($tab === 'bots' ? 'bot hits' : 'visits') . '</h2>';
    sa_analytics_table_render(['Time (UTC)', 'Page', $tab === 'bots' ? 'Crawler' : 'Referrer', 'User agent'], array_map(static function ($x) use ($tab) {
        $mid = $tab === 'bots'
            ? esc_html(trim(($x['company'] !== '' ? $x['company'] . ' / ' : '') . $x['crawler']) ?: 'bot')
            : ($x['referrer'] !== '' ? '<a href="' . esc_url($x['referrer']) . '" target="_blank" rel="noopener">' . esc_html(wp_parse_url($x['referrer'], PHP_URL_HOST) ?: $x['referrer']) . '</a>' : '<span style="color:#999">direct</span>');
        return [esc_html($x['ts']), esc_html($x['uri']), $mid, '<span style="color:#777;font-size:11px">' . esc_html(substr($x['ua'], 0, 90)) . '</span>'];
    }, $recent));

    echo '</div>';
}

function sa_analytics_category_label(string $c): string
{
    return [
        'answer_fetch' => 'AI assistant (answering a user)',
        'training'     => 'AI training crawl',
        'search_index' => 'Search engine indexing',
        'ai_crawler'   => 'AI crawler',
        'bot'          => 'Other bot',
    ][$c] ?? $c;
}

function sa_analytics_stat_card(string $label, string $value): void
{
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:14px 20px;min-width:180px">';
    echo '<div style="font-size:26px;font-weight:600;line-height:1.2">' . esc_html($value) . '</div>';
    echo '<div style="color:#646970">' . esc_html($label) . '</div></div>';
}

function sa_analytics_bar_chart(array $perday, int $days): void
{
    $byDay = [];
    foreach ($perday as $row) {
        $byDay[$row['d']] = (int) $row['c'];
    }
    $max = max(1, $byDay ? max($byDay) : 1);
    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px 20px;max-width:900px">';
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
        $c = $byDay[$d] ?? 0;
        $w = (int) round($c / $max * 100);
        echo '<div style="display:flex;align-items:center;gap:10px;margin:3px 0;font-size:12px">';
        echo '<span style="width:80px;color:#646970">' . esc_html(gmdate('D j M', strtotime($d))) . '</span>';
        echo '<span style="background:#2271b1;height:14px;width:' . max(1, $w) . '%;min-width:2px;border-radius:2px"></span>';
        echo '<span style="color:#1d2327">' . number_format_i18n($c) . '</span></div>';
    }
    echo '</div>';
}

function sa_analytics_table_render(array $head, array $rows): void
{
    echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
    foreach ($head as $h) {
        echo '<th>' . esc_html($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="' . count($head) . '" style="color:#999">No data yet for this range.</td></tr>';
    }
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . $cell . '</td>'; // cells are pre-escaped by callers
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}
