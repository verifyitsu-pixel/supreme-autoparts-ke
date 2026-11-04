# Paid confirmation routing regression

Run PHP source contracts without WordPress:

    php tests/paid-confirmation-routing-regression.php

The other PHP fixtures require a disposable WordPress + WooCommerce store with Supreme core and the exact theme installed. They create synthetic orders and mutate test records. Never run them on production. Their `pre_wp_mail` filter intercepts transport; outbound mail and provider HTTP must also be denied by the surrounding disposable runtime. Test products and fixture IDs must be set for that runtime. SQL advisory lock operations are emulated by these SQLite fixtures; that is not a MySQL concurrency proof.

`mysql-protocol.py` is an isolated MySQL protocol harness. Its local socket must point to a disposable MySQL server. It creates only the `sa_confirm_test` database and verifies separate-process zero-wait locking, sent-marker replay protection and connection-death unknown-outcome behavior. It is not a full concurrent Woo integration test.

Verified separately: actual Woo 11.1 CPT and HPOS (compatibility mode off), successful first confirmation for direct completed and processing orders, on-hold followed by payment, same/fresh replay, later completion, failed transport retry, missing paid date, side-effect-free enabled inspection, blank recipient, busy lock, reentry, wrong recipient, unknown transport outcome, full persisted order mutations, mutable subject/content mutations, nested subject evaluation and receipt-relevant metadata changes.

The confirmation lock serializes competing confirmation sends only. It does not freeze unrelated admin order edits, catalog images or customer profile updates. Order-derived state is checked immediately before transport. A non-boolean return, exception or crash after the pending marker is written leaves `_sa_paid_confirmation_pending=1`. That state blocks blind retries. Review provider/mail transport logs and order notes to establish whether acceptance happened before any manual recovery. Never clear a pending marker merely because time elapsed. Transport acceptance is not proof of inbox delivery.

Historical completed-email markers are retained to avoid unsolicited paid-confirmation resends. This revision does not resend past orders or change order/payment status. The approved processing confirmation template is unchanged.
