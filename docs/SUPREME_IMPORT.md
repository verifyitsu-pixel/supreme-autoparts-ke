# Supreme catalog import

## Boot import (Railway)

When `SUPREME_IMPORT_ON_BOOT=1` (or first boot with no `sa_boot_import_done` flag), the entrypoint imports products that have **real Shopify CDN photos**:

1. Prefer `data/scrape/chunks/batch-with-images-400.ndjson` (default path)
2. Fallback: `batch-with-images-50.ndjson`

Env knobs:

| Variable | Default | Meaning |
|---|---|---|
| `SUPREME_IMPORT_ON_BOOT` | `1` / empty triggers once | Run import on container start |
| `SUPREME_IMPORT_FILE` | auto | Override NDJSON path |
| `SUPREME_IMPORT_LIMIT` | `400` or `50` | Max rows |
| `SUPREME_IMPORT_SKIP_IMAGES` | `1` | `1` = store CDN URL meta only (fast); `0` = sideload into Media Library |

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
