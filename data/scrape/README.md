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

Huge `products.ndjson` is gitignored; use Railway volume or `chunks/` (<40MB).

Updated: 2026-09-16 09:44:28 EAT
