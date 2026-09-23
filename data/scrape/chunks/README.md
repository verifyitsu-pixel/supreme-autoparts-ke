# Import chunks

Rolling require-images batches rebuilt from `products.ndjson` (Shopify CDN photos + fields):

| File | Purpose |
|------|---------|
| `batch-with-images-50.ndjson` | Fast boot smoke |
| `batch-with-images-400.ndjson` | Default Railway fill |
| `batch-with-images-500.ndjson` | Mid sample |
| `batch-with-images-1000.ndjson` | Larger sample |
| `batch-with-images-2000.ndjson` | Preferred boot when present (~8MB) |
| `batch-with-images-3000.ndjson` | Extended |
| `batch-with-images-5000.ndjson` | Large (~16MB) |
| `batch-with-images-10000.ndjson` | Max commit-sized (~31MB, under 40MB) |

Rebuild:

```bash
python3 scripts/build-import-batches.py --sizes 50,400,500,1000,2000,3000,5000,10000
```

Each record keeps: `images[]`, `variants[].sku`, `product_type`, `vendor`, `tags`, `body_html`, `title`, `handle`.

Full `products.ndjson` / `products-*.ndjson` are gitignored; scrape overnight with:

```bash
./scripts/ensure-scrape-running.sh
```
