# Supreme Autoparts — Super Admin Blueprint

Source: owner brief 2026-09-24. Goal: control over the entire platform, not only individual orders.

## Extras (must include)
- Fraud screening
- User / audit / login logs
- Support tickets: Contact us / site email → ticket; customer tracks + replies in My Account; admin manages in Super Admin

## Sidebar
Dashboard · Orders · Products · Inventory · Customers · Vendors · Payments · Shipping · Discounts · Marketing · Analytics & Reports · Reviews · Content/Website · Admins & Roles · Notifications · Integrations · Settings · Security & Audit Logs · Support Tickets · Fraud

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
- Inventory, Discounts, Marketing, Analytics, Reviews, Admins, Notifications (useful lists + Woo links)
- Vendors: honest Phase 4 notice (single-store, no marketplace)
- Woo setup task list / "Step X of 5" dismissed on admin_init
- Legacy `supreme-orders` / `supreme-products` / `supreme-customers` redirect to Super pages
