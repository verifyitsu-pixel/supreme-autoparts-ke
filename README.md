# Supreme Autoparts (supremeautoparts.co.ke)

WordPress + WooCommerce storefront — visual/UX replica of [supreme-mods.com](https://supreme-mods.com/), rebranded as **Supreme Autoparts** for Kenya (KES, Africa/Nairobi). Deployable on **Railway via Git auto-deploy**, with **Cloudflare** in front for DNS/CDN/proxy (see [Cloudflare + Railway](#cloudflare--railway)).

> Catalog note: the source store has on the order of **~1M listings** and **~917 collections**. This repo ships **sample products only** plus an import helper. Full catalog migration is a later pipeline.

## Stack

- WordPress (official `wordpress:php8.3-apache` image)
- WooCommerce (installed on container boot via WP-CLI)
- Custom theme: `wp-content/themes/supreme-autoparts`
- Core plugin: `wp-content/plugins/supreme-autoparts-core`
- MySQL 8 (local Compose) / Railway MySQL plugin (production)

## Quick start (local)

```bash
cd SUPREMEAUTOPARTS
cp .env.example .env
# Edit admin password in .env
docker compose up --build -d
```

Open **http://localhost:8080**

Default admin (change immediately): see `.env` / `WORDPRESS_ADMIN_*`.

### Import sample catalog

```bash
docker compose exec wordpress wp supreme import-sample --allow-root
# or
docker compose exec wordpress wp eval-file \
  wp-content/plugins/supreme-autoparts-core/scripts/import-shopify-sample.php --allow-root
```

Admin UI: **Tools → Supreme Import**.

Sample USD prices are converted with `SUPREME_USD_TO_KES` (default `130`) for display — replace with real KES pricing before launch.

## Railway deploy (Git auto-deploy)

1. Push this repo to GitHub (`verifyitsu-pixel/SUPREMEAUTOPARTS` or your fork). **Do not commit `.env`.**
2. In [Railway](https://railway.app): **New Project → Deploy from GitHub repo**.
3. Railway detects `Dockerfile` + `railway.json` (DOCKERFILE builder, healthcheck `/healthz.php`).
4. Add the **MySQL** plugin/service and link it to the web service.
5. Set variables on the web service (Railway MySQL vars are often injected automatically):

| Variable | Notes |
|----------|--------|
| `MYSQLHOST` / `MYSQLPORT` / `MYSQLUSER` / `MYSQLPASSWORD` / `MYSQLDATABASE` | From MySQL plugin (entrypoint maps → `WORDPRESS_DB_*`) |
| `WP_HOME` | `https://supremeautoparts.co.ke` (or Railway URL first) |
| `WP_SITEURL` | Same as `WP_HOME` |
| `WORDPRESS_ADMIN_USER` | Admin username |
| `WORDPRESS_ADMIN_PASSWORD` | Strong secret |
| `WORDPRESS_ADMIN_EMAIL` | Your email |
| `WOO_CURRENCY` | `KES` |
| `SUPREME_FREE_SHIPPING_THRESHOLD` | e.g. `15000` |
| `SUPREME_SEED_ON_BOOT` | `1` to seed pages/cats on boot |

6. Attach a **volume** to `/var/www/html/wp-content/uploads` so media survives redeploys.
7. Custom domain: Railway service → **Settings → Domains** → add `supremeautoparts.co.ke` (+ `www` if needed) and point DNS as instructed.
8. After first deploy: log in, run sample import, configure shipping zones, and enable payment gateways (M-Pesa / card) — stubs only; no credentials in repo.

Health check: `GET /healthz.php` → `ok`.

## Cloudflare + Railway

Production edge: **Cloudflare** (DNS, CDN, proxy, optional WAF) in front of **Railway** (WordPress / WooCommerce PHP origin).

**Cloudflare Pages cannot run WordPress** — do not deploy this app to Pages. Point the zone at your Railway hostname with orange-cloud proxy, SSL/TLS **Full (strict)**, cache bypass for cart/checkout/account/admin/`wc-ajax`/`wc-api`/Woo cookies, and longer TTL for `/wp-content/uploads` and theme static assets. Keep the Whop webhook (`/?wc-api=whop_webhook`) publicly reachable with **no bot challenge**.

Step-by-step: **[docs/cloudflare.md](docs/cloudflare.md)**. Cache rule sketch: [`cloudflare-cache-rules.json`](cloudflare-cache-rules.json).

## Project layout

```
Dockerfile                 # wordpress:php8.3-apache + theme/plugin bake-in
railway.json               # DOCKERFILE builder + /healthz.php
docker-compose.yml         # local wordpress + mysql
wordpress-entrypoint.sh    # DB wait, WP install, Woo, theme, KES, Nairobi
.env.example
healthz.php
data/
  sample-products.json     # 50 Shopify sample products
  collections.json         # condensed IA + subset of handles
scripts/
  import-shopify-sample.php
  import-from-shopify.php  # stub / docs for full pipeline
wp-content/
  themes/supreme-autoparts/
  plugins/supreme-autoparts-core/
```

## Homepage IA (mirrored)

- Hero: **CAR PARTS & ACCESSORIES**
- Shop by region: American / European / Asian
- Product type tiles: Air Intake, Brakes, Drivetrain, Engine, Exhaust, Exterior, Interior, Lighting, Suspension, Tires, Wheels
- Marketing blurb + free-shipping messaging (KES threshold placeholder)
- Top brands: ACT, aFe, AWE, Bilstein, Bushwacker, Corsa, EBC, Fox, Garrett, King, Oracle, Road Armor, WeatherTech
- Deep megamenu: Brakes, Drivetrain, Engine, Exhaust, Exterior, Interior, Lighting, Suspension (+ Tires/Wheels)

## Payments (next steps — config only)

WooCommerce gateways to evaluate for Kenya:

- Card: WooCommerce Payments / Stripe (if available in your region)
- Mobile money: M-Pesa plugins (e.g. community or SaaS gateways) — install and configure secrets in Railway env, never in Git

No payment credentials are bundled.

## Full catalog import (later)

Public Shopify-style endpoints (read-only reference / owner-authorized export):

- `/collections.json`
- `/collections/{handle}/products.json?limit=250&page=N`
- `/products/{handle}.json`

Extend `includes/import-shopify.php` with pagination, rate limits, and resume checkpoints. Prefer official Shopify Admin export when possible. **Do not** attempt a full scrape in one job.

## License / branding

Storefront code in this repo is for Supreme Autoparts. Visible copy must say **Supreme Autoparts** / **supremeautoparts.co.ke** only — never “Supreme Mods”.
