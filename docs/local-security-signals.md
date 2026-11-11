# Local private security signals

Release: 2026-11-11. Notify only. Adapter license: GPL-2.0-or-later.

## Included

- Checkout Guard 2.1.1 disposable-email data, alias allowlist, and exact placeholder-name list. Original headers, package readme, MIT notice and GPL2 license are included in `wp-content/mu-plugins/sa-security-data/`.
- Approximate fixed-window order-submission counters using saved checkout IP and email. Transient keys use HMAC, not plaintext customer values. TTL is 10 minutes plus one minute for IP, one hour plus one minute for email. Parallel requests may undercount; this is an indicator, not a forensic ledger or payment-decline counter.
- One private assessment note on newly created supported checkout orders. Existing saved-device and authenticated provider-response notes stay in the same order timeline.
- An internal review notification when both more than 10 submissions/IP/10-minute bucket and more than 5/email/hourly bucket match. A name, country or disposable-email match alone does not trigger this alert. This is a local review heuristic, not a vendor score or fraud finding.
- Protected order metadata deduplicates assessment and alert notes. Dashboard queue holds at most 20 order IDs/timestamps, expires display after 30 days, shows five links, requires `manage_woocommerce`, and suppresses repeated dashboard entries for the same IP/email pair for ten minutes. Private order notes follow the store's order retention. No email or push is sent.

## Not included

No complete third-party fraud plugin is installed or activated by this release. The adapter loads only the licensed local lists. No plugin licensing SDK, external screening, account signup, paid plan, API key, auto-recharge, new customer-data disclosure, blocklist enforcement, checkout rejection, order hold, status change, customer message or document request occurs.

SaferCheckout's binary country datasets were excluded because their separate provenance/license was not established. Existing Woo local location labels stay unchanged; this assessment explicitly shows additional location as unavailable. No remote fallback or automatic data update.

Velocity Guard, Blacklist Manager and Dotstore full loaders are not stacked because of overlapping enforcement and state. This release's bounded submission signal is custom, not a claim that Velocity Guard is activated. No manually invented customer/IP blacklist is added.

External FraudLabs Pro, Predax, OrderGuard and other screening services remain unconnected pending service-specific data-sharing, terms and free-limit approval. No VPN/proxy/Tor reputation, hardware ID, separate card IP or enterprise equivalence is claimed.

Order-linked journey remains disabled in this release. No capture script, cookie or endpoint is loaded. Exact public disclosure approval and isolated consent/session tests are required before a separate journey release. No historical customer journeys are reconstructed.

## Qualification and deployment

`tests/local-security-regression.php`: isolated fake orders/state; list/alias false positives, composite thresholds, privacy-only note path, timestamp and unavailable labels, counter bounds, dedup, group flooding, no customer/payment/network/tracking hooks. Existing private timeline and Paystack regression tests retained. Full main deploy gate includes these tests before Contabo git deployment. No paid/live checkout test.

After deployment verify deployed hash/file hash, unchanged order status/amount/method, a private assessment note, and actual desktop/mobile pixels. Do not manufacture a live high-risk event or real order to prove the alert. Alert behavior is qualified in isolated fake state.

Rollback: git revert this release and deploy through the existing workflow. Do not replace live files directly. Already written private notes may remain as historical evidence; do not erase or rewrite them silently.

Sources:
- https://wordpress.org/plugins/checkout-guard/
- https://downloads.wordpress.org/plugin/checkout-guard.2.1.1.zip
- https://wordpress.org/plugins/safercheckout-lite/
- https://downloads.wordpress.org/plugin/safercheckout-lite.1.20.zip
