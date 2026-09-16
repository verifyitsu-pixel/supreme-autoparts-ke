# Supreme catalog import

## Boot import (Railway)

When `SUPREME_IMPORT_ON_BOOT=1` (or first boot with no `sa_boot_import_done` flag), the entrypoint imports products that have **real Shopify CDN photos**:

1. Prefer `data/scrape/chunks/batch-with-images-2000.ndjson` when present
2. Else `batch-with-images-400.ndjson`
3. Fallback: `batch-with-images-50.ndjson`

Env knobs:

| Variable | Default | Meaning |
|---|---|---|
| `SUPREME_IMPORT_ON_BOOT` | `0` (empty catalog / first boot still imports) | Force import on every container start |
| `SUPREME_FORCE_IMPORT` | `0` | `1` = clear `sa_boot_import_*` options and re-import even if catalog non-empty |
| `SUPREME_RECOVER_TOKEN` | (unset) | Shared secret for `GET/POST /wp-json/supreme/v1/recover-catalog` (`?token=` or `X-SA-Recover-Token`) |
| `SUPREME_IMPORT_FILE` | auto | Override NDJSON path |
| `SUPREME_IMPORT_LIMIT` | `400` or `50` | Max rows |
| `SUPREME_IMPORT_SKIP_IMAGES` | `1` | `1` = store CDN URL meta only (fast); `0` = sideload into Media Library |

**Empty catalog recovery:** if published Woo product count is `0`, entrypoint deletes `sa_boot_import_done` / `sa_boot_import_batch50` and re-imports from `batch-with-images-400` (fallback 50). Products are assigned to **parent IA categories** (e.g. `brakes`) **and** leaf `product_type` so `/product-category/brakes/` lists children.

CDN URLs are always stored on the product (`_sa_shopify_image_urls`). The theme/plugin render those URLs when local thumbnails are missing — **never AI placeholders**.

## WP-CLI

```bash
wp supreme import-ndjson \
  --file=/var/www/html/data/scrape/chunks/batch-with-images-400.ndjson \
  --require-images
```

Omit `--skip-images` (or set `SUPREME_IMPORT_SKIP_IMAGES=0`) to sideload binaries.

## Admin

**Tools → Supreme Import** — import first 400 products with Shopify photos.

## Scrape

Do not stop an active `scripts/scrape-shopify-catalog.py` process. Full `products.ndjson` is gitignored; commit sized chunks under `data/scrape/chunks/` (~40MB max for GitHub).

See also root `README.md` § Boot import / Full catalog scrape.


## HTTP recover (empty shop)

```bash
curl -sS "https://www.supremeautoparts.co.ke/wp-json/supreme/v1/recover-catalog?token=$SUPREME_RECOVER_TOKEN"
```

When published product count is `0`, the token is optional. Response includes `published`, `brakes_count`, `suspension_count`. Caps at 50 products over HTTP (CDN photos only).


## Pricing (USD base)

Import stores Shopify variant prices as **USD** on `_regular_price` / `_sale_price` (no KES multiply).
`woocommerce_currency` should be `USD` (`WOO_CURRENCY=USD`). Display-currency FX by visitor IP is handled separately; catalog amounts remain USD.


## Rolling batches from live scrape

```bash
./scripts/ensure-scrape-running.sh   # never kills an active scrape
python3 scripts/build-import-batches.py --sizes 400,2000
```

Set `SUPREME_IMPORT_LIMIT=2000` (or higher later) on Railway; empty catalog still force-imports.
