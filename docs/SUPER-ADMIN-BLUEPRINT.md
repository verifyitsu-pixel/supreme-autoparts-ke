# Supreme Autoparts — Super Admin Blueprint

Source: owner brief 2026-09-24. Goal: control over the entire platform, not only individual orders.

## Extras (must include)
- Fraud screening
- User / audit / login logs
- Support tickets: Contact us / site email → ticket; customer tracks + replies in My Account; admin manages in Super Admin

## Sidebar
Dashboard · Orders · Products · Inventory · Customers · Vendors · Payments · Shipping · Discounts · Marketing · Analytics & Reports · Reviews · Content/Website · Admins & Roles · Notifications · Integrations · Settings · Security & Audit Logs · Support Tickets · Fraud

Also under Supreme Autoparts (tools): Import · Policies · Enquire leads

## Sections 1–13 + platform control
(See owner message: overview KPIs, products, orders, customers, payments, inventory, shipping, discounts & marketing, website, admin/users, analytics, notifications, system & security, multi-store/vendor/commission/KYC/disputes/API/health/feature toggles/maintenance.)

## Phases
1. Shell + sidebar + overview metrics + tickets + fraud/audit foundations
2. Orders/shipping/fulfilment/labels + customers CRM + payments/invoices/payment links
3. Inventory/warehouses + discounts/marketing + roles + analytics exports
4. Multi-store/vendor/platform controls

## Phase 2 status (shipped in core 1.3.30+)

Working Super Admin surfaces (real Woo data + deep links — not stubs):

- Orders, Customers (deduped menu), Payments, Shipping, Products, Content, Integrations, Settings
- Vendors: honest Phase 4 notice (single-store, no marketplace)
- Woo setup task list / "Step X of 5" dismissed on admin_init
- Legacy `supreme-orders` / `supreme-products` / `supreme-customers` redirect to Super pages

## Phase 3 status (shipped in core 1.3.36+)

Deep Woo/Brevo-backed UIs in `admin-super-phase3.php`:

- **Inventory** — stock KPIs (in/out/backorder/low/managing), filters, search, quick qty/status update (writes Woo stock meta). Single warehouse; multi-location = Phase 4 notice.
- **Discounts** — coupon list (usage/limit/expiry) + create coupon form (percent / fixed cart / fixed product).
- **Marketing** — Brevo lists + email campaigns via API (read-only), enquire leads snapshot, WhatsApp +1 917 437 5121. No fake in-WP campaign builder.
- **Analytics** — full revenue/order/AOV via HPOS or postmeta SQL (not sample of 100), range 7d/30d/90d/all, status counts, top products by qty.
- **Reviews** — product comment/review table with ratings + moderate links.
- **Admins & Roles** — staff list + role/capability guide (`manage_woocommerce` / `manage_options`). No dangerous custom role editor.
- **Notifications** — Woo email template enablement table + Brevo routing notes. In-app push = not built (honest).

## Still Phase 4

- Multi-store / Vendors marketplace, commissions, KYC, disputes
- Multi-warehouse inventory locations
- Feature toggles / maintenance mode platform controls
- In-app push / SMS blast notification center
- Custom capability matrix / SSO
