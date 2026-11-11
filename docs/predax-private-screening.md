# Private Predax IP checks

Approved scope: checkout IP only. No names, emails, addresses, order/payment details, timezone, fingerprint or community telemetry. Provider retains IP/request metadata for 90 days. Notify only, never blocks, holds, document requests or customer messages. Journey capture remains disabled.

New checkout/store-api/sa_open_pay orders queue asynchronously after request shutdown. No historical backfill. Results are private chronological notes with collapsed details. High provider score (80+) adds private admin review only, not a fraud verdict. API errors and missing data have no risk inference.

Permanent free allowance: 1000/day and 5000/month. Local hard reservations cap at800/day and4000/month UTC, including failed calls. Cache is one hour, keyed by HMAC of IP, stores only allowlisted derived findings. Provider auth/quota failures pause for one day, rate failures for one hour. No paid fallback. Current introductory Starter plan automatically falls to Free; no card supplied.

Key lives in non-autoloaded WordPress option sa_predax_key, never source. Turn off future enqueueing by deleting that option. Existing order flags/notes remain audit records. Rollback through git deploy, not direct file replacement.

Qualification: tests/predax-private-regression.php uses no network, fake credentials, no real orders. Public example8.8.8.8 API response verified at setup, not a customer test. Official sources: https://predax.io/docs/quickstart https://predax.io/privacy https://predax.io/terms
