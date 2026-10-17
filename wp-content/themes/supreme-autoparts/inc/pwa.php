<?php
/**
 * PWA essentials (Rock 2026-10-17): installable from Safari "Add to Home Screen".
 *
 * - Serves /sw.js with Service-Worker-Allowed: / so the worker gets root scope
 *   without a webroot file (WP virtual route, survives Docker rebuilds).
 * - The worker never caches checkout, cart, account, login, payment or API
 *   traffic: cache-first applies ONLY to this theme's static assets, every
 *   other request goes straight to the network.
 * - Registers the worker in the footer (https only).
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('init', static function (): void {
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ($path !== 'sw.js') {
        return;
    }
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache, must-revalidate');
    echo sa_pwa_sw_js(); // phpcs:ignore
    exit;
}, 1);

function sa_pwa_sw_js(): string
{
    return <<<'JS'
/* Supreme Autoparts service worker - minimal, safe by construction.
   Dynamic pages (checkout, cart, account, login, payment, APIs) are NEVER
   cached: only this theme's own static assets get cache-first, everything
   else goes to the network. The bypass list is a second, explicit guard. */
const SA_CACHE = 'sa-static-v1';
const ASSET_PREFIX = '/wp-content/themes/supreme-autoparts/assets/';
const BYPASS = /^\/(checkout|cart|my-account|order-pay|order-received|track-order|wp-admin|wp-login\.php)(\/|$)|wc-api|admin-ajax\.php|wc-ajax|add-to-cart|pesapal|whop/i;

self.addEventListener('install', () => { self.skipWaiting(); });
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== SA_CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (BYPASS.test(url.pathname + url.search)) return; // network only, never cached
  if (!url.pathname.startsWith(ASSET_PREFIX)) return; // network only
  e.respondWith(
    caches.match(req).then((hit) => {
      const fresh = fetch(req).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(SA_CACHE).then((c) => c.put(req, copy));
        }
        return res;
      });
      return hit || fresh;
    })
  );
});
JS;
}

add_action('wp_footer', static function (): void {
    if (!is_ssl()) {
        return;
    }
    ?>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
  });
}
</script>
    <?php
}, 20);
