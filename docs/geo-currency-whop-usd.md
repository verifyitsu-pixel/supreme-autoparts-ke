# Geo currency display + Whop USD settlement

## Architecture (WCPBC equivalent — do **not** install WCPBC)

We ship **`sa-geo-currency`** (baked into the Docker image / Railway deploy) instead of
[WooCommerce Price Based on Country](https://wordpress.org/plugins/woocommerce-product-price-based-on-countries/).

Why not WCPBC:

- WCPBC changes **pricing/zone currency** in Woo and would desync Whop amounts.
- Our store **base / order / Whop plan currency is always USD**.
- Display conversion is visual-only via `wc_price` + CF geo; cart math stays USD.

| Layer | Currency |
|-------|----------|
| Catalog / Woo store option | **USD** |
| Order `order.currency` | **USD** |
| Whop `plan.currency` / API amount | **`usd`** (forced) |
| Storefront price labels | Visitor local (geo) approx. FX |
| Whop Adaptive Pricing (checkout UI) | Buyer local; **settles to USD** |

## Worldwide coverage

`SA_Geo_Detector::country_currency_map()` maps **~250** ISO-3166-1 countries/territories → ISO-4217.

- Cloudflare `CF-IPCountry` preferred on **every** request (CDN Vary: `CF-IPCountry`).
- Fallback cookie `sa_geo_cc` is **IP-bound** via `sa_geo_ip` (2h). VPN/IP change invalidates it — not stuck on first visit.
- Soft AJAX `sa_geo_resolve` reloads once if HTML body country ≠ live CF/IP (page-cache safety).
- Further fallback: ipapi.co (per-IP transient).
- **Unmapped / unknown → USD** (“rest of world”).
- FX: `https://open.er-api.com/v6/latest/USD` (override `SA_FX_API_URL`), cached ~8h.
- If FX missing for a currency → display falls back to USD (no broken prices).

GBP, EUR, KES, and every other mapped currency are covered the same way — not a EUR/GBP-only zone list.

## WooCommerce settings (entrypoint + shipping boot)

- `woocommerce_currency` = `USD` (`WOO_CURRENCY` / `SA_CHECKOUT_CURRENCY`)
- `woocommerce_default_customer_address` = `geolocation_ajax`  
  (Woo: **Default customer location → Geolocate (with page caching support)**)

## Whop force-USD (plugin `whop-payments` ≥ 1.2.18)

Real hooks (no fictional `woocommerce_whop_payment_gateway_args`):

1. `WC_Gateway_Whop::process_payment` → `resolve_usd_charge()` → always sends `currency=usd`.
2. `Whop_Api_Client::create_checkout_configuration` hard-sets `plan.currency = usd` and re-asserts after filter.
3. Filters:
   - `whop_payments_usd_charge` — adjust USD amount / display snapshot
   - `whop_payments_checkout_configuration_body` — last chance on API body (USD re-forced)
4. Order meta for reconciliation:
   - `_sa_display_currency`
   - `_sa_display_total`
   - `_sa_charged_usd`
   - `_whop_currency_sent` = `usd`
   - `_sa_geo_country`
5. Woo logger source `whop-payments`:  
   `Whop USD charge order=… amount_usd=… display=GBP …`
6. Open Pay / $1 card verify remain **USD**.

Webhook settlement compares Whop paid amount to **USD order total** (unchanged).

## QA checklist

| Visitor geo | Storefront shows | Whop API / meta |
|-------------|------------------|-----------------|
| Mock `CF-IPCountry: GB` | approx GBP + “Charged in USD…” | `currency=usd`, `_sa_charged_usd` = order total, `_sa_display_currency=GBP` |
| Mock `CF-IPCountry: DE` | approx EUR | same, display EUR |
| Mock `CF-IPCountry: KE` | approx KES | same, display KES |
| Unknown / XX | USD | usd |

Open browser with CF header or cookie `sa_geo_cc=GB` on cart; place test order; confirm order note + meta + Woo log.

## Adaptive Pricing (Whop) — manual + API

**What it does:** buyer sees local currency on Whop checkout; **you still settle in plan currency (USD)**. Free for sellers. One-time payments only.

### API (already wired)

Dynamic checkout configs set:

```json
"plan": { "currency": "usd", "adaptive_pricing_enabled": true, ... }
```

Embedded PM verify stays USD ($1); storefront redirect checkout uses API flag.

### Manual dashboard (optional — API already ON for dynamic checkouts)

Whop docs: Adaptive Pricing is **per checkout link / plan**. Our plugin sets `adaptive_pricing_enabled: true` on every dynamic config (verified on live `ch_*` for Standard Driveworks).

If you also want the dashboard Advanced default for static links:

1. Open [Whop Dashboard](https://whop.com/dashboard) as **owner** (Cavin Lugai / `verifyitsu` — not a limited teammate)
2. **Payments → Settings → Advanced** (or the specific **Checkout link** settings)
3. Toggle **Accept local currency payments** / **Adaptive Pricing** → **ON**

Docs: https://docs.whop.com/payments-and-billing/fees/adaptive-pricing

2026-09-30: browser session on agent desktop was **DI** (`user_W1KnXrx9v9WEE`) — **permission denied** on Payments Settings. MCP/API as owner already has Adaptive ON for storefront checkouts.

### Embed note

For any future embedded storefront checkout, set `data-whop-checkout-adaptive-pricing="true"` (defaults false on embeds). Redirect `purchase_url` checkouts honor the plan flag from the API.

## Env

```
WOO_CURRENCY=USD
SA_CHECKOUT_CURRENCY=USD
SA_GEO_CURRENCY=1
# optional: SA_FX_API_URL=https://open.er-api.com/v6/latest/USD
```

## Versions

| Package | Version |
|---------|---------|
| sa-geo-currency | 1.1.1 |
| whop-payments | 1.2.18 |
| supreme-autoparts-core | 1.3.40 (geolocation_ajax) |
