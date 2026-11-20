# Isolated full-cart sandbox preparation

This is a synthetic WooCommerce staging cart, not the private admin test panel.
No customer/catalog/database clone. No production files or options are edited.
The current prepare-only phase leaves PayPal keys empty and every payment blocked.
It includes the Supreme theme, actual WooCommerce cart/checkout, official PayPal
Payments 4.1.3 unavailable-without-credentials behavior, and a staging-only payment
fragment showing the honest unavailable PayPal sandbox row. The theme's production
payment fragment is Pesapal-only, so the staging override is explicit and does not
change that production fragment or production gateway defaults.

Deployment is not automatic. Everything here lives under tests/staging, not the
production mu-plugin/theme deploy paths. No production main branch push is needed.

## Runtime isolation required before a review URL

Separate WordPress tree, database, DB user, Docker network and volumes. No mounts
of production DB, uploads, config, secrets, cron or checkout callbacks. Loopback
listener only and access-protected dedicated hostname. Block outbound email,
external analytics and cron. Empty-key phase blocks all outbound PHP HTTP and
browser external resources. Do not provide an unauthenticated staging URL.

The current VPS failed the fresh capacity assessment on Nov20 16:20UTC despite
healthy HTTP endpoints. No new staging workload may be added there until a fresh
assessment supports it. Shared-host staging would still share the VPS failure
domain and is not an off-server copy. No paid infrastructure is approved.

## Local disposable rehearsal

Install fresh WordPress with a separate disposable MySQL-compatible DB and the
Supreme theme. Activate only WooCommerce 11.1.0 and PayPal Payments 4.1.3, not
Supreme Core or production analytics/mail/gateways. This is deliberate: those
production modules include import, email, fulfilment and other external effects.
Copy this directory's mu-plugins and templates preserving their relative paths.
Set `SA_SYNTHETIC_STAGING=true`, `WP_ENVIRONMENT_TYPE=staging`, `DISABLE_WP_CRON=true`,
`DISALLOW_FILE_MODS=true`, and synthetic store URL. Run bootstrap.php once only
on the fresh DB. It refuses an existing product/order store.

## Pending, not claimed

- A persistent protected review URL has not yet been stood up.
- Sandbox credentials are absent. No provider approval/capture test has run.
- This synthetic cart uses the production theme and native WooCommerce, but does
  not claim equivalence to all production plugins, shipping/discounts or real
  catalog. Those behaviors need separate fixture coverage before release.
- The empty-key guard intentionally blocks official plugin network, SDK, AJAX and
  checkout submission. Supplying keys alone will NOT enable payments. A reviewed
  sandbox-only allowlist, credential wiring, order ownership/amount/capture tests,
  and buyer sandbox test are still required before payment is usable.
- The synthetic product's stock/orders are separate from real stock/orders.
- Real-store Pesapal stays untouched, PayPal stays hidden from real customers.


## Verification recorded before publishing

Local rehearsal used WordPress7.1.5, WooCommerce11.1.0, PayPal Payments4.1.3,
Supreme theme from0357311, MariaDB10.6.23 and PHP8.1.2. Twenty-eight actual
browser assertions at390/1280 and fourteen native state/isolation checks passed.
The synthetic checkout submission was forged directly and denied; orders stayed
zero and stock stayed ten. Browser and DB processes were stopped after the run.

The first visual test found a narrow payment-message column inherited from the
production theme's grid. A staging-only full-width grid span fixed it; final
390/1280 payment pixels were inspected. The initial native setup needed explicit
WooCommerce schema installation and its coming-soon option disabled. Those steps
are now in the bootstrap. The first SDK test found an otherwise auto-loaded
PayPal sandbox SDK; final empty-key guard dequeues/filters all ppcp scripts and
keeps the strict CSP after WooCommerce's checkout header. Final tests report no
successful external browser resources. None of these fixes changes production.

The portable integration script and feature-branch workflow are prepared; their
hosted execution remains pending until a push is authorized and performed.
Local MariaDB/PHP8.1 evidence is not hosted MySQL8.4/PHP8.3 evidence.
