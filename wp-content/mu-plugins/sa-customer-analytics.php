<?php
/**
 * Plugin Name: SA Customer Analytics (per-customer deep insights)
 * Description: Customer-level analytics in wp-admin (Analytics > Customers): per-customer orders,
 *              lifetime value, repeat rate, first-touch source, products/categories, cart activity,
 *              location and device, plus visits & pages viewed (from the site's own Matomo tables for
 *              logged-in customers and the server-side event log matched by IP hash for guests).
 *              Admin-only. No card data, no passwords. All data from this site's own database.
 * Version: 1.0.0
 * Author: Supreme Autoparts
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_CUST_ANALYTICS_VERSION = '1.0.0';

/* ----------------------------------------------------------------------- */
/* First-touch / last-touch capture (consent-aware, set client-side)        */
/* ----------------------------------------------------------------------- */

add_action('wp_footer', static function (): void {
    if (is_admin() || (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options'))) {
        return;
    }
    ?>
<script>
(function(){
  "use strict";
  function gc(n){var m=document.cookie.match(new RegExp("(?:^|; )"+n+"=([^;]*)"));return m?decodeURIComponent(m[1]):"";}
  function sc(n,v,days){var e=new Date(Date.now()+days*864e5);document.cookie=n+"="+encodeURIComponent(v)+";expires="+e.toUTCString()+";path=/;SameSite=Lax";}
  // Consent: UK/EU/CH need an accepted banner before analytics cookies are written.
  var EU="AT BE BG HR CY CZ DK EE FI FR DE GR HU IS IE IT LV LI LT LU MT NL NO PL PT RO SK SI ES SE GB CH".split(" ");
  var cc=gc("sa_geo_cc").toUpperCase();
  if(EU.indexOf(cc)!==-1 && gc("sa_tconsent")!=="1"){return;}
  try{
    var ref=document.referrer||"";
    var ext=ref && ref.indexOf(location.host)===-1;
    var q=new URLSearchParams(location.search);
    var touch={ref:ext?ref.slice(0,300):"",land:(location.pathname+location.search).slice(0,300),src:q.get("utm_source")||"",med:q.get("utm_medium")||"",cmp:q.get("utm_campaign")||"",ts:Math.floor(Date.now()/1000)};
    if(!gc("sa_ft")){if(!ext&&!touch.src){touch.ref="direct";}sc("sa_ft",JSON.stringify(touch),365);}
    if(ext||touch.src){sc("sa_lt",JSON.stringify(touch),30);}
  }catch(e){}
})();
</script>
<?php
}, 30);

/* ----------------------------------------------------------------------- */
/* Order meta: attribution + device summary                                 */
/* ----------------------------------------------------------------------- */

function sa_cust_device_summary(string $ua): string
{
    $ua === '' && $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $type = 'desktop';
    if (preg_match('~Mobile|Android|iPhone|iPod~i', $ua)) {
        $type = 'mobile';
    } elseif (preg_match('~iPad|Tablet~i', $ua)) {
        $type = 'tablet';
    }
    $browser = 'other';
    foreach (['Edg' => 'Edge', 'Chrome' => 'Chrome', 'Safari' => 'Safari', 'Firefox' => 'Firefox', 'OPR' => 'Opera', 'SamsungBrowser' => 'Samsung Internet'] as $needle => $label) {
        if (stripos($ua, $needle) !== false) { $browser = $label; break; }
    }
    $os = 'other';
    foreach (['Windows' => 'Windows', 'Mac OS' => 'macOS', 'Android' => 'Android', 'iPhone|iPad' => 'iOS', 'Linux' => 'Linux'] as $needle => $label) {
        if (preg_match('~' . $needle . '~i', $ua)) { $os = $label; break; }
    }
    return $type . ' / ' . $browser . ' / ' . $os;
}

function sa_cust_capture_order_meta($order): void
{
    if (!is_object($order) || !method_exists($order, 'get_id')) {
        return;
    }
    if ($order->get_meta('_sa_first_touch') !== '' && $order->get_meta('_sa_first_touch') !== null) {
        return; // already captured
    }
    $decode = static function (string $raw): string {
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return '';
        }
        $parts = [];
        if (!empty($d['src'])) { $parts[] = 'utm:' . $d['src'] . (!empty($d['med']) ? '/' . $d['med'] : '') . (!empty($d['cmp']) ? '/' . $d['cmp'] : ''); }
        if (!empty($d['ref'])) { $parts[] = (string) $d['ref']; }
        if (!empty($d['land'])) { $parts[] = 'landed ' . $d['land']; }
        if (!empty($d['ts']))  { $parts[] = gmdate('Y-m-d H:i', (int) $d['ts']) . ' UTC'; }
        return implode(' | ', array_map(static function ($p) { return substr((string) $p, 0, 300); }, $parts));
    };
    $ft = isset($_COOKIE['sa_ft']) ? $decode((string) wp_unslash($_COOKIE['sa_ft'])) : '';
    $lt = isset($_COOKIE['sa_lt']) ? $decode((string) wp_unslash($_COOKIE['sa_lt'])) : '';
    if ($ft !== '') { $order->update_meta_data('_sa_first_touch', $ft); }
    if ($lt !== '') { $order->update_meta_data('_sa_last_touch', $lt); }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    // Skip UA recorded from gateway/server-side order creation (webhooks) - that is not the customer's device.
    if ($ua !== '' && !preg_match('~curl|python|wget|webhook|whop|pesapal|transact~i', $ua)) {
        $order->update_meta_data('_sa_device', sa_cust_device_summary($ua));
    }
    $order->save_meta_data();
}

add_action('woocommerce_checkout_order_created', 'sa_cust_capture_order_meta', 20);
add_action('woocommerce_new_order', static function ($order_id, $order = null): void {
    try {
        $o = $order instanceof WC_Order ? $order : wc_get_order((int) $order_id);
        if ($o) {
            sa_cust_capture_order_meta($o);
        }
    } catch (\Throwable $e) {
        error_log('[sa-cust-analytics] order meta: ' . $e->getMessage());
    }
}, 20, 2);

/* ----------------------------------------------------------------------- */
/* Admin UI                                                                 */
/* ----------------------------------------------------------------------- */

add_action('admin_menu', static function (): void {
    add_submenu_page(
        'sa-analytics',
        'Customers',
        'Customers',
        'manage_options',
        'sa-customers',
        'sa_cust_render_admin'
    );
}, 20);

/** Money statuses used for LTV / repeat-rate math. */
function sa_cust_money_statuses(): array
{
    return ['wc-completed', 'wc-processing', 'wc-on-hold'];
}

/** Aggregate all orders (post-type shop_order, works with HPOS off) keyed by billing email. Cached 5 min. */
function sa_cust_index(): array
{
    $cached = get_transient('sa_cust_index');
    if (is_array($cached)) {
        return $cached;
    }
    $index = ['customers' => [], 'total_orders' => 0];
    $page = 1;
    while ($page <= 50) {
        $orders = wc_get_orders([
            'limit'   => 200,
            'page'    => $page,
            'type'    => 'shop_order',
            'status'  => array_keys(wc_get_order_statuses()),
            'orderby' => 'ID',
            'order'   => 'ASC',
            'return'  => 'objects',
        ]);
        if (empty($orders)) {
            break;
        }
        foreach ($orders as $o) {
            if (!($o instanceof WC_Order)) { continue; }
            $email = strtolower(trim((string) $o->get_billing_email()));
            if ($email === '') {
                $email = 'guest-order-' . $o->get_id();
            }
            $c = &$index['customers'][$email];
            if (!isset($c['orders'])) {
                $c = [
                    'orders' => 0, 'revenue_orders' => 0, 'spent' => 0.0,
                    'first' => null, 'last' => null, 'name' => '', 'user_id' => 0,
                    'country' => '', 'city' => '', 'device' => '', 'first_touch' => '',
                    'cats' => [], 'products' => [],
                ];
            }
            $c['orders']++;
            $index['total_orders']++;
            $status = 'wc-' . $o->get_status();
            $date   = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
            if ($c['first'] === null || $date < $c['first']) { $c['first'] = $date; }
            if ($c['last'] === null || $date > $c['last'])   { $c['last'] = $date; }
            if (in_array($status, sa_cust_money_statuses(), true)) {
                $c['revenue_orders']++;
                $c['spent'] += (float) $o->get_total();
            }
            if ($c['name'] === '') { $c['name'] = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()); }
            $uid = (int) $o->get_customer_id();
            if ($uid > 0) { $c['user_id'] = $uid; }
            if ($o->get_billing_country()) { $c['country'] = $o->get_billing_country(); }
            if ($o->get_billing_city())    { $c['city'] = $o->get_billing_city(); }
            $dev = (string) $o->get_meta('_sa_device');
            if ($dev !== '') { $c['device'] = $dev; }
            $ft = (string) $o->get_meta('_sa_first_touch');
            if ($ft !== '' && $c['first_touch'] === '') { $c['first_touch'] = $ft; }
            foreach ($o->get_items() as $item) {
                $pname = $item->get_name();
                $c['products'][$pname] = ($c['products'][$pname] ?? 0) + $item->get_quantity();
                $pid = $item->get_product_id();
                if ($pid) {
                    $terms = get_the_terms($pid, 'product_cat');
                    if (is_array($terms)) {
                        foreach ($terms as $t) {
                            if (!empty($t->parent)) { continue; }
                            $c['cats'][$t->name] = ($c['cats'][$t->name] ?? 0) + $item->get_quantity();
                        }
                    }
                }
            }
            unset($c);
        }
        if (count($orders) < 200) {
            break;
        }
        $page++;
    }
    set_transient('sa_cust_index', $index, 300);
    return $index;
}

function sa_cust_matomo_cols(string $table): array
{
    global $wpdb;
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return $cache[$table] = [];
    }
    $cols = [];
    foreach ((array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0) as $c) {
        $cols[$c] = true;
    }
    return $cache[$table] = $cols;
}

/** Visits + pages for a customer from Matomo raw tables (by user_id and/or IP hash via our event log). */
function sa_cust_visits(int $user_id, array $ips): array
{
    global $wpdb;
    $out = ['visits' => [], 'pages' => [], 'note' => ''];
    $p    = $wpdb->prefix . 'matomo_';
    $vcol = sa_cust_matomo_cols($p . 'log_visit');
    if (!$vcol) {
        $out['note'] = 'Matomo tables not found yet.';
        return $out;
    }
    $has_uid = isset($vcol['user_id']);
    $sel = ['idvisit', 'visit_last_action_time'];
    foreach (['visit_total_actions','visit_total_time','config_browser_name','config_os','config_device_brand','config_device_model','config_device_type','config_resolution','location_country','location_city','referer_type','referer_name','referer_url'] as $c) {
        if (isset($vcol[$c])) { $sel[] = $c; }
    }
    $where = [];
    $args  = [];
    if ($user_id > 0 && $has_uid) {
        $where[] = 'user_id = %s';
        $args[]  = (string) $user_id;
    }
    // Match guest visits by truncated IP (Matomo stores anonymised IP in location_ip).
    foreach ($ips as $ip) {
        $packed = @inet_pton($ip);
        if ($packed === false) { continue; }
        if (isset($vcol['location_ip'])) {
            // Matomo anonymises the last byte(s); compare the first 3 bytes of IPv4 / first 6 of IPv6.
            $len    = strlen($packed);
            $keep   = $len === 4 ? 3 : 6;
            $where[] = 'SUBSTRING(location_ip,1,' . $keep . ') = %s';
            $args[]  = substr($packed, 0, $keep);
        }
    }
    if (!$where) {
        $out['note'] = $has_uid ? 'No user id or IP to match.' : 'Matomo user_id column not present yet (enable user id tracking).';
        return $out;
    }
    $sql = 'SELECT ' . implode(',', $sel) . " FROM {$p}log_visit WHERE (" . implode(' OR ', $where) . ') ORDER BY visit_last_action_time DESC LIMIT 60';
    $out['visits'] = (array) $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

    $lcol = sa_cust_matomo_cols($p . 'log_link_visit_action');
    $acol = sa_cust_matomo_cols($p . 'log_action');
    if ($lcol && $acol && $out['visits']) {
        $ids = array_map(static fn($v) => (int) $v['idvisit'], $out['visits']);
        $in  = implode(',', array_fill(0, count($ids), '%d'));
        $out['pages'] = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT l.idvisit, a.name AS url, COUNT(*) AS views FROM {$p}log_link_visit_action l
             JOIN {$p}log_action a ON a.idaction = l.idaction_url
             WHERE l.idvisit IN ($in) GROUP BY l.idvisit, a.name ORDER BY views DESC LIMIT 200",
            $ids
        ), ARRAY_A);
    }
    return $out;
}

/** Page hits from our own server-side log, matched by IP hash (works for guests). */
function sa_cust_event_hits(array $ips): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'sa_analytics_events';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table || !$ips) {
        return [];
    }
    $salt   = defined('AUTH_SALT') ? AUTH_SALT : 'sa';
    $hashes = [];
    foreach ($ips as $ip) {
        $hashes[] = hash('sha256', $ip . '|' . $salt);
    }
    $in = implode(',', array_fill(0, count($hashes), '%s'));
    return (array) $wpdb->get_results($wpdb->prepare(
        "SELECT ts, uri, referrer, is_bot FROM {$table} WHERE ip_hash IN ($in) AND is_bot = 0 ORDER BY ts DESC LIMIT 100",
        $hashes
    ), ARRAY_A);
}

function sa_cust_render_admin(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Nope');
    }
    $who = isset($_GET['customer']) ? sanitize_text_field((string) wp_unslash($_GET['customer'])) : '';
    echo '<div class="wrap"><h1>Customer Analytics</h1>';
    echo '<p style="color:#666">Everything below comes from this store\'s own database (orders + on-site analytics). Admin-only. No card data is ever stored.</p>';
    try {
        $index = sa_cust_index();
        if ($who !== '') {
            sa_cust_render_detail($index, $who);
        } else {
            sa_cust_render_list($index);
        }
    } catch (\Throwable $e) {
        echo '<div class="notice notice-error"><p>Customer analytics error: ' . esc_html($e->getMessage()) . '</p></div>';
    }
    echo '</div>';
}

function sa_cust_render_list(array $index): void
{
    $customers = $index['customers'];
    $buyers    = array_filter($customers, static fn($c) => $c['revenue_orders'] > 0);
    $repeat    = array_filter($buyers, static fn($c) => $c['revenue_orders'] > 1);
    $total_spent = array_sum(array_map(static fn($c) => $c['spent'], $buyers));
    $n = count($buyers);
    $cards = [
        'Customers with orders' => number_format($n),
        'Repeat customers'      => number_format(count($repeat)) . ($n ? ' (' . round(count($repeat) / $n * 100, 1) . '%)' : ''),
        'Avg lifetime value'    => $n ? wc_price($total_spent / $n) : '-',
        'Avg orders per customer' => $n ? round(array_sum(array_map(static fn($c) => $c['revenue_orders'], $buyers)) / $n, 2) : '-',
        'Total orders'          => number_format($index['total_orders']),
    ];
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0">';
    foreach ($cards as $label => $val) {
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 18px;min-width:150px"><div style="font-size:12px;color:#666">' . esc_html($label) . '</div><div style="font-size:20px;font-weight:700">' . wp_kses_post((string) $val) . '</div></div>';
    }
    echo '</div>';

    $q = isset($_GET['s']) ? strtolower(sanitize_text_field((string) wp_unslash($_GET['s']))) : '';
    $rows = $buyers;
    if ($q !== '') {
        $rows = array_filter($rows, static fn($c, $email) =>
            str_contains(strtolower((string) $c['name']), $q) || str_contains((string) $email, $q), ARRAY_FILTER_USE_BOTH);
    }
    uasort($rows, static fn($a, $b) => $b['spent'] <=> $a['spent']);
    $page    = max(1, (int) ($_GET['paged'] ?? 1));
    $per     = 25;
    $pages   = max(1, (int) ceil(count($rows) / $per));
    $page    = min($page, $pages);
    $slice   = array_slice($rows, ($page - 1) * $per, $per, true);

    echo '<form method="get" style="margin:8px 0"><input type="hidden" name="page" value="sa-customers"><input type="search" name="s" value="' . esc_attr($q) . '" placeholder="Search name or email" class="regular-text"> <button class="button">Search</button></form>';
    echo '<table class="widefat striped"><thead><tr><th>Customer</th><th>Email</th><th>Orders</th><th>Lifetime value</th><th>First order</th><th>Last order</th><th>First source</th><th>Location</th><th>Device</th></tr></thead><tbody>';
    foreach ($slice as $email => $c) {
        $url = admin_url('admin.php?page=sa-customers&customer=' . rawurlencode((string) $email));
        echo '<tr><td><a href="' . esc_url($url) . '"><strong>' . esc_html($c['name'] !== '' ? $c['name'] : '(no name)') . '</strong></a></td>'
            . '<td>' . esc_html((string) $email) . '</td>'
            . '<td>' . (int) $c['revenue_orders'] . '</td>'
            . '<td>' . wp_kses_post(wc_price($c['spent'])) . '</td>'
            . '<td>' . esc_html($c['first'] ? date_i18n('Y-m-d', $c['first']) : '-') . '</td>'
            . '<td>' . esc_html($c['last'] ? date_i18n('Y-m-d', $c['last']) : '-') . '</td>'
            . '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis">' . esc_html($c['first_touch'] !== '' ? $c['first_touch'] : '-') . '</td>'
            . '<td>' . esc_html(trim($c['city'] . ' ' . $c['country']) !== '' ? trim($c['city'] . ', ' . $c['country']) : '-') . '</td>'
            . '<td>' . esc_html($c['device'] !== '' ? $c['device'] : '-') . '</td></tr>';
    }
    if (!$slice) {
        echo '<tr><td colspan="9">No customers found.</td></tr>';
    }
    echo '</tbody></table>';
    if ($pages > 1) {
        echo '<p style="margin-top:8px">';
        for ($i = 1; $i <= $pages; $i++) {
            $cls = $i === $page ? 'button button-primary' : 'button';
            echo ' <a class="' . $cls . '" href="' . esc_url(admin_url('admin.php?page=sa-customers&paged=' . $i . ($q !== '' ? '&s=' . rawurlencode($q) : ''))) . '">' . $i . '</a>';
        }
        echo '</p>';
    }
}

function sa_cust_render_detail(array $index, string $who): void
{
    $c = $index['customers'][$who] ?? null;
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=sa-customers')) . '">&larr; All customers</a></p>';
    if (!$c) {
        echo '<p>Customer not found.</p>';
        return;
    }
    $user   = $c['user_id'] > 0 ? get_userdata($c['user_id']) : get_user_by('email', $who);
    $uid    = $user ? (int) $user->ID : 0;

    echo '<h2>' . esc_html($c['name'] !== '' ? $c['name'] : $who) . '</h2>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:10px 0">';
    $cards = [
        'Lifetime value'   => wc_price($c['spent']),
        'Orders'           => (string) $c['orders'] . ' (' . $c['revenue_orders'] . ' paid)',
        'Avg order value'  => $c['revenue_orders'] ? wc_price($c['spent'] / $c['revenue_orders']) : '-',
        'First order'      => $c['first'] ? date_i18n('Y-m-d', $c['first']) : '-',
        'Last order'       => $c['last'] ? date_i18n('Y-m-d', $c['last']) : '-',
        'Registered'       => $user ? 'Yes (' . esc_html($user->user_login) . ')' : 'Guest',
    ];
    foreach ($cards as $label => $val) {
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;min-width:130px"><div style="font-size:12px;color:#666">' . esc_html($label) . '</div><div style="font-size:17px;font-weight:700">' . wp_kses_post((string) $val) . '</div></div>';
    }
    echo '</div>';

    if ($c['first_touch'] !== '') {
        echo '<p><strong>First-touch source:</strong> ' . esc_html($c['first_touch']) . '</p>';
    }

    // Orders + addresses + meta
    $orders = wc_get_orders([
        'billing_email' => $who,
        'limit'         => 50,
        'type'          => 'shop_order',
        'orderby'       => 'date',
        'order'         => 'DESC',
        'status'        => array_keys(wc_get_order_statuses()),
    ]);
    $ips = [];
    echo '<h3>Orders</h3><table class="widefat striped"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th><th>Items</th><th>Ship to</th><th>Source</th><th>Device</th></tr></thead><tbody>';
    foreach ($orders as $o) {
        $ip = (string) $o->get_customer_ip_address();
        if ($ip !== '') { $ips[$ip] = true; }
        $items = [];
        foreach ($o->get_items() as $item) {
            $items[] = $item->get_quantity() . 'x ' . $item->get_name();
        }
        $src = (string) $o->get_meta('_sa_first_touch');
        $dev = (string) $o->get_meta('_sa_device');
        echo '<tr><td><a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html((string) $o->get_order_number()) . '</a></td>'
            . '<td>' . esc_html($o->get_date_created() ? $o->get_date_created()->date_i18n('Y-m-d H:i') : '-') . '</td>'
            . '<td>' . esc_html(wc_get_order_status_name($o->get_status())) . '</td>'
            . '<td>' . wp_kses_post($o->get_formatted_order_total()) . '</td>'
            . '<td style="max-width:320px">' . esc_html(implode('; ', array_slice($items, 0, 4))) . (count($items) > 4 ? ' ...' : '') . '</td>'
            . '<td>' . esc_html(trim($o->get_shipping_city() . ', ' . $o->get_shipping_country()) ?: '-') . '</td>'
            . '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis">' . esc_html($src !== '' ? $src : '-') . '</td>'
            . '<td>' . esc_html($dev !== '' ? $dev : '-') . '</td></tr>';
    }
    echo '</tbody></table>';

    // Top products / categories
    echo '<div style="display:flex;gap:24px;flex-wrap:wrap">';
    foreach (['Products bought' => $c['products'], 'Categories' => $c['cats']] as $title => $data) {
        arsort($data);
        echo '<div style="flex:1;min-width:260px"><h3>' . esc_html($title) . '</h3><table class="widefat striped"><tbody>';
        foreach (array_slice($data, 0, 10, true) as $name => $qty) {
            echo '<tr><td>' . esc_html((string) $name) . '</td><td style="width:60px;text-align:right">' . (int) $qty . '</td></tr>';
        }
        if (!$data) { echo '<tr><td>-</td></tr>'; }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // Visits & pages (Matomo raw tables + own event log by IP)
    $visits = sa_cust_visits($uid, array_keys($ips));
    echo '<h3>Visits' . ($visits['note'] !== '' ? ' <small style="color:#a00">(' . esc_html($visits['note']) . ')</small>' : '') . '</h3>';
    if ($visits['visits']) {
        echo '<table class="widefat striped"><thead><tr><th>Last action (UTC)</th><th>Pages</th><th>Time</th><th>Browser</th><th>OS</th><th>Device</th><th>Screen</th><th>Location</th><th>Source</th></tr></thead><tbody>';
        $rt = [1 => 'Direct', 2 => 'Search engine', 3 => 'Website', 4 => 'Campaign'];
        foreach ($visits['visits'] as $v) {
            $dev = trim(($v['config_device_brand'] ?? '') . ' ' . ($v['config_device_model'] ?? ''));
            echo '<tr><td>' . esc_html((string) $v['visit_last_action_time']) . '</td>'
                . '<td>' . esc_html((string) ($v['visit_total_actions'] ?? '-')) . '</td>'
                . '<td>' . esc_html(isset($v['visit_total_time']) ? gmdate('i\ms\s', (int) $v['visit_total_time']) : '-') . '</td>'
                . '<td>' . esc_html((string) ($v['config_browser_name'] ?? '-')) . '</td>'
                . '<td>' . esc_html((string) ($v['config_os'] ?? '-')) . '</td>'
                . '<td>' . esc_html($dev !== '' ? $dev : '-') . '</td>'
                . '<td>' . esc_html((string) ($v['config_resolution'] ?? '-')) . '</td>'
                . '<td>' . esc_html(trim(($v['location_city'] ?? '') . ' ' . ($v['location_country'] ?? '')) ?: '-') . '</td>'
                . '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis">' . esc_html(trim(($rt[(int) ($v['referer_type'] ?? 0)] ?? '') . ' ' . ($v['referer_name'] ?? '')) ?: '-') . '</td></tr>';
        }
        echo '</tbody></table>';

        if ($visits['pages']) {
            echo '<h3>Pages viewed</h3><table class="widefat striped"><thead><tr><th>Page</th><th style="width:80px">Views</th></tr></thead><tbody>';
            $byUrl = [];
            foreach ($visits['pages'] as $p) {
                $byUrl[$p['url']] = ($byUrl[$p['url']] ?? 0) + (int) $p['views'];
            }
            arsort($byUrl);
            foreach (array_slice($byUrl, 0, 30, true) as $url => $views) {
                echo '<tr><td style="word-break:break-all">' . esc_html((string) $url) . '</td><td>' . (int) $views . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    } else {
        echo '<p>No Matomo visits matched yet (visits appear once tracking has recorded this customer).</p>';
    }

    $hits = sa_cust_event_hits(array_keys($ips));
    if ($hits) {
        echo '<h3>Raw page hits (server log, matched by IP)</h3><table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>URL</th><th>Referrer</th></tr></thead><tbody>';
        foreach (array_slice($hits, 0, 50) as $h) {
            echo '<tr><td style="white-space:nowrap">' . esc_html((string) $h['ts']) . '</td><td style="word-break:break-all">' . esc_html((string) $h['uri']) . '</td><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis">' . esc_html((string) $h['referrer']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
