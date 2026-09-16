# Import chunks

`batch-with-images-400.ndjson` — first 400 scraped supreme-mods products that have real `images[].src` Shopify CDN URLs.

`batch-with-images-1000.ndjson` — refreshed 1,000-product sample (3.4 MB) requiring both non-empty `images[]` and a variant SKU; suitable for Railway import and safe to commit.

The complete source remains at `data/scrape/products.ndjson` (gitignored). Rebuild a larger local batch with:

```bash
python3 - <<'PY'
import json
from pathlib import Path
out = Path("data/scrape/chunks/batch-with-images-2000.ndjson")
n = 0
with Path("data/scrape/products.ndjson").open(encoding="utf-8") as src, out.open("w", encoding="utf-8") as dst:
    for line in src:
        p = json.loads(line)
        if p.get("images") and any(v.get("sku") for v in (p.get("variants") or []) if isinstance(v, dict)):
            dst.write(json.dumps(p, ensure_ascii=False, separators=(",", ":")) + "\n")
            n += 1
            if n == 2000: break
print(f"wrote {n} records to {out}")
PY
```

Used by boot import (`SUPREME_IMPORT_ON_BOOT=1`) and Tools → Supreme Import.

Full `products.ndjson` stays gitignored; add more chunks as needed (&lt; ~40MB each for GitHub).
