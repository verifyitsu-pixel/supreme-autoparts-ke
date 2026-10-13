# Supreme Workshop — shop management features (internal)

Source study: github.com/ChanMeng666/Automotive-Repair-Management-System ("RepairOS", Flask/SQLAlchemy).
Benchmarks: Tekmetric, Shopmonkey, Mitchell1 Manager SE, AutoLeap, Garage360 (public feature pages).

## RepairOS inventory (what the reference repo has)
| Area | RepairOS | Supreme Workshop |
|---|---|---|
| Customers | first/family name, email, phone; list, search, billing summary, CSV export | `wp_sa_g_customers` (name, email, phone, WP user link, notes); search by name/phone/email; profile with vehicles, ROs, bookings, roadside, Woo orders |
| Jobs (work orders) | job date, customer, total, completed, paid, assigned technician | Repair orders `wp_sa_g_ro` with 10-stage pipeline, tech, bay, schedule, mileage in, concern, approval token, invoice order, paid |
| Job services / parts | job↔service and job↔part with qty | `wp_sa_g_ro_items` (labour / part / fee), hours, KES unit, per-line approval, tech, stock-deducted flag |
| Service catalogue | name, cost, duration, category | Uses the live Car Services catalogue (`data/service-catalog.php`, real prices only) when an RO is created from a booking |
| Parts catalogue + inventory | part, SKU, supplier; inventory on hand, reorder level, transactions | Uses the WooCommerce catalogue (25k+ products): add by SKU/ID, price = live shop price → KES, stock deducted via `wc_update_product_stock` when work starts, restored on removal |
| Technician portal | current jobs, job detail, add service/part, mark complete | Mobile app `/workshop/` (jobs, clock in/out, status buttons, inspections) |
| Admin portal | dashboard, billing, overdue bills, pay bills, reports, team | Super Admin → Workshop: board, ROs, schedule, vehicles, customers, technicians, inspections, reminders, reports, settings |
| Billing | mark paid, overdue list; Stripe subscription (SaaS only) | Woo order invoice per RO, Pesapal pay link, paid status syncs back |
| Reports | dashboard stats, customer export | Revenue by month, ROs by status, technician productivity, top services, parts usage — each with CSV |
| Multi-tenant, RBAC, Google OAuth, onboarding | yes | Not needed (single shop). Roles: `sa_mechanic` (app only), admin / shop manager (full) |

## Added from Tekmetric / Shopmonkey / Mitchell1 / AutoLeap / Garage360
- Vehicles as first-class records: unique reg plate, VIN, mileage history, owner, full history (bookings, roadside, ROs, inspections, invoices, parts orders).
- Digital vehicle inspection (DVI) with red / yellow / green ratings, photos and notes, emailed to the customer (PDF + web report).
- Pickup / drop-off condition cards: tap-to-mark damage diagram, fuel, mileage, items left in car, warning lights, two signatures, timestamp + GPS.
- Digital estimate approval per line from a tokenized link (no login), with customer note; staff notified.
- Job board (kanban) by RO status; bay scheduler week view including un-converted service bookings.
- Technician time clock per job; efficiency = approved billed labour hours ÷ clocked hours.
- Service reminders by date or mileage, emailed daily (7 days / 500 km ahead).

## Integration API (for Roadside and other modules)
- `sa_garage_link_vehicle(array $vehicle, array $customer, string $src_type, int $src_id): int`
- `do_action('sa_garage_link_roadside', $post_id)` / `do_action('sa_garage_link_booking', $post_id)`
- `apply_filters('sa_garage_vehicle_for', 0, 'roadside', $post_id)`
- New bookings and roadside requests are linked automatically at the end of the request (`_sa_bk_ref` / `_rs_ref` meta).
- `sa_g_ro_from_booking($id)`, `sa_g_ro_from_roadside($id)` create a repair order.

## Not built (yet)
- Supplier purchase orders / reorder levels (Woo stock is the inventory).
- SMS reminders (email only).
- Canned jobs / labour guides (no licensed labour-time data; prices are entered by staff or come from the real catalogue).
