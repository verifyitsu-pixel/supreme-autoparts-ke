# Part-number-first catalog search

## Data map (live)

| Signal | Stored as | Notes |
|--------|-----------|--------|
| Part number / OE / SKU | Woo `_sku` (+ `wc_product_meta_lookup.sku`) | From Shopify variant SKU on import |
| Brand | `product_cat` under parent **Brands** | Shopify `vendor` |
| Make / model / year | Title text; optional `_sa_make`, `_sa_model`, `_sa_year` | Soft-stamped from title; title LIKE always works |

No change to scrape PID. Vehicle meta backfill is a small daily batch on shutdown.

## Shareable query args

- `sa_pn` — part number (exact → prefix → contains), **highest priority**
- `s` — keyword (SKU-prioritized on search; also works on `/shop/`)
- `filter_brand` — brand category slug (e.g. `icon`, `powerstop`)
- `sa_make` — e.g. `Ford`
- `sa_model` — e.g. `F-150` (also matches `F150`)
- `sa_year` — e.g. `2021` (also matches `21-23` style ranges)

Examples:

- `https://www.supremeautoparts.co.ke/shop/?sa_pn=ICO91823`
- `https://www.supremeautoparts.co.ke/?s=ICO91823&post_type=product`
- `https://www.supremeautoparts.co.ke/shop/?filter_brand=icon&sa_make=Ford&sa_model=F-150&sa_year=2021`

## UI

Filter bar on shop, category archives, and search. Clear all + chips. Empty state with clear / browse / enquire.

## Versions

- Theme `supreme-autoparts` **1.4.47**
- Core `supreme-autoparts-core` **1.3.42** (`includes/catalog-search.php`)
