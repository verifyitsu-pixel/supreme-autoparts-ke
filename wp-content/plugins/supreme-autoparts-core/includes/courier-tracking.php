<?php
declare(strict_types=1);
/**
 * G4S + Speedaf shipment tracking, embedded on our own site (Rock 2026-10-17:
 * "doesn't take customer from my site"). Mirrors fargo-tracking.php: server-side
 * fetch from each courier's own public tracking system, 20 min cache, rate limits,
 * normalized events, panel on the order view and /track-order/. Anything unparsable
 * falls back to "Tracking updates soon" with the courier name + tracking number.
 *
 * Endpoints (both verified live 2026-10-17, no auth, same ones their own sites use):
 *   G4S:     GET https://g4scourier.logixerp.com/API/webservice/v2/MultipleWaybillTracking
 *              ?SecureKey=36C77BABF551458BB88AFD993CC0965F&WaybillNumber={waybill}  (JSON)
 *   Speedaf: POST https://speedaf.com/publicservice/v1/api/express/track/listExpressTrack
 *              {"mailNoList":["{waybill}"]}  (JSON, data[0].tracks[] = scan events)
 */

if (!defined('ABSPATH')) {
    exit;
}

const SA_CT_CACHE_TTL = 1200; // 20 min
const SA_CT_FAIL_TTL = 600;

function sa_ct_couriers(): array
{
    return [
        'g4s' => ['name' => 'G4S Courier', 'official' => 'https://www.g4s.com/en-ke/track-my-shipment'],
        'speedaf' => ['name' => 'Speedaf Courier', 'official' => 'https://www.speedaf.com/ke-en'],
    ];
}

function sa_ct_clean(string $courier, string $wb): array
{
    $c = sanitize_key($courier);
    if (!isset(sa_ct_couriers()[$c])) {
        $c = '';
    }
    $wb = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $wb) ?? '');
    if (strlen($wb) < 4 || strlen($wb) > 30) {
        $wb = '';
    }
    return [$c, $wb];
}

/** Normalize one decoded JSON tree into ['status'=>, 'events'=>[...]]. Defensive: key names guessed by pattern. */
function sa_ct_normalize($node): array
{
    $out = ['status' => '', 'events' => []];
    $walk = static function ($n) use (&$walk, &$out): void {
        if (!is_array($n)) {
            return;
        }
        $is_list = array_keys($n) === range(0, count($n) - 1) && $n !== [];
        if ($is_list) {
            foreach ($n as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $time = '';
                $status = '';
                $loc = '';
                foreach ($item as $k => $v) {
                    if (!is_scalar($v)) {
                        continue;
                    }
                    $kl = strtolower((string) $k);
                    $vs = trim((string) $v);
                    if ($vs === '') {
                        continue;
                    }
                    if ($time === '' && preg_match('/time|date/', $kl)) {
                        $time = $vs;
                    } elseif ($status === '' && preg_match('/desc|status|scan|content|remark|action|comment/', $kl)) {
                        $status = $vs;
                    } elseif ($loc === '' && preg_match('/site|city|location|network|branch|station/', $kl)) {
                        $loc = $vs;
                    }
                }
                $has_children = false;
                foreach ($item as $v) {
                    if (is_array($v)) {
                        $has_children = true;
                        break;
                    }
                }
                // A node with its own nested scan list is a wrapper (e.g. G4S waybillTrackDetails):
                // recurse into it instead of swallowing the scans as one status-only event.
                if ($status !== '' && !$has_children) {
                    $t = $time !== '' ? strtotime(str_replace('/', '-', $time)) : false;
                    $out['events'][] = ['time' => $time, 'ts' => $t ?: null, 'status' => $status, 'location' => $loc];
                } else {
                    $walk($item);
                }
            }
            return;
        }
        foreach ($n as $k => $v) {
            if (is_array($v)) {
                $walk($v);
            } elseif ($out['status'] === '' && is_scalar($v) && preg_match('/^(current)?(status|state)(desc)?$/i', (string) $k) && trim((string) $v) !== '') {
                $out['status'] = trim((string) $v);
            }
        }
    };
    $walk($node);
    // Newest first when every event has a timestamp.
    if ($out['events'] && count(array_filter(array_column($out['events'], 'ts'))) === count($out['events'])) {
        usort($out['events'], static fn($a, $b) => $b['ts'] <=> $a['ts']);
    }
    if ($out['status'] === '' && $out['events']) {
        $out['status'] = $out['events'][0]['status'];
    }
    return $out;
}

/** Fetch (cached) tracking for a G4S or Speedaf waybill. Never throws. */
function sa_ct_track(string $courier, string $wb, bool $force = false): array
{
    [$c, $wb] = sa_ct_clean($courier, $wb);
    $info = $c !== '' ? sa_ct_couriers()[$c] : ['name' => 'Courier', 'official' => ''];
    $base = ['ok' => false, 'courier' => $c, 'name' => $info['name'], 'waybill' => $wb, 'official_url' => $info['official'], 'status' => '', 'events' => [], 'checked' => 0];
    if ($c === '' || $wb === '') {
        return $base;
    }
    $ck = 'sa_ct_' . md5($c . $wb);
    $hit = get_transient($ck);
    if (!$force && is_array($hit)) {
        return $hit + ['cached' => true];
    }
    if (!function_exists('sa_ft_rate_ok') || !sa_ft_rate_ok('ctglobal', 40, 300)) {
        return is_array($hit) ? $hit : $base;
    }
    $res = $base;
    $res['checked'] = time();
    $code = 0;
    $json = null;
    if ($c === 'g4s') {
        $r = wp_remote_get('https://g4scourier.logixerp.com/API/webservice/v2/MultipleWaybillTracking?SecureKey=36C77BABF551458BB88AFD993CC0965F&WaybillNumber=' . rawurlencode($wb), [
            'timeout' => 12,
            'user-agent' => 'SupremeAutoparts-OrderTracking/1.0 (+' . home_url('/') . ')',
        ]);
        $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        $json = $code === 200 ? json_decode((string) wp_remote_retrieve_body($r), true) : null;
        if (is_array($json) && strtolower((string) ($json['messageType'] ?? '')) === 'error') {
            $json = null; // "Waybill Detail Unavailable" etc.
        }
    } else {
        $r = wp_remote_post('https://speedaf.com/publicservice/v1/api/express/track/listExpressTrack', [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['mailNoList' => [$wb]]),
            'user-agent' => 'SupremeAutoparts-OrderTracking/1.0 (+' . home_url('/') . ')',
        ]);
        $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        $json = $code === 200 ? json_decode((string) wp_remote_retrieve_body($r), true) : null;
    }
    if (is_array($json)) {
        $p = sa_ct_normalize($json);
        if ($p['events'] || $p['status'] !== '') {
            $res = array_merge($res, $p, ['ok' => true]);
        }
    }
    set_transient($ck, $res, $res['ok'] ? SA_CT_CACHE_TTL : SA_CT_FAIL_TTL);
    if (!$res['ok'] && function_exists('sa_core_audit_log')) {
        sa_core_audit_log('courier.track_miss', ['courier' => $c, 'waybill' => $wb, 'http' => $code], 'info');
    }
    return $res;
}

function sa_ct_panel_html(string $courier, string $wb, ?WC_Order $o = null): string
{
    [$c, $wb] = sa_ct_clean($courier, $wb);
    if ($c === '' || $wb === '') {
        return '';
    }
    $t = sa_ct_track($c, $wb);
    $h = '<section class="sa-ft"><div class="sa-ft__head"><div><span class="sa-ft__eyebrow">' . esc_html($t['name']) . ' tracking</span>'
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
        $h .= '<p class="sa-ft__msg">Status from ' . esc_html($t['name']) . ': ' . esc_html($t['status']) . '</p>';
    } else {
        $h .= '<p class="sa-ft__msg"><strong>Tracking updates soon.</strong> ' . esc_html($t['name']) . ' hasn\'t published scan events for waybill ' . esc_html($wb) . ' yet.</p>';
    }
    $h .= ($t['checked'] ? '<p class="sa-ft__foot"><small>Checked ' . esc_html(wp_date('j M, g:i A', (int) $t['checked'])) . ' EAT</small></p>' : '') . '</section>';
    return (function_exists('sa_ft_css') ? sa_ft_css() : '') . $h;
}

function sa_ct_order_waybill(WC_Order $o): array
{
    return sa_ct_clean((string) $o->get_meta('_sa_courier'), (string) $o->get_meta('_sa_tracking_no'));
}

/* Order view (My Account + /track-order/ result) and thank-you: live panel after the timeline. */
add_action('woocommerce_view_order', static function ($order_id): void {
    $o = wc_get_order($order_id);
    if (!$o instanceof WC_Order) {
        return;
    }
    [$c, $wb] = sa_ct_order_waybill($o);
    if ($c !== '' && $wb !== '') {
        echo sa_ct_panel_html($c, $wb, $o); // phpcs:ignore
    }
}, 6);

/* /track-order/?courier=g4s|speedaf&waybill=N — Fargo's handler bails on those (see fargo-tracking.php). */
add_filter('do_shortcode_tag', static function ($out, $tag) {
    if ($tag !== 'woocommerce_order_tracking' || !is_string($out)) {
        return $out;
    }
    $c = isset($_GET['courier']) ? sanitize_key((string) $_GET['courier']) : ''; // phpcs:ignore
    if (!isset(sa_ct_couriers()[$c])) {
        return $out;
    }
    $wb = isset($_GET['waybill']) ? sanitize_text_field(wp_unslash((string) $_GET['waybill'])) : ''; // phpcs:ignore
    $pre = function_exists('sa_ft_css') ? sa_ft_css() : '';
    if ($wb !== '') {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $pre .= (function_exists('sa_ft_rate_ok') && !sa_ft_rate_ok('ip' . $ip, 8, 60))
            ? '<p class="sa-ft__msg">Too many lookups. Please wait a minute and try again.</p>'
            : sa_ct_panel_html($c, $wb);
    }
    $pre .= '<form class="sa-ft-form" method="get" action="' . esc_url(home_url('/track-order/')) . '">'
        . '<input type="hidden" name="courier" value="' . esc_attr($c) . '">'
        . '<label class="screen-reader-text" for="sa-ct-wb">' . esc_html(sa_ct_couriers()[$c]['name']) . ' waybill number</label>'
        . '<input id="sa-ct-wb" name="waybill" placeholder="' . esc_attr(sa_ct_couriers()[$c]['name']) . ' waybill number" value="' . esc_attr($wb) . '" maxlength="30" required><button type="submit">Track waybill</button></form>'
        . '<p style="margin:-8px 0 18px;color:#666;font-size:.9rem">Or track with your order number and email:</p>';
    return $pre . $out;
}, 20, 2);

/* Public JSON (rate limited) for QA. */
add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/courier-track', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static function (WP_REST_Request $r) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (function_exists('sa_ft_rate_ok') && !sa_ft_rate_ok('ip' . $ip, 8, 60)) {
                return new WP_REST_Response(['ok' => false, 'message' => 'Tracking updates soon.'], 429);
            }
            $t = sa_ct_track((string) $r->get_param('courier'), (string) $r->get_param('waybill'));
            if (!$t['ok']) {
                $t['message'] = 'Tracking updates soon.';
            }
            return new WP_REST_Response($t, 200);
        },
    ]);
});
