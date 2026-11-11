# Residual query research: offline only

## Evidence boundary

The normalized live digests in the bounded Nov11 morning diagnostic match SQL source shapes in this branch's existing application files:

- `catalog-facets.php` lines 647-710: `sa_fx_base_sql` constructs the published-product/visibility/lookup base, and `sa_fx_counts` joins it to the facet index before grouping value and label. Cache key includes facet, skip, prefix and filter state. Source uses a 30-minute transient; observed live calls do not establish its hit rate or explain misses.
- `site-forms.php` lines 588-640: `sa_sf_search_index_build` retrieves product ID/title and a correlated `_sku` scalar, ordered by ID in pages of 500 with increasing OFFSET. This source hooks it to `sa_search_index_warm` and schedules hourly. The SQL is a strong shape match, not proof of current production file identity, runtime initiating request, exact schedule state or overlapping jobs.

No current live source hash was read during this offline phase. No claims that all similar calls originate in the named functions, or that an importer is responsible. Do not treat old code comments about historical timing as present performance evidence.

## Fake-data experiment

`tests/integration/residual-query-qualification.py` builds 65,000 fake post records, 325,000 fake meta rows, fake lookup data, visibility exclusions and three facet rows per post on the already pinned MySQL 9.7.2 CI service. No customer data or production connection exists.

Research alternatives, not application patches:

1. Keyset product paging with `p.ID > last_seen_id`, retaining the same correlated SKU subquery. Compare full row parity at offsets 0/10k/40k/53k, including missing/duplicate SKU rows and draft/revision exclusions. This avoids falsely declaring a join against WooCommerce lookup equivalent to arbitrary postmeta scalar semantics. Concurrent publish/unpublish/insert/delete behavior needs separate decision and qualification before any real search rebuild change.
2. Facet EXISTS rewrite retaining lookup filters and visibility rules. Compare value/label/count maps for unfiltered, stock, price, other-facet and empty results. Original SQL sorts only by count, so tied row order is not promised. Synthetic group count stays below LIMIT; tie-boundary subset equivalence remains unresolved.
3. Extra covering facet index, measured only in fake data. This is outside the approved production two-GMT-index scope. It is not a request or permission to add it live.

Measure EXPLAIN and warm median reads. A syntactic rewrite may produce the same optimizer plan, and a covering index may offer little benefit. Do not convert static semantic pass into live speed claims. No scoring, ranking, cache, cron, settings, product, price, search code, or schema is changed in production. Any later proposal must preserve the user's scoring restriction and the separate holds.
