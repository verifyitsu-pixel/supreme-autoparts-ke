# Paystack payment lock recovery

Release prerequisite: an operator must have this procedure and access to the
merchant's Paystack transaction history and the WordPress execution environment.
Do not clear a lock just because it is old. Age is not proof the owner stopped.

## Normal behavior

Initialization and settlement share the database option
`sa_paystack_payment_ORDERID`. The request that creates it holds it until request
shutdown. A competing initialization stops before a provider request. A competing
settlement returns a retryable response. Normal shutdown removes the option.
A hard-killed process can leave it behind. The deliberate result is a blocked
payment flow, not an unsafe takeover of a possibly active payment.

## Investigate without changing payment state

1. Identify the exact order, its currency/total, current status, transaction ID,
   recorded `_sa_paystack_charge_quotes`, latest `_paystack_txn_ref`, duplicate
   paid references and held-paid references. Preserve these values and the lock
   timestamp in a private incident record. Never copy credentials into it.
2. Identify the request/process that held the lock using the host's request and
   process logs. Confirm it has ended and cannot resume. If that cannot be
   established, keep the lock and escalate to the host/operator. Do not use a
   timeout as a substitute for this check.
3. Check Paystack's authoritative transaction history for every recorded attempt
   and any matching order-prefix reference in the incident window. Confirm exact
   reference, success/pending/failed status, charge currency and minor-unit amount.
   An initialization timeout or missing authorization URL does not prove that no
   provider transaction exists. If any outcome is unresolved, keep the order
   blocked and escalate; do not invite another payment.
4. If a successful payment is found, compare it against its exact recorded quote
   and current order state. A mismatch, unsupported historical reference, edited
   order, cancelled/refunded/on-hold order or second successful payment needs
   manual review. Do not mark the order paid merely from a screenshot or browser
   return. Do not issue an automatic converted-currency refund.

## Controlled recovery

Only after the original process is confirmed ended and all payment attempts are
accounted for may the authorized operator remove the *single exact order's*
`sa_paystack_payment_ORDERID` option. Recheck immediately before removal that its
value and order state still match the incident record. If they changed, stop and
restart the investigation. Never bulk-delete payment locks, quotes or references.

For an exact recorded successful payment, use the provider's authenticated event
redelivery where available, or the existing server-side verification callback for
that recorded reference. The callback must fetch and verify Paystack itself; do
not fabricate an event or bypass signature/amount/currency checks. Confirm the
resulting order status, original transaction reference and conversion metadata.
The same reference repeated must not create a second fulfillment. A second paid
reference must produce the private manual-review record, not replace the first.

For conclusively failed/unpaid attempts, let the customer reload the payment page
and explicitly review the fresh charge before retrying. Do not reuse or invent a
customer quote token. Confirm the new init uses the charge shown on that form.

If recovery still fails, leave further payments blocked, preserve the evidence
and escalate. No timer, scheduled job or automatic stale-lock deletion is part of
this release.
