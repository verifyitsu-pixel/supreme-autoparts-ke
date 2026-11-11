# Private order security timeline

Passive Woo private notes, visible in the existing order notes timeline (including the Woo mobile app). No new customer message, scoring, hold, payment, refund, price or checkout decision.

- Checkout snapshot: existing Woo order IP, self-reported user-agent, UA-derived device/browser/OS, saved order creation time in UTC. Limited to checkout, Store API and Supreme custom-payment order origins. Other imported, admin and server-created origins are excluded.
- IP country uses Woo local lookup only, with external IP and API fallbacks disabled. Missing local data stays unavailable. Network IP and approximate country do not establish identity, physical address or fraud.
- Paystack: allowlisted transaction IP, channel, issuing country and successful response status, only after existing reference/amount/currency/status checks pass. No authorization codes, full card, CVC, device fingerprint or provider dump.
- Pesapal: allowlisted method and response status, only after existing authenticated provider lookup and merchant-reference match. Failed and completed responses remain separate events. No changes to provider status application or old notes.
- No hardware ID/IMEI or separate card IP is claimed. AVS/CVC/3DS/risk results are marked not supplied to this timeline. No third-party plugin or API added.
- Notes use customer_note=0 and protected underscore-prefixed dedup metadata. No public endpoint, frontend JavaScript, cookie or fingerprint. Checkout facts are not replaced by callback/admin IP or UA.
- Existing orders can receive a clearly labelled saved-data snapshot through an explicit bounded admin operation. No live provider re-query or invented historical details. No bulk historical scan.
- Existing hold-review document defaults remain unchanged and undecided.

Tests: isolated fake orders assert private notes, same-order deduplication, failed/completed history, absent signals, local-only lookup, safe field allowlist, imported-order exclusion and UA parsing. Git deployment gate runs the regression suite. No paid test.

Rollback: git revert this feature and deploy through the existing workflow. Already-written private notes are preserved as history; no automatic deletion or payment action.
