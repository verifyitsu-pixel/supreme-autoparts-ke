# Import chunks

Rolling require-images batches rebuilt from `products.ndjson` (Shopify CDN photos + fields):

| File | Purpose |
|------|---------|
| `batch-with-images-50.ndjson` | Fast boot smoke |
| `batch-with-images-400.ndjson` | Default Railway fill |
| `batch-with-images-1000.ndjson` | Larger sample |
| `batch-with-images-2000.ndjson` | Preferred boot when present (~8MB) |

Rebuild:

```bash
python3 scripts/build-import-batches.py --sizes 400,2000
```

Each record keeps: `images[]`, `variants[].sku`, `product_type`, `vendor`, `tags`, `body_html`, `title`, `handle`.

Full `products.ndjson` is gitignored; scrape overnight with:

```bash
./scripts/ensure-scrape-running.sh
```
