# Import chunks

`batch-with-images-400.ndjson` — first 400 scraped supreme-mods products that have real `images[].src` Shopify CDN URLs.

Used by boot import (`SUPREME_IMPORT_ON_BOOT=1`) and Tools → Supreme Import.

Full `products.ndjson` stays gitignored; add more chunks as needed (&lt; ~40MB each for GitHub).
