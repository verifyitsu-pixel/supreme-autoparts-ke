# Two GMT indexes: reviewed isolated execution package

This is preparation, not a production apply command. No production connection defaults or credentials are included. Tests use fake records only. The caller supplies a connection factory. No main branch merge or deployment is implied.

## Exact scope

- Add only `sa_status_date_gmt(post_status,post_date_gmt,post_type)` and `sa_status_modified_gmt(post_status,post_modified_gmt,post_type)` to `railway.wp_posts`.
- Explicit `ALGORITHM=INPLACE, LOCK=NONE`, session `lock_wait_timeout=2`. Unsupported online operation, metadata timeout or cap expiry means stop. Never substitute COPY, another lock mode or an implicit retry.
- Preserve every existing index and all application/data/settings/cache/scoring behavior.
- Rollback drops only those two indexes after checking their exact definitions and equality of every original index to saved pre-state. No force-drop of an unexpected definition.

## Operator preflight, outside this module

A future opportunity must first be coordinated. The unused approval is not a new window. Do not run on a clock guess or use the fake CI pass as proof of live headroom.

Fresh read-only evidence must show at most 100 requests/minute across the preceding 10 minutes, healthy CPU/I/O/load/memory, at least 20 GiB root free, no transaction older than 10 seconds, no pending metadata locks, and both candidate names absent. Any failed or unverifiable gate stops the opportunity. Preserve the timestamp and full gate evidence privately. No polling for another chance unless separately coordinated.

Use pinned SSH and short-lived vault credential files. Delete credential files in a `finally` block. Verify exact server version and table engine. Save original index definitions before DDL. Preserve authority and current restrictions before proposing execution.

## Module and private evidence sequence

`ops/gmt_index_package.py` accepts a connection factory, schema and table. Connect with autocommit, a short connect/write timeout and a worker read timeout longer than its cap plus cancellation grace. Controller failures must be visible. Do not put passwords in reports or source.

1. Capture `snapshot(conn, 'railway', 'wp_posts')` and preserve it privately with the gate receipt. It includes original index inventory, both exact scalar lookups, EXPLAIN, timings and same-repeatable-read-snapshot parity against IGNORE INDEX when present. Read-only transactions always roll back before DDL, so the package does not retain its own metadata lock.
2. Capture side-effect-free public home, cart, checkout and health reads, with status, final URL and latency. Do not create carts, orders, sessions with customer data, or payment attempts. Use the actual established site routes; do not guess a health endpoint.
3. Report about-to-execute to parent only after preflight and preparation pass. Run one `change(factory, 'railway', 'wp_posts', 'add', cap_seconds=30)`.
4. Reconcile SHOW INDEX immediately even after an error. A client error can race completion. The package returns expected/unchanged inventory and flags any failed, killed or uncertain operation for manual reconciliation. Never retry blindly. An unreachable source or UNKNOWN session is a blocker, not completed work.
5. Capture another private snapshot and public checks. A newer timestamp across the two snapshots is allowed under concurrent legitimate writes; parity must be checked inside each consistent snapshot rather than treating an advancing result as corruption. EXPLAIN must use the intended covering indexes, and scalar results must match ignored-index results. Record actual observed timings, not synthetic predictions.
6. Any verified regression requires exact rollback under the same online/2-second metadata lock constraints: `change(..., 'drop', expected_original=pre['indexes'])`. If rollback cannot acquire its lock or the source cannot be reached, alert parent immediately, report actual reconciled state, and do not force a blocking alternative.
7. Verify original inventory restored, scalar parity and public health again. Report URLs, before/after proof, errors and unresolved state. Keep operational evidence private, separate from this public fake-data test branch.

## Runtime cap and its limits

The worker obtains its server `CONNECTION_ID()` before starting. A separate control connection waits up to 30 seconds, then sends `KILL QUERY <exact connection id>` to the server. This is not a client socket timeout. The worker is reconciled for up to another 10 seconds; if it has not returned, status is `UNKNOWN_SERVER_SESSION` and execution blocks. No automatic retry or automatic fallback occurs. Keep the worker connection open until its thread has completed, preventing connection-id reuse while cancellation is issued.

The cap is a cancellation deadline, not a guarantee that InnoDB cleanup has ended at exactly 30 seconds. A control/network failure can prevent cancellation. Such a failure must be reported with unknown or reconciled state, never claimed as bounded success. Do not start another DDL while a prior session's outcome remains uncertain. Reconnect read-only for server session and index reconciliation if possible under the coordinated incident scope; otherwise stop and escalate.

## Qualification

The existing MySQL 9.7.2 pinned-image test seeds 65,000 fake posts and verifies scalar parity, excluded types, original-index preservation and synthetic write-cost evidence. The package-specific test adds/drops the actual candidate definitions, checks plans/parity, rejects duplicate add and mismatched rollback, verifies a real metadata timeout with unchanged inventory, and forces a real server KILL QUERY of metadata-blocked DDL well before the metadata timeout. It also rejects an unsupported FULLTEXT/LOCK=NONE request without changing its algorithm.

CI pass does not establish production build duration, writer latency, total CPU improvement or rollback availability under production contention. Earlier synthetic 200-row insert cost rose about 32% (roughly 2.9 ms per batch), not a prediction of live checkout impact. Facet-query load is separate and unchanged.
