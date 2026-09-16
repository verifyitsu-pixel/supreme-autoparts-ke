# Cloudflare + Railway (supremeautoparts.co.ke)

**Architecture:** Cloudflare (DNS / CDN / proxy / WAF) in front of **Railway** (WordPress + WooCommerce PHP host).

> **Cloudflare Pages cannot run WordPress.** Do not deploy this app to Pages. Use Railway (or another PHP host) as origin; put Cloudflare in front for DNS, TLS, caching, and security.

## Overview

```
Visitor → Cloudflare (orange-cloud proxy)
       → Railway public domain / TCP proxy
       → WordPress + WooCommerce container
```

| Layer | Role |
|-------|------|
| **Cloudflare** | Authoritative DNS, proxied CDN, SSL termination to visitor, cache rules, optional WAF |
| **Railway** | Origin: Dockerfile WordPress/Apache, MySQL, volumes, Git auto-deploy |

Whop webhooks must remain publicly reachable through Cloudflare with **no bot challenge** on the webhook path (see [Whop webhook](#whop-webhook-must-stay-reachable)).

---

## 1. Add the domain to Cloudflare

1. Sign in at [dash.cloudflare.com](https://dash.cloudflare.com).
2. **Add a site** → enter `supremeautoparts.co.ke`.
3. Choose a plan (Free is enough to start; Pro if you want finer WAF/cache rules).
4. Cloudflare lists nameservers (e.g. `*.ns.cloudflare.com`). At your domain registrar, replace the existing NS records with Cloudflare’s.
5. Wait until the dashboard shows the zone as **Active** (often minutes; can take up to 24–48h for NS propagation).

---

## 2. Railway origin first

Before pointing the apex at Cloudflare:

1. Deploy this repo on Railway (see [README — Railway deploy](../README.md#railway-deploy-git-auto-deploy)).
2. In Railway → service → **Settings → Networking / Domains**, note the public hostname, e.g. `something.up.railway.app`.
3. Optionally add `supremeautoparts.co.ke` in Railway later for TLS awareness; with Cloudflare Full (strict), Railway still needs a valid cert on the hostname Cloudflare connects to (usually the `*.up.railway.app` host, or a custom domain Railway issues a cert for).

Set on the Railway web service:

| Variable | Value |
|----------|--------|
| `WP_HOME` | `https://supremeautoparts.co.ke` |
| `WP_SITEURL` | `https://supremeautoparts.co.ke` |

Redeploy after changing these so WordPress generates correct absolute URLs.

---

## 3. DNS records (proxied / orange cloud)

In Cloudflare → **DNS → Records**:

| Type | Name | Target | Proxy |
|------|------|--------|-------|
| **CNAME** | `@` (apex) | `<your-service>.up.railway.app` | **Proxied** (orange cloud) |
| **CNAME** | `www` | `<your-service>.up.railway.app` | **Proxied** (orange cloud) |

Notes:

- Cloudflare supports CNAME flattening on the apex, so CNAME `@` → Railway is fine.
- If your registrar/tooling prefers A/AAAA, use Cloudflare’s proxied A records only when Railway documents static IPs (Railway hostnames usually change; **prefer CNAME to the Railway hostname**).
- Keep the proxy **on** (orange). Grey-cloud DNS-only skips CDN/WAF/cache and exposes the origin IP.

Optional: in Railway, also attach `supremeautoparts.co.ke` / `www` if you want Railway-issued certs on those hostnames; with Full (strict) this helps when Cloudflare connects using the visitor Host header. Many setups work with Cloudflare → `*.up.railway.app` and `WP_HOME` set to the custom domain—verify with a live request after SSL is set.

---

## 4. SSL/TLS

1. Cloudflare → **SSL/TLS → Overview**.
2. Set mode to **Full (strict)** once Railway presents a valid certificate (Railway’s `*.up.railway.app` cert, or a custom-domain cert on Railway).
3. Until Railway has a working cert, temporarily use **Full** (not Flexible). **Avoid Flexible** — it encrypts browser→Cloudflare only and talks HTTP to origin (breaks cookies, redirects, and WooCommerce).
4. **SSL/TLS → Edge Certificates**: leave **Always Use HTTPS** on; enable **Automatic HTTPS Rewrites** if mixed-content warnings appear.

Confirm:

```bash
curl -sI https://supremeautoparts.co.ke/healthz.php
# expect 200 and body later via GET → ok
```

---

## 5. Cache rules (critical for WooCommerce)

Default Cloudflare caching will break carts and logged-in sessions if HTML is cached. Create **Cache Rules** (Rules → Cache Rules) or use Configuration Rules / Page Rules on Free.

### Bypass (do not cache) — dynamic / private

Create a rule **Bypass cache** (Cache eligibility → Bypass cache) when **any** of:

| Match | Example expression / path |
|-------|---------------------------|
| Path starts with | `/cart` |
| Path starts with | `/checkout` |
| Path starts with | `/my-account` |
| Path starts with | `/wp-admin` |
| Path equals / starts with | `/wp-login.php` |
| Query string | `wc-ajax` present (WooCommerce AJAX: `/?wc-ajax=*`) |
| Cookie | `wordpress_logged_in_*` |
| Cookie | `woocommerce_items_in_cart` |

Also bypass (recommended):

- `/wp-json/*` (REST API)
- `/?wc-api=*` (WooCommerce API endpoints, including payment webhooks)
- `/xmlrpc.php` (or block it in WAF instead)

**Example expression** (Cache Rules — adjust field names to the dashboard UI):

```txt
(starts_with(http.request.uri.path, "/cart")) or
(starts_with(http.request.uri.path, "/checkout")) or
(starts_with(http.request.uri.path, "/my-account")) or
(starts_with(http.request.uri.path, "/wp-admin")) or
(starts_with(http.request.uri.path, "/wp-login.php")) or
(http.request.uri.query contains "wc-ajax=") or
(http.request.uri.query contains "wc-api=") or
(http.cookie contains "wordpress_logged_in_") or
(http.cookie contains "woocommerce_items_in_cart")
```

Action: **Bypass cache**.

A machine-readable sketch lives in [`cloudflare-cache-rules.json`](../cloudflare-cache-rules.json) at the repo root (import manually or mirror in the dashboard; not applied by Wrangler automatically).

### Cache static assets — longer TTL

Separate rule, **lower priority than bypass** (evaluate bypass first):

| Match | Action |
|-------|--------|
| Path starts with `/wp-content/uploads/` | Cache eligible; Edge TTL e.g. 1 month; Browser TTL e.g. 1 day–1 week |
| Theme/plugin static: `/wp-content/themes/` and `/wp-content/plugins/` with extensions `.css`, `.js`, `.woff2`, `.png`, `.jpg`, `.webp`, `.svg`, `.ico` | Same — long edge TTL |

Example:

```txt
(starts_with(http.request.uri.path, "/wp-content/uploads/")) or
(
  (starts_with(http.request.uri.path, "/wp-content/themes/") or
   starts_with(http.request.uri.path, "/wp-content/plugins/"))
  and
  (
    ends_with(http.request.uri.path, ".css") or
    ends_with(http.request.uri.path, ".js") or
    ends_with(http.request.uri.path, ".woff2") or
    ends_with(http.request.uri.path, ".png") or
    ends_with(http.request.uri.path, ".jpg") or
    ends_with(http.request.uri.path, ".jpeg") or
    ends_with(http.request.uri.path, ".webp") or
    ends_with(http.request.uri.path, ".svg") or
    ends_with(http.request.uri.path, ".ico")
  )
)
```

Action: **Eligible for cache**, Edge TTL **1 month** (or “Ignore cache-control header and use this TTL” if origin sends short TTLs).

Do **not** cache HTML product/category pages aggressively until you have a purge strategy (plugin updates, price changes). Start with static assets only.

### Cache level / Development Mode

- While debugging checkout: **Caching → Configuration → Development Mode** (temporary) or disable the static rule.
- Purge: **Caching → Configuration → Purge Everything** after theme/plugin deploys if assets are fingerprinted poorly.

---

## 6. Whop webhook must stay reachable

Payment confirmations use WooCommerce’s WC-API callback, typically:

```txt
https://supremeautoparts.co.ke/?wc-api=whop_webhook
```

(Exact URL is shown in WooCommerce → Settings → Payments → Whop.)

Requirements:

1. **Public HTTPS** through Cloudflare (proxied is fine).
2. **No Bot Fight / Managed Challenge / JS Challenge** on this path — Whop’s servers must get a normal `200` JSON/empty response from WordPress, not an interstitial HTML page.
3. Include `wc-api` in the **cache bypass** rule (above) so webhook POSTs are never served from cache.

### Skip challenges for the webhook

**Security → WAF → Custom rules** (or Configuration Rules / Bot Fight exceptions, depending on plan):

- **Skip** Bot Fight Mode / Super Bot Fight Mode / Managed Challenges / Rate limiting (as available on your plan) when:

```txt
(http.request.uri.query contains "wc-api=whop_webhook") or
(http.request.uri.query contains "wc-api=")
```

Prefer scoping to `whop_webhook` if your plan’s expression fields allow it.

After setup, trigger a sandbox payment or use Whop’s webhook test — confirm Railway/WordPress logs show the hit and Cloudflare analytics do not show a challenge block.

---

## 7. WAF basics (optional)

Useful on Free/Pro without breaking the store:

| Control | Suggestion |
|---------|------------|
| **Bot Fight Mode** | On, but **skip** for `wc-api` / Whop webhook (and optionally `/wp-admin` from known IPs only) |
| **Security Level** | Medium |
| **WAF custom rule** | Block or challenge `xmlrpc.php` if unused |
| **WAF custom rule** | Rate-limit `/wp-login.php` (e.g. 5–10 req/min per IP) |
| **Managed rules** | Enable Cloudflare Free Managed Ruleset if offered |

Avoid challenging all POSTs site-wide — that breaks checkout and add-to-cart.

---

## 8. WordPress / Railway checklist behind Cloudflare

1. `WP_HOME` / `WP_SITEURL` = `https://supremeautoparts.co.ke`.
2. If redirects loop: ensure Railway/Apache trusts `X-Forwarded-Proto` (WordPress normally respects HTTPS when Cloudflare sends it; set `$_SERVER['HTTPS'] = 'on'` via a small mu-plugin only if needed).
3. Volume on `/var/www/html/wp-content/uploads` still required on Railway — Cloudflare caches copies at the edge; origin remains source of truth.
4. Health: `https://supremeautoparts.co.ke/healthz.php` → `ok`.

---

## 9. Wrangler note

This project is **not** a Cloudflare Workers/Pages app. You do **not** need Wrangler to deploy WordPress.

Optional uses only:

- `wrangler` for a future Worker (e.g. edge redirects) — out of scope here.
- Keep [`cloudflare-cache-rules.json`](../cloudflare-cache-rules.json) as human/API reference for Cache Rules; apply in the Cloudflare dashboard (or Cloudflare API) against the zone.

---

## Quick verification

1. DNS: `dig supremeautoparts.co.ke` → Cloudflare IPs (proxied).
2. SSL: padlock, Full (strict), no mixed content on homepage.
3. Logged-out product page loads; static CSS/JS from `/wp-content/` show `cf-cache-status: HIT` after second request.
4. Add to cart → cart/checkout never show `HIT` for HTML; cart count updates.
5. Whop sandbox webhook delivers without Cloudflare challenge pages in the response body.
