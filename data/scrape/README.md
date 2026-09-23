# Shopify catalog scrape (supreme-mods.com)

Owner-authorized full catalog mirror for Supreme Autoparts.

## Strategy

1. `/products.json` pagination (bulk)
2. `/collections.json` + `/collections/{handle}/products.json`
3. Sitemap gap-fill: `sitemap.xml` → `sitemap_products_*.xml` → `/products/{handle}.json`

Rate limit: **≥5–10s** between requests (adaptive up to 45s on 429).

## Resume

```bash
python3 scripts/scrape-shopify-catalog.py --resume --delay 8
nohup python3 -u scripts/scrape-shopify-catalog.py --resume --delay 8 >> data/scrape/scrape.log 2>&1 &
```

## Monitor

```bash
tail -f data/scrape/scrape.log
cat data/scrape/progress.json
wc -l data/scrape/products.ndjson
```

## Import

```bash
wp supreme import-ndjson --file=/var/www/html/data/scrape/products.ndjson --limit=500 --offset=0
```

## Live status (snapshot for git; progress.json stays local)

- Unique products scraped: **37,200**
- NDJSON lines: **37,200**
- Phase: `sitemap_fetch` · status: `running`
- Sitemap files: 438/438
- Sitemap gap: **3,449 / 403,337** (~399,888 handles left)
- Adaptive delay: **45.0s** · chunk_index: 5
- Snapshot: 2026-09-23 15:06:57 EAT
- Scrape PID on box: keep alive via `./scripts/ensure-scrape-running.sh` (do not kill)

`progress.json`, `products.ndjson`, `products-index.csv`, `sitemap-handles.txt`, and `scrape.log` are **gitignored** on purpose (monoliths + avoid `git restore` clobbering a live scrape). Push status via this README + `chunks/batch-with-images-*.ndjson`.

Huge `products.ndjson` is gitignored; use Railway volume or `chunks/` (<40MB).

Updated: 2026-09-23 15:07:29 EAT
