# Delivery estimates, pickup slots and fitted items

All dates shown to customers are labelled **Estimated** and are never guaranteed.
Code: `wp-content/plugins/supreme-autoparts-core/includes/fulfilment.php`.

## Fargo Courier transit (sources and assumptions)
- Fargo's rate card (`data/fargo-rates.json`) has **no transit times**.
- Fargo's own service description (Fargo/Wells Fargo proposal to KOAN, 2023,
  https://koan.co.ke/wp-content/uploads/2023/06/WellsFargo-Proposal-to-KOAN-2023.pdf): overnight service,
  "delivered the following working day to all major towns ... items destined for remote off-grid locations may take longer".
- Conservative tiers used (working days after hand-over to Fargo):
  - Nairobi county: 1–2
  - Other towns with a Fargo branch: 1–3
  - Remote towns (`remote_150_towns`, `starred_towns` on the card) and counties without a branch (Wajir, Mandera → Garissa): 3–5
  - County not chosen / unknown: 3–5
- Hand-over (dispatch) assumption: same day if paid on a working day before 14:00 EAT, otherwise the next working day.
  This is OUR assumption, not a Fargo figure; change `sa_ff_dispatch_day()` if the shop's real cut-off differs.
- Working days skip Sundays and Kenyan public holidays (list in `sa_ff_holidays()`; extend yearly).
  Saturdays are counted as working days (assumption: Fargo branches operate Saturdays).
- Stock lead time: added only when a product has `_sa_stock_lead_days` set AND is out of stock / on backorder.

## Store pickup slots
- Hours from the booking settings in code (`sa_svc_available_slots`): Mon–Fri 08:00–17:00, Sat 08:00–13:00,
  closed Sunday and public holidays.
- 1-hour windows; earliest = 2 hours after checkout rounded up to the hour (assumption: preparation time).
- Capacity per window: option `sa_pickup_slot_capacity` (default 3, our choice; change in wp_options).
- Saved as order meta `_sa_pickup_date`, `_sa_pickup_time`; shown in order emails, thank-you, My Account and admin.
- "Ready for pickup" email = existing order status `ready-pickup` (now includes the window). Reminder email ~2 h before.

## Fitted items
- Cart lines with fitting are a separate package, rate "Fitted at our garage (no shipping)", KES 0; they add no Fargo weight.
- All-fitted carts need no shipping. Earliest fitting date = next open garage slot after payment.
