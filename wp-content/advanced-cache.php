<?php
/**
 * SA origin page cache (country-aware, Woo-safe). Enabled by WP_CACHE.
 *
 * Key = resolved country (same header precedence as the sa-geo detector:
 * CF-IPCountry, then X-Country-Code, then the sa_geo_cc sticky cookie) +
 * host + path + normalized query. Storage: wp-content/cache/sa-page/.
 * 30 minute TTL. Purged by mu-plugins/sa-page-cache-purge.php.
 *
 * Never cached: cart/checkout/account/admin/wp-json paths, any request with
 * Woo/session/login cookies, mutating query params, non-GET/HEAD, any
 * response that sets cookies or is not HTML 200.
 */
if (!defined('WP_CACHE') || !WP_CACHE) {
    return;
}

// A bounced visitor must not kill the render: finish it so the page is still
// cached for the next visitor (cold renders are the expensive ones).
ignore_user_abort(true);

$sa_pc_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($sa_pc_method !== 'GET' && $sa_pc_method !== 'HEAD') {
    return;
}

$sa_pc_uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$sa_pc_path = (string) parse_url($sa_pc_uri, PHP_URL_PATH);
foreach (['/cart', '/checkout', '/my-account', '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php'] as $sa_pc_bypass) {
    if ($sa_pc_path === $sa_pc_bypass || str_starts_with($sa_pc_path, $sa_pc_bypass . '/')) {
        return;
    }
}

// A Merchant USD browsing session must never receive country-only cached KES HTML.
if (isset($_COOKIE['sa_merchant_usd']) && $_COOKIE['sa_merchant_usd'] === '1') {
    return;
}

foreach (array_keys($_COOKIE) as $sa_pc_ck) {
    if (str_starts_with($sa_pc_ck, 'woocommerce_cart_hash')
        || str_starts_with($sa_pc_ck, 'woocommerce_items_in_cart')
        || str_starts_with($sa_pc_ck, 'wp_woocommerce_session_')
        || str_starts_with($sa_pc_ck, 'wordpress_logged_in_')
        || str_starts_with($sa_pc_ck, 'comment_author')) {
        return;
    }
}

$sa_pc_query = [];
parse_str((string) parse_url($sa_pc_uri, PHP_URL_QUERY), $sa_pc_query);
foreach (['add-to-cart', 'sa_buy_now', 'remove_item', 'undo_item', 'add_payment_method', 'v'] as $sa_pc_mut) {
    if (isset($sa_pc_query[$sa_pc_mut])) {
        return;
    }
}
// Normalize tracking params out of the key so they share one entry.
foreach (array_keys($sa_pc_query) as $sa_pc_k) {
    if (str_starts_with($sa_pc_k, 'utm_') || in_array($sa_pc_k, ['fbclid', 'gclid', 'msclkid', '_ga', 'mc_cid', 'mc_eid'], true)) {
        unset($sa_pc_query[$sa_pc_k]);
    }
}
ksort($sa_pc_query);

$sa_pc_cc = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
if ($sa_pc_cc === '') {
    $sa_pc_cc = strtoupper(trim((string) ($_SERVER['HTTP_X_COUNTRY_CODE'] ?? '')));
}
if (!preg_match('/^[A-Z]{2}$/', $sa_pc_cc) || $sa_pc_cc === 'XX' || $sa_pc_cc === 'T1') {
    $sa_pc_cc = strtoupper(trim((string) ($_COOKIE['sa_geo_cc'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $sa_pc_cc)) {
        $sa_pc_cc = 'XX'; // unknown bucket; sa-geo falls back to KE default
    }
}

$sa_pc_host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'unknown'));
$sa_pc_dir  = (defined('SA_PAGE_CACHE_DIR') ? SA_PAGE_CACHE_DIR : WP_CONTENT_DIR . '/cache') . '/sa-page';
$sa_pc_key  = $sa_pc_host . '|' . $sa_pc_cc . '|' . $sa_pc_path . '|' . http_build_query($sa_pc_query);
$sa_pc_file = $sa_pc_dir . '/' . md5($sa_pc_key) . '.html';
$sa_pc_ttl  = defined('SA_PAGE_CACHE_TTL') ? SA_PAGE_CACHE_TTL : 1800;

// The XX bucket (no geo header, no sticky cookie) is never served: a cached page
// is country-specific, and only PHP can resolve the country for these visitors.
// The request still flows to the store callback below, which files the render
// under the country the detector sets in the sa_geo_cc cookie.
if ($sa_pc_cc !== 'XX' && is_file($sa_pc_file) && filemtime($sa_pc_file) + $sa_pc_ttl > time()) {
    $sa_pc_body = file_get_contents($sa_pc_file);
    if ($sa_pc_body !== false && $sa_pc_body !== '') {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-SA-Cache: HIT age=' . (time() - filemtime($sa_pc_file)));
        header('Vary: CF-IPCountry, X-Country-Code');
        if ($sa_pc_method === 'HEAD') {
            header('Content-Length: ' . strlen($sa_pc_body));
        } else {
            echo $sa_pc_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        exit;
    }
}

$sa_pc_qs = http_build_query($sa_pc_query);
ob_start(static function (string $sa_pc_html) use ($sa_pc_file, $sa_pc_dir, $sa_pc_cc, $sa_pc_host, $sa_pc_path, $sa_pc_qs): string {
    $sa_pc_store = http_response_code() === 200;
    $sa_pc_set_cc = '';
    if ($sa_pc_store) {
        foreach (headers_list() as $sa_pc_h) {
            if (stripos($sa_pc_h, 'Set-Cookie:') === 0) {
                // The geo stickiness pair is safe: the cache is keyed by the same
                // country the cookie carries, and it is HttpOnly (never read by JS).
                // Any other cookie marks the response personalized - do not store.
                if (preg_match('/^Set-Cookie:\s*sa_geo_cc=([A-Za-z]{2})[^a-zA-Z0-9]/i', $sa_pc_h, $sa_pc_m)) {
                    $sa_pc_set_cc = strtoupper($sa_pc_m[1]);
                    continue;
                }
                if (stripos($sa_pc_h, 'Set-Cookie: sa_geo_ip=') === 0) {
                    continue;
                }
                $sa_pc_store = false;
                break;
            }
            if (stripos($sa_pc_h, 'Cache-Control:') === 0 && (stripos($sa_pc_h, 'no-store') !== false || stripos($sa_pc_h, 'no-cache') !== false || stripos($sa_pc_h, 'private') !== false)) {
                $sa_pc_store = false;
                break;
            }
            if (stripos($sa_pc_h, 'Content-Type:') === 0 && stripos($sa_pc_h, 'text/html') === false) {
                $sa_pc_store = false;
                break;
            }
        }
    }
    if ($sa_pc_store && $sa_pc_set_cc === '' && $sa_pc_cc === 'XX') {
        // Unknown country and no cookie set this render: the file would sit in
        // the never-served XX bucket, so skip writing it.
        $sa_pc_store = false;
    }
    if ($sa_pc_store && strlen($sa_pc_html) > 512) {
        $sa_pc_target = $sa_pc_file;
        if ($sa_pc_set_cc !== '' && $sa_pc_set_cc !== $sa_pc_cc) {
            // First visit (no geo header/cookie): PHP resolved the country by IP
            // and set the cookie - file this render under that country's bucket.
            $sa_pc_target = $sa_pc_dir . '/' . md5($sa_pc_host . '|' . $sa_pc_set_cc . '|' . $sa_pc_path . '|' . $sa_pc_qs) . '.html';
        }
        if (!is_dir($sa_pc_dir)) {
            mkdir($sa_pc_dir, 0755, true);
        }
        file_put_contents($sa_pc_target, $sa_pc_html, LOCK_EX);
    }
    header('X-SA-Cache: ' . ($sa_pc_store ? 'MISS stored' : 'MISS bypassed'));
    return $sa_pc_html;
});
