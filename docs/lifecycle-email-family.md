# Customer order lifecycle email family

Adapted from the actual Cyclery confirmation, staged-to-ship and cancellation references supplied by the owner on 2026-11-04. Original buyer information, tracking URLs, logos and vendor policies are not reused. Uses the same locally hosted licensed stock workshop photos as the approved confirmation; these are labelled inspiration, not Supreme premises.

Scope: custom customer processing, hold, information request, staged, shipped, out-for-delivery, delivered, pickup-ready, completed, cancelled, refund and failed updates. Active delivery is `sa_ol_send` with `sa_ol_email_content`. Existing code already disables the overlapping native Woo emails. No send trigger, recipient, attachment, order state or payment change. Approved processing confirmation template remains unchanged. The shared layout used by workshop, return and admin messages remains unchanged pending its separately scoped review.

Single SHOP/GARAGE/HELP header, stage band, workshop photo, status-specific message and CTA, actual item/totals and customer/shipping/payment detail, optional category discovery shelf, licensed footer photo and black business footer. Pending/info/failed/refund messages omit the discovery shelf to keep the required action clear. Recommendations preserve the confirmation's local selector, do not touch SM_Recommend, and explicitly do not claim vehicle fitment.

No generic delivery estimates, signature threshold, automatic refund guarantee or claim that a failed payment means no debit occurred. Stored courier/tracking, pickup code and information-upload routes stay in place. Stage-specific content is still derived from the existing order event, not from the design.

Validation: PHP 8.1 lint and isolated render regression for all 12 stages, including twice-applied actual Brevo branding identity. Fixture screenshot inspection is not proof of production rendering or delivery. Production no-send runtime and desktop/mobile pixel checks required before closeout. No test email send is needed.
