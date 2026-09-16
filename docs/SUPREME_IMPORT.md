# Supreme catalog import

## Step-by-step (avoid mistakes)

Scrape and live import are **separate**. Never stop an active scrape to import.

1. **Keep scraping** (box or CI worker):
   ```bash
   ./scripts/ensure-scrape-running.sh   # never kills an active scrape
   # or: python3 -u scripts/scrape-shopify-catalog.py --resume --delay 10
   ```
2. **Build sized batches** from whatever scrape progress exists (safe anytime):
   ```bash
   python3 scripts/build-import-batches.py --sizes 50,400,2000
   ```
3. **Dry-run one parent category** (no writes):
   ```bash
   wp supreme import-ndjson \
     --file=/var/www/html/wp-content/plugins/supreme-autoparts-core/data/scrape/chunks/batch-with-images-400.ndjson \
     --category=brakes --limit=50 --require-images --dry-run
   ```
4. **Live import that category** (CDN meta only is fine for first pass):
   ```bash
   wp supreme import-ndjson \
     --file=.../batch-with-images-400.ndjson \
     --category=brakes --limit=50 --require-images --skip-images
   ```
5. **Verify parent archive** lists products: `/product-category/brakes/`
6. **Repeat** for `suspension`, `exhaust`, … one family at a time.
7. Only after parents look correct, raise `--limit` or omit `--category`.

Admin UI: **Tools → Supreme Import** (or Ultra → Import) — pick category, limit, dry-run, run.

---

## Boot import (Railway)

When `SUPREME_IMPORT_ON_BOOT=1` (or first boot / empty catalog), the entrypoint imports a **safe** batch:

1. Prefer `batch-with-images-400.ndjson` (then `50`) — **not** auto `2000`
2. Default `SUPREME_IMPORT_CATEGORY=brakes` and `SUPREME_IMPORT_LIMIT=50` unless overridden
3. Passes `--require-images` and optional mapping file

To dump a larger unscoped batch you must set both explicitly, e.g. `SUPREME_IMPORT_CATEGORY=` (empty) + `SUPREME_IMPORT_LIMIT=400` + optional `SUPREME_IMPORT_FILE=...2000.ndjson`.

Env knobs:

| Variable | Default | Meaning |
|---|---|---|
| `SUPREME_IMPORT_ON_BOOT` | `0` (empty catalog / first boot still imports) | Force import on every container start |
| `SUPREME_FORCE_IMPORT` | `0` | `1` = clear `sa_boot_import_*` options and re-import even if catalog non-empty |
| `SUPREME_RECOVER_TOKEN` | (unset) | Shared secret for `GET/POST /wp-json/supreme/v1/recover-catalog` |
| `SUPREME_IMPORT_FILE` | auto (400 then 50) | Override NDJSON path (use this for 2000) |
| `SUPREME_IMPORT_CATEGORY` | `brakes` (boot) | Parent slug filter (`brakes`, `suspension`, …); empty = all |
| `SUPREME_IMPORT_LIMIT` | `50` when category set | Max **matching** rows |
| `SUPREME_IMPORT_SKIP_IMAGES` | `1` | `1` = CDN URL meta only; `0` = sideload |
| `SUPREME_IMPORT_MAPPING` | plugin `data/product-type-parent-map.json` | product_type → parent map |

**Empty catalog recovery:** if published Woo product count is `0`, entrypoint deletes `sa_boot_import_done` / `sa_boot_import_batch50` and re-imports (category-scoped by default). Products are assigned to **parent IA categories** (e.g. `brakes`) **and** leaf `product_type` so `/product-category/brakes/` lists children.

CDN URLs are always stored on the product (`_sa_shopify_image_urls`). The theme/plugin render those URLs when local thumbnails are missing — **never AI placeholders**.

---

## Customization flags

```bash
wp supreme import-ndjson \
  --file=.../batch-with-images-400.ndjson \
  --category=brakes \
  --limit=50 \
  --require-images \
  --dry-run \
  --mapping=/var/www/html/wp-content/plugins/supreme-autoparts-core/data/product-type-parent-map.json
```

Same flags via `wp eval-file scripts/import-from-shopify.php -- --category=brakes --limit=50 --dry-run --require-images`.

Mapping JSON shape:

```json
{
  "exact": { "Brake Pads": "brakes", "Shocks and Struts": "suspension" },
  "keywords": { "brakes": ["brake", "rotor", "caliper"] }
}
```

Flat `{ "Brake Pads": "brakes" }` is also accepted.

---

## WP-CLI

```bash
wp supreme import-ndjson \
  --file=/var/www/html/data/scrape/chunks/batch-with-images-400.ndjson \
  --category=brakes --require-images
```

Omit `--skip-images` (or set `SUPREME_IMPORT_SKIP_IMAGES=0`) to sideload binaries.

---

## Admin / Ultra

**Tools → Supreme Import** (linked from Ultra) — pick category, limit, dry-run, require images, run import.

---

## Scrape

Do not stop an active `scripts/scrape-shopify-catalog.py` process. Full `products.ndjson` is gitignored; commit sized chunks under `data/scrape/chunks/` (~40MB max for GitHub).

See also root `README.md` § Boot import / Full catalog scrape.

---

## HTTP recover (empty shop)

```bash
curl -sS "https://www.supremeautoparts.co.ke/wp-json/supreme/v1/recover-catalog?token=$SUPREME_RECOVER_TOKEN"
```

When published product count is `0`, the token is optional. Response includes `published`, `brakes_count`, `suspension_count`. Caps at 50 products over HTTP (CDN photos only).

---

## Pricing (USD base)

Import stores Shopify variant prices as **USD** on `_regular_price` / `_sale_price` (no KES multiply).
`woocommerce_currency` should be `USD` (`WOO_CURRENCY=USD`). Display-currency FX by visitor IP is handled separately; catalog amounts remain USD.

---

## Rolling batches from live scrape

```bash
./scripts/ensure-scrape-running.sh   # never kills an active scrape
python3 scripts/build-import-batches.py --sizes 400,2000
```

Then import **one category** with a modest limit before scaling up.
