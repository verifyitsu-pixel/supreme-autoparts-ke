# Roadside Rescue & Assistance — feature inventory

The four reference repos were cloned and read on 14 Oct 2026. Every feature is listed below with
how it's built in WordPress. Code: `wp-content/plugins/supreme-autoparts-core/includes/roadside*.php`,
`data/roadside-catalog.php`, `assets/roadside.{css,js}`.

Status key: ✅ built · ➖ already covered by the existing site · ❌ not built (reason given)

## 1. ab3lsec/AutoDocBreakdownAssistance (PHP/MySQL: user, admin, towing driver)

| Feature (source file) | WordPress implementation | |
|---|---|---|
| Request form: name, email, phone, pickup location, destination, service type, pickup time (`index.php`) | `/roadside/request/` no-login form: GPS, map pin or address/landmark, "Tow to" destination, 7 service types, "Help now" or "Schedule for later" | ✅ |
| Booking number + track by number (`track.php`) | `SA-RS-YYMMDD-XXXX`; `/roadside/track/` lookup by number + phone; tokenized `/roadside/status/` link | ✅ |
| Tracking remarks per status (`tbltracking`) | `_rs_history` timeline with time, status, note and who made the change, shown to the customer and in admin | ✅ |
| Admin: new / approved / on the way / completed / cancelled lists (`admin/*.php`) | Super Admin → Roadside Rescue: status filter with counts, statuses Received → Dispatched → En route → Arrived → Completed / Cancelled | ✅ |
| Admin: assign a driver to a request (`view-booking-detail.php`) | Assign dropdown ranked by service fit and distance, with an ETA field; the customer gets an email | ✅ |
| Admin: add / manage / edit drivers (`add-driver.php`, `manage-driver.php`) | Providers tab: add, edit, approve, deactivate, email dashboard link, reset link, link a WP account | ✅ |
| Reports between dates, driver-wise report (`between-dates-reports.php`, `driverwise-report.php`) | Reports tab: date range, totals, by type, by status, by responder, average time to arrival | ✅ |
| Search requests (`search.php`) | Search by ref, phone, name or plate, plus CSV export | ✅ |
| Driver portal: login, assigned jobs, status updates (`driver/*.php`) | `/roadside/provider/` mobile dashboard (role `sa_rs_provider` login or private link) | ✅ |
| Driver notified of new jobs | Brevo email to available partner responders within radius; the in-house team gets the admin alert | ✅ |
| Admin/driver profile, change/forgot password | WordPress accounts and password reset; responder profile form (phone, vehicle, radius, services) | ✅/➖ |
| Admin dashboard counts (`dashboard.php`) | KPI tiles (waiting, active, completed, rating) | ✅ |

## 2. aaazureee/roadside-app (React + NestJS + PostGIS, "Uber for roadside")

| Feature | WordPress implementation | |
|---|---|---|
| Customer makes a callout with location from Google Places (`MakeRequest.js`) | Leaflet + OpenStreetMap map with GPS, drag pin and reverse geocoding (no API key) | ✅ |
| Callouts in range for professionals (`getCalloutsInRange`, PostGIS) | Haversine distance; open jobs within each responder's radius, nearest first | ✅ |
| Professional accept / decline (`acceptCallout`, `declineCallout`) | "Accept job" with ETA, "Not for me", "Release job" | ✅ |
| Customer chooses among accepted professionals (`WAITING_CUSTOMER_SELECTION`, `ResponseList.js`) | Setting "Let customers choose": offers show on the status page with ETA and rating, and the customer picks one | ✅ |
| Live map of current request (`MapsCurrentRequest.js`) | Status page map with the customer pin and a live responder marker (polls every 15 s), plus an admin live map | ✅ |
| Complete callout + payment transaction (`completeCallout`, `transaction.service`) | "Job completed" with amount charged; optional Pesapal link from admin (KES or USD), marked paid via a Woo order hook | ✅ |
| Rating & review modal, review list (`RatingReviewModal.js`, `RatingReviewList.js`) | 1–5 stars + feedback on the status page after completion; per-responder averages; history in the dashboard | ✅ |
| Customer / professional transaction history (`CustomerTransaction.js`, `ProfTransaction.js`) | My Account → Roadside requests; responder job history with amounts and ratings | ✅ |
| Admin lists: customers, professionals, transactions | Requests table + CSV export, Providers tab; customers already in Super Admin → Customers; payments in Woo orders | ✅/➖ |
| Vehicle profile, address profile (`VehicleProfile.js`, `MapsAddressProfile.js`) | Saved vehicles (shared with car-service bookings) and the last location are offered in the form for logged-in customers | ✅ |
| Work profile for professionals (`WorkProfile.js`) | Responder profile: services, radius, vehicle, area | ✅ |
| Sign-up wizard for customers/professionals | Customers never need an account (existing My Account works). Responders apply at `/roadside/join/` and an admin approves | ✅ |
| Membership subscription plans (`Subscription.js`, `AdminSubscription.js`, `Pricing.js`) | **Not built.** It needs a real membership price and cover rules the business hasn't set, and showing one would mean inventing a price | ❌ |
| Stored credit card in profile (`PaymentForm.js`, `credit-card.dto`) | **Not built on purpose.** Card data stays with Pesapal (PCI); we never store cards | ❌ |
| Careers page (`Career.js`) | Covered by "Become a responder" | ✅ |

## 3. minh-nguyen-97/uow-roadside-assistance (ASP.NET: customer, contractor, admin)

| Feature | WordPress implementation | |
|---|---|---|
| Customer MakeRequest, InProgressTransaction | Request form + live status page | ✅ |
| Customer BrowseAvailable contractors | Offers / choice on the status page (optional setting) | ✅ |
| Customer past transactions | My Account → Roadside requests (matched by user or email) | ✅ |
| Contractor AvailableCustomer, IncompleteWork, PastTransactions | Dashboard sections: Open requests near you, My active jobs, Job history | ✅ |
| Contractor profile / change password | Responder profile form; WP password for linked accounts | ✅ |
| Admin ManageContractors | Providers tab | ✅ |
| Admin AllTransactions | Requests table + Woo orders for paid links + CSV | ✅ |
| Admin AccountStatistics (problem bar chart, rating doughnut) | Reports tab bars by problem type and status, plus ratings KPI | ✅ |
| Admin AppealReviews (contractor appeals a review) | Responder "Appeal this rating" → admin Ratings tab decides: hide or keep | ✅ |
| ManageSubscription | Same as roadside-app subscriptions: not built (no real price) | ❌ |
| Contractor sign-up | `/roadside/join/` application + approval | ✅ |

## 4. Chirag0606/Road-rescue-Project (HTML template + docs)

| Feature | WordPress implementation | |
|---|---|---|
| Landing with services (jump start, flat tyre, towing…) | `/roadside/` hero, 7 service cards, how it works, towing price guide, safety box, FAQ, sticky Call/WhatsApp bar | ✅ |
| Mechanic sign-up to offer services | `/roadside/join/` | ✅ |
| Rate and review providers | Ratings (real customer submissions only) | ✅ |
| Admin FAQ manager (`admin_faq.html`) | Settings & FAQ tab: editable FAQ, also output as FAQPage schema | ✅ |
| Admin inbox / comments (`admin_inbox.html`, `admin_comments.html`) | Per-request messages between customer and team/responder, emailed both ways; internal staff notes | ✅ |
| Admin orders | Requests list/detail | ✅ |
| Contact / about pages | Existing site Contact page; Call/WhatsApp CTA +254 714 498 451 | ➖ |
| Blog, posts, products admin | Existing WordPress posts and WooCommerce products | ➖ |
| Commission fee per transaction | **Not built.** The business hasn't set a commission rate, and partner payouts happen offline. Amounts are recorded per job so this can be added once a rate is decided | ❌ |

## Extra UX taken from AAA, Good Hands Rescue, Urgently, HONK

- One-tap "Use my current location", a draggable pin and a landmark field (HONK/Urgently)
- No account to request; status link by email; request number on screen (Urgently)
- Live responder position and ETA countdown, with a 5-step progress tracker (HONK/Urgently)
- Price agreed before dispatch; published towing guide with its source (AAA-style transparency)
- Safety guidance and 999/112 note on the landing page and form (AAA)
- Sticky Call / WhatsApp bar on every roadside page (Good Hands Rescue)
- Tow destination with the shop's own garage as a one-tap option, linking to the parts and service business
- Rate-your-help prompt in the completion email and on the status page

## Integration with the existing site

- **Accounts:** a logged-in customer is linked to the request; guests are matched by email in My Account → Roadside requests. Login is never required.
- **Super Admin:** new section "Roadside Rescue" (`sa-super-roadside`), behind the same `manage_woocommerce` capability.
- **Emails:** `wp_mail` → Brevo mailer, which adds the logo header. No custom footer. Every status update emails the customer.
- **Payments:** the optional admin-created link reuses `Whop_Open_Pay::create_pending_order` (Pesapal gateway, KES or USD), and the order carries `_sa_rs_for`.
- **Numbering:** `SA-RS-YYMMDD-XXXX`, matching `SA-BK-…` bookings.
- **Geo:** Kenya/Nairobi only. If the visitor is detected in the US, an inline note appears in the request form only.
- **Entry points:** third homepage path card, header link + main nav, side menu section, footer links.
