# Supreme Autoparts (supremeautoparts.co.ke)

WordPress + WooCommerce storefront — visual/UX replica of [supreme-mods.com](https://supreme-mods.com/), rebranded as **Supreme Autoparts** for Kenya (KES, Africa/Nairobi). Deployable on **Railway via Git auto-deploy**, with **Cloudflare** in front for DNS/CDN/proxy (see [Cloudflare + Railway](#cloudflare--railway)).

> Catalog note: the source store has on the order of **~1M listings** and **~917 collections**. This repo ships sample products plus a **full Shopify JSON scraper** (`scripts/scrape-shopify-catalog.py`) and NDJSON Woo importer. Run the scrape before production import.

## Stack

- WordPress (official `wordpress:php8.3-apache` image)
- WooCommerce (installed on container boot via WP-CLI)
- Custom theme: `wp-content/themes/supreme-autoparts`
- Core plugin: `wp-content/plugins/supreme-autoparts-core`
- Payments: `wp-content/plugins/whop-payments` (Whop Checkout)
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
| `WHOP_API_KEY` / `WHOP_COMPANY_ID` / `WHOP_WEBHOOK_SECRET` / `WHOP_SANDBOX` | Whop payment gateway (see Payments) |

6. Attach a **volume** to `/var/www/html/wp-content/uploads` so media survives redeploys.
7. Custom domain: Railway service → **Settings → Domains** → add `supremeautoparts.co.ke` (+ `www` if needed) and point DNS as instructed.
8. After first deploy: log in, run sample import, configure shipping zones, set `WHOP_*` env vars, enable **Whop** under WooCommerce → Payments, and register the webhook URL in the Whop dashboard.

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
  plugins/whop-payments/       # Whop WooCommerce gateway
```

## Homepage IA (mirrored)

- Hero: **CAR PARTS & ACCESSORIES**
- Shop by region: American / European / Asian
- Product type tiles: Air Intake, Brakes, Drivetrain, Engine, Exhaust, Exterior, Interior, Lighting, Suspension, Tires, Wheels
- Marketing blurb + free-shipping messaging (KES threshold placeholder)
- Top brands: ACT, aFe, AWE, Bilstein, Bushwacker, Corsa, EBC, Fox, Garrett, King, Oracle, Road Armor, WeatherTech
- Deep megamenu: Brakes, Drivetrain, Engine, Exhaust, Exterior, Interior, Lighting, Suspension (+ Tires/Wheels)

## Payments (Whop)

Store payment processor: **[Whop](https://whop.com)** via the bundled plugin `wp-content/plugins/whop-payments`.

Appears in **WooCommerce → Settings → Payments** as **Whop**.

### Flow

1. At checkout, the gateway creates a Whop **Checkout Configuration** with an inline **one-time** plan priced to the WooCommerce order total (`POST /checkout_configurations`).
2. Customer is redirected to Whop’s hosted `purchase_url` (or returns via the configured redirect).
3. Whop sends a signed **`payment.succeeded`** webhook to `/?wc-api=whop_webhook`; the plugin verifies the signature and marks the order paid.

Docs: [Accept payments](https://docs.whop.com/developer/guides/accept-payments) · [Create checkout configuration](https://docs.whop.com/api-reference/checkout-configurations/create-checkout-configuration) · [Webhooks](https://docs.whop.com/developer/guides/webhooks)

### Railway env vars

Set these on the web service (never commit secrets):

| Variable | Notes |
|----------|--------|
| `WHOP_API_KEY` | Account API key (`Authorization: Bearer …`) |
| `WHOP_COMPANY_ID` | Company / account id (`biz_…`) |
| `WHOP_WEBHOOK_SECRET` | Signing secret from the Whop webhook endpoint (`ws_…` / `whsec_…`) |
| `WHOP_SANDBOX` | `1` → `https://sandbox-api.whop.com/api/v1`; `0` → production `https://api.whop.com/api/v1` |

Admin fields mirror these; **env vars win** when set (so Railway can inject secrets).

### Webhook setup

1. Copy the callback URL shown under Whop gateway settings (also: `https://<your-domain>/?wc-api=whop_webhook`).
2. In Whop Dashboard → Developer → Webhooks, create an endpoint for that URL.
3. Subscribe to **`payment.succeeded`** only (recommended).
4. Paste the signing secret into `WHOP_WEBHOOK_SECRET` (or the admin field).

Sandbox: use keys from [sandbox.whop.com](https://sandbox.whop.com) and set `WHOP_SANDBOX=1`.

No payment credentials are bundled in Git.

## Full catalog scrape + import

Owner-authorized full scrape of https://supreme-mods.com/ public Shopify JSON:

```bash
# Start (hours — expected). Logs: data/scrape/scrape.log
nohup python3 -u scripts/scrape-shopify-catalog.py >> data/scrape/scrape.log 2>&1 &

# Resume
python3 scripts/scrape-shopify-catalog.py --resume
```

Outputs under `data/scrape/` (`products.ndjson`, `products-index.csv`, `collections.json`, `progress.json`, `chunks/`). See `data/scrape/README.md`.

Import into Woo (batched, idempotent by handle/SKU/Shopify id):

```bash
wp supreme import-ndjson --file=/var/www/html/data/scrape/products.ndjson --limit=500 --offset=0
# or
wp eval-file scripts/import-from-shopify.php -- --file=data/scrape/products.ndjson --limit=500 --offset=0
```

Huge `products.ndjson` is gitignored — mount a Railway volume at `/var/www/html/data/scrape` or push `chunks/` under ~40MB each.

## License / branding

Storefront code in this repo is for Supreme Autoparts. Visible copy must say **Supreme Autoparts** / **supremeautoparts.co.ke** only — never “Supreme Mods”.
