<?php
declare(strict_types=1);
/**
 * Fargo Courier (Wells Fargo Ltd Kenya's courier arm) shipment tracking.
 *
 * Source: Fargo's own public tracking page. fargocourier.co.ke/track-your-shipment/ posts the
 * waybill to /waybill-viewer/, which embeds portal.fargocourier.co.ke/track?blank=1&wayBillNumber=X.
 * Fargo publishes no developer API. We read that same public page server-side (no login, no
 * captcha), cache 20 min, rate-limit, and only show events actually parsed from Fargo's HTML.
 * Anything else falls back to "Tracking updates soon" + a link to Fargo's official page.
 */

if (!defined('ABSPATH')) {
    exit;
}

const SA_FT_CACHE_TTL = 1200; // 20 min
const SA_FT_FAIL_TTL = 600;   // 10 min for failures
const SA_FT_GLOBAL_PER_5MIN = 40;
const SA_FT_IP_PER_MIN = 8;

function sa_ft_clean_waybill(string $wb): string
{
    $wb = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $wb) ?? '');
    return (strlen($wb) >= 4 && strlen($wb) <= 30) ? $wb : '';
}

function sa_ft_official_url(string $wb): string
{
    return 'https://www.fargocourier.co.ke/waybill-viewer/?wayBill=' . rawurlencode($wb);
}

function sa_ft_our_url(string $wb): string
{
    return add_query_arg('waybill', rawurlencode($wb), home_url('/track-order/'));
}

/** Simple counters in transients. Returns false when limit exceeded. */
function sa_ft_rate_ok(string $key, int $limit, int $window): bool
{
    $k = 'sa_ft_rl_' . md5($key);
    $n = (int) get_transient($k);
    if ($n >= $limit) {
        return false;
    }
    set_transient($k, $n + 1, $window);
    return true;
}

/**
 * Parse Fargo's portal HTML into events. Pure function (unit-tested offline).
 * Returns ['status'=>string, 'events'=>[['time'=>string,'ts'=>int|null,'status'=>string,'location'=>string]], 'meta'=>[label=>value]]
 */
function sa_ft_parse(string $html): array
{
    $out = ['status' => '', 'events' => [], 'meta' => []];
    if (trim($html) === '' || stripos($html, 'could not complete your request') !== false) {
        return $out;
    }
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($dom);
    foreach ($xp->query('//script|//style|//noscript') as $n) {
        $n->parentNode->removeChild($n);
    }
    $txt = static fn($n): string => trim(preg_replace('/\s+/u', ' ', (string) $n->textContent) ?? '');
    $is_date = static function (string $s): bool {
        return (bool) preg_match('/\b(\d{1,4}[\/\-.]\d{1,2}[\/\-.]\d{2,4}|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{2,4}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4})\b/', $s);
    };

    foreach ($xp->query('//table') as $table) {
        $heads = [];
        foreach ($xp->query('.//th', $table) as $th) {
            $heads[] = strtolower($txt($th));
        }
        $rows = $xp->query('.//tr', $table);
        foreach ($rows as $tr) {
            $cells = [];
            foreach ($xp->query('./td', $tr) as $td) {
                $cells[] = $txt($td);
            }
            if (!$cells) {
                continue;
            }
            // Key/value table (2 cells, first is a label).
            if (count($cells) === 2 && !$is_date($cells[0]) && strlen($cells[0]) < 40 && !$heads) {
                $out['meta'][rtrim($cells[0], ': ')] = $cells[1];
                continue;
            }
            $ev = ['time' => '', 'ts' => null, 'status' => '', 'location' => ''];
            foreach ($cells as $i => $c) {
                $h = $heads[$i] ?? '';
                if ($c === '') {
                    continue;
                }
                if ($ev['time'] === '' && (preg_match('/date|time/', $h) || (!$h && $is_date($c)))) {
                    $ev['time'] = $c;
                } elseif (preg_match('/locat|branch|depot|station|town|city/', $h)) {
                    $ev['location'] = $ev['location'] ? $ev['location'] . ', ' . $c : $c;
                } elseif (preg_match('/status|event|activity|descr|remark|action|scan/', $h) || ($ev['status'] === '' && !$h)) {
                    $ev['status'] = $ev['status'] ? $ev['status'] . ' — ' . $c : $c;
                }
            }
            // Only keep rows that are real dated events with a description.
            if ($ev['time'] !== '' && $ev['status'] !== '' && $is_date($ev['time'])) {
                $t = strtotime(str_replace('/', '-', $ev['time']));
                $ev['ts'] = $t ?: null;
                $out['events'][] = $ev;
            }
        }
    }
    foreach ($out['meta'] as $k => $v) {
        if (preg_match('/status/i', (string) $k) && $v !== '') {
            $out['status'] = $v;
        }
    }
    if (count(array_filter(array_column($out['events'], 'ts'))) === count($out['events'])) {
        usort($out['events'], static fn($a, $b) => $b['ts'] <=> $a['ts']);
    }
    if ($out['status'] === '' && $out['events']) {
        $out['status'] = $out['events'][0]['status'];
    }
    return $out;
}

/** Fetch (cached) tracking for a waybill. Never throws, never returns raw errors. */
function sa_fargo_track(string $wb, bool $force = false): array
{
    $wb = sa_ft_clean_waybill($wb);
    $base = ['ok' => false, 'waybill' => $wb, 'official_url' => $wb ? sa_ft_official_url($wb) : 'https://www.fargocourier.co.ke/track-your-shipment/', 'status' => '', 'events' => [], 'meta' => [], 'checked' => 0];
    if ($wb === '') {
        return $base;
    }
    $ck = 'sa_ft_' . md5($wb);
    $hit = get_transient($ck);
    if (!$force && is_array($hit)) {
        return $hit + ['cached' => true];
    }
    if (!sa_ft_rate_ok('global', SA_FT_GLOBAL_PER_5MIN, 300)) {
        return is_array($hit) ? $hit : $base;
    }
    $r = wp_remote_get('https://portal.fargocourier.co.ke/track?blank=1&wayBillNumber=' . rawurlencode($wb), [
        'timeout' => 12,
        'redirection' => 2,
        'user-agent' => 'SupremeAutoparts-OrderTracking/1.0 (+' . home_url('/') . ')',
        'headers' => ['Referer' => sa_ft_official_url($wb)],
    ]);
    $res = $base;
    $res['checked'] = time();
    $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
    if ($code === 200) {
        $p = sa_ft_parse((string) wp_remote_retrieve_body($r));
        if ($p['events'] || $p['status'] !== '') {
            $res = array_merge($res, $p, ['ok' => true]);
        }
    }
    set_transient($ck, $res, $res['ok'] ? SA_FT_CACHE_TTL : SA_FT_FAIL_TTL);
    if (!$res['ok'] && function_exists('sa_core_audit_log')) {
        sa_core_audit_log('fargo.track_miss', ['waybill' => $wb, 'http' => $code], 'info');
    }
    return $res;
}

function sa_ft_panel_html(string $wb, ?WC_Order $o = null): string
{
    $wb = sa_ft_clean_waybill($wb);
    if ($wb === '') {
        return '';
    }
    $t = sa_fargo_track($wb);
    $h = '<section class="sa-ft"><div class="sa-ft__head"><div><span class="sa-ft__eyebrow">Fargo Courier tracking</span>'
        . '<h3 class="sa-ft__wb">Waybill ' . esc_html($wb) . '</h3></div>'
        . ($t['ok'] && $t['status'] !== '' ? '<span class="sa-ft__pill">' . esc_html($t['status']) . '</span>' : '') . '</div>';
    if ($t['ok'] && $t['events']) {
        $h .= '<ol class="sa-ft__list">';
        foreach ($t['events'] as $i => $e) {
            $h .= '<li class="' . ($i === 0 ? 'is-latest' : '') . '"><span class="sa-ft__dot"></span><div><strong>' . esc_html($e['status']) . '</strong>'
                . '<div class="sa-ft__sub">' . esc_html(trim($e['time'] . ($e['location'] ? ' · ' . $e['location'] : ''))) . '</div></div></li>';
        }
        $h .= '</ol>';
    } elseif ($t['ok']) {
        $h .= '<p class="sa-ft__msg">Status from Fargo: ' . esc_html($t['status']) . '</p>';
    } else {
        $h .= '<p class="sa-ft__msg"><strong>Tracking updates soon.</strong> Fargo hasn\'t published scan events for this waybill yet. You can also check it on Fargo\'s official tracking page.</p>';
    }
    $h .= '<p class="sa-ft__foot"><a class="sa-ft__btn" href="' . esc_url($t['official_url']) . '" target="_blank" rel="noopener">View on Fargo Courier</a>'
        . ($t['checked'] ? '<small>Checked ' . esc_html(wp_date('j M, g:i A', (int) $t['checked'])) . ' EAT</small>' : '') . '</p></section>';
    return sa_ft_css() . $h;
}

function sa_ft_css(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<style>.sa-ft{border:1px solid #e6e1d3;border-radius:14px;padding:20px;margin:0 0 24px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}'
        . '.sa-ft__head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}'
        . '.sa-ft__eyebrow{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:#9a7b2f;font-weight:700}'
        . '.sa-ft__wb{margin:2px 0 0;font-size:1.15rem;color:#111}.sa-ft__pill{background:#111;color:#f3d27a;border-radius:999px;padding:6px 12px;font-size:.8rem;font-weight:700}'
        . '.sa-ft__list{list-style:none;margin:0;padding:0 0 0 6px;border-left:2px solid #eee}.sa-ft__list li{position:relative;display:flex;gap:10px;padding:0 0 14px 14px}'
        . '.sa-ft__dot{position:absolute;left:-7px;top:5px;width:12px;height:12px;border-radius:50%;background:#cfcfcf}.sa-ft__list li.is-latest .sa-ft__dot{background:#c9a646;box-shadow:0 0 0 4px rgba(201,166,70,.2)}'
        . '.sa-ft__sub{color:#666;font-size:.86rem}.sa-ft__msg{color:#333;margin:0 0 12px}.sa-ft__foot{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:8px 0 0}'
        . '.sa-ft__foot small{color:#888}.sa-ft__btn{display:inline-block;background:#111;color:#fff!important;padding:10px 16px;border-radius:8px;font-weight:700;text-decoration:none}'
        . '.sa-ft-form{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 20px}.sa-ft-form input{flex:1 1 220px;padding:12px;border:1px solid #ccc;border-radius:8px}'
        . '.sa-ft-form button{background:#111;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-weight:700}</style>';
}

function sa_ft_order_waybill(WC_Order $o): string
{
    return (string) $o->get_meta('_sa_courier') === 'fargo' ? sa_ft_clean_waybill((string) $o->get_meta('_sa_tracking_no')) : '';
}

/* Order view (My Account + /track-order/ result) and thank-you: show live panel after the timeline. */
add_action('woocommerce_view_order', static function ($order_id): void {
    $o = wc_get_order($order_id);
    if ($o instanceof WC_Order && ($wb = sa_ft_order_waybill($o))) {
        echo sa_ft_panel_html($wb, $o); // phpcs:ignore
    }
}, 6);

/* /track-order/: add "track by Fargo waybill" form + result above Woo's order/email form. */
add_filter('do_shortcode_tag', static function ($out, $tag) {
    if ($tag !== 'woocommerce_order_tracking' || !is_string($out)) {
        return $out;
    }
    $wb = isset($_GET['waybill']) ? sa_ft_clean_waybill(sanitize_text_field(wp_unslash((string) $_GET['waybill']))) : '';
    $pre = sa_ft_css();
    if ($wb !== '') {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $pre .= sa_ft_rate_ok('ip' . $ip, SA_FT_IP_PER_MIN, 60) ? sa_ft_panel_html($wb)
            : '<p class="sa-ft__msg">Too many lookups. Please wait a minute, or <a href="' . esc_url(sa_ft_official_url($wb)) . '" target="_blank" rel="noopener">check on Fargo Courier</a>.</p>';
    }
    $pre .= '<form class="sa-ft-form" method="get" action="' . esc_url(home_url('/track-order/')) . '"><label class="screen-reader-text" for="sa-ft-wb">Fargo waybill number</label>'
        . '<input id="sa-ft-wb" name="waybill" placeholder="Fargo Courier waybill number" value="' . esc_attr($wb) . '" maxlength="30" required><button type="submit">Track waybill</button></form>'
        . '<p style="margin:-8px 0 18px;color:#666;font-size:.9rem">Or track with your order number and email:</p>';
    return $pre . $out;
}, 20, 2);

/* Public JSON (rate limited) for the panel / QA. */
add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/fargo-track', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static function (WP_REST_Request $r) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (!sa_ft_rate_ok('ip' . $ip, SA_FT_IP_PER_MIN, 60)) {
                return new WP_REST_Response(['ok' => false, 'message' => 'Tracking updates soon.'], 429);
            }
            $t = sa_fargo_track((string) $r->get_param('waybill'));
            unset($t['meta']);
            if (!$t['ok']) {
                $t['message'] = 'Tracking updates soon.';
            }
            return new WP_REST_Response($t, 200);
        },
    ]);
});
