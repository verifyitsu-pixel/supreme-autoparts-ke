# Whop Pixel + US Meta ads (biz_9VJcCdK7G30L63)

## Pixel install (done in repo)

- **mu-plugin:** `wp-content/mu-plugins/supreme-whop-pixel.php`
- **Biz ID:** `biz_9VJcCdK7G30L63`
- **Snippet source:** https://docs.whop.com/developer/ads/pixel  
- **Dashboard check:** https://whop.com/dashboard/biz_9VJcCdK7G30L63/pixel  
- Ships on every front-end page via `wp_head` (`whop.setScope("biz_9VJcCdK7G30L63"); whop.track("page");`).
- Destination URL for ads: `https://www.supremeautoparts.co.ke/` (shop). External URL requires this pixel or Meta rejects with “Whop pixel was not detected”.

WooCommerce checkouts that go through **Whop Checkout** are attributed server-side — do **not** fire `whop.track("purchase")` for Whop-processed orders (double-count).

---

## Blockers (do not launch spend until both clear)

Checked 2026-09-30 (Africa/Nairobi):

| Check | Status |
| --- | --- |
| Meta (Facebook/Instagram) with `advertise` scope | **NOT connected** — `social-accounts_list` empty |
| Whop Cards (active) | **Present** — e.g. `icrd_kK0kxp7Ji8fCc` “Supreme Autoparts” …2079; also …0156, …3707 |
| Ads payment method selected in Whop Ads UI | **Owner must confirm** in dashboard (Card selected for ads billing) |
| Pixel live on shop | After this deploy + ~1–2 min of traffic, re-check pixel page / `events_validate_pixel` |

**Rule:** Do **not** create/launch a Meta campaign that spends until Meta is connected **and** a Whop Card is selected as the ads payment method.

---

## Owner steps — connect Meta + Card (required before spend)

1. Open https://whop.com/dashboard/biz_9VJcCdK7G30L63  
2. Go to **Ads** (Growth / Ads).  
3. **Connect Facebook Page + Instagram** via OAuth (needs `advertise` scope). Use a Page you admin.  
4. Under ads **payment method**, select a Whop Card (prefer **Supreme Autoparts** `…2079` / `icrd_kK0kxp7Ji8fCc`, or another active card).  
5. Confirm pixel status is green on https://whop.com/dashboard/biz_9VJcCdK7G30L63/pixel (domains should list `supremeautoparts.co.ke`).  
6. Tell the agent “Meta connected + Card selected” so a **draft or live** US campaign can be created safely.

---

## Planned US campaign (~$9–10 lifetime) — ready to create after blockers clear

| Field | Value |
| --- | --- |
| Platform | `meta` |
| Title | `US shop — Supreme Autoparts $10` |
| Objective | **`sales`** if pixel validated; else **`traffic`** |
| Budget | Campaign-level: `budget_optimization: ad_campaign`, `budget_type: lifetime`, `budget_amount: 10` (USD) |
| Bid | `minimum_cost` (most results for budget) |
| Region | `regions.include.countries: ["US"]` |
| Destination | `https://www.supremeautoparts.co.ke/` (shop) |
| Conversion location | `website` |
| Optimization (Sales) | `conversions` + `conversion_event: purchase` (or `landing_page_views` / `link_clicks` if Traffic) |
| CTA | `shop_now` |
| Creative | Owner uploads 1 image/video (`file_…`); agent attaches via `ads_create` |
| Status at create | Prefer **draft** then `ad-campaigns_update` → active only after Meta + Card confirmed |

### API sketch (after Meta + Card)

1. `ad-campaigns_create` — objective sales/traffic, platform meta, lifetime $10, title as above.  
2. `ad-groups_create` — regions US, conversion_location website, optimization_goal conversions (or link_clicks), demographics automatic optional.  
3. `ads_create` — url shop, call_to_action shop_now, creatives + social_accounts (Facebook Page `sacc_…`), headlines/primary_texts.  
4. Launch only when payment method connected; else leave draft.

### Suggested copy (owner can edit)

- Headline: `OEM-quality auto parts — ship to the US`  
- Primary: `Shop Supreme Autoparts. Clear USD checkout. Find parts by number, brand, make & model.`  
- Description: `Secure checkout · Worldwide display currency · Charged in USD`

---

## Verify after deploy

```text
curl -sL https://www.supremeautoparts.co.ke/ | grep -E 't\.whop\.tw|whop\.setScope|biz_9VJcCdK7G30L63'
```

Or Whop: `events_validate_pixel` with `account_id=biz_9VJcCdK7G30L63` and `url=https://www.supremeautoparts.co.ke/`.

## Meta connect (OAuth link generated 2026-09-30 EAT)

Owner must open this while logged into the Facebook account that admins the Page:

https://www.facebook.com/v25.0/dialog/oauth?auth_type=rerequest&client_id=3885443075092968&redirect_uri=https%3A%2F%2Fwhop.com%2Fcore%2Fapi%2Fcallback%2Fmeta%2F&response_type=code&scope=instagram_basic%2Cpages_show_list%2Cpages_read_engagement%2Cbusiness_management%2Cads_read%2Cads_management&state=eea446092b155e1d0513a35075d91ed1

Or start again from Whop Ads UI / `social-accounts_connect` (platform `meta_business`, scopes `advertise`).


## Live US Meta campaign (2026-09-30 EAT)

| Field | Value |
| --- | --- |
| Campaign ID | `adcamp_hfeEPR6lWM6` |
| Ad group | `adgrp_4z4C3L3lR57X` |
| Ad | `ad_bjDDYG2Afn5Kzvu` |
| Facebook page | `sacc_ZdkPh88howiFa` (Whop-managed, Standard Driveworks) |
| Objective | sales (purchase / website) |
| Spend cap | **lifetime $10 USD** (campaign-level) |
| Ends | ~2026-10-02 13:51 UTC (~47h; Meta $5/day min) |
| Payment | platform_balance `ldgr_0YILGy67R2mFG` |
| Ads agreement | signed by Cavin Lugai Gwehona |
| Meta Pixel | mu-plugin `supreme-meta-pixel.php` ID `1455607103130157` (deploy required) |

## Optimization pass (2026-09-30 EAT ~18:20)

- Ad `ad_bjDDYG2Afn5Kzvu` upgraded: genius copy + square creative `file_7geUwtVXQ2cD0`; URL `/shop/`.
- Vertical ad `ad_ZDYcFzDEQnMjbKt` added for Stories/Reels.
- Meta Pixel mu-plugin now fires Purchase / AddToCart / InitiateCheckout / ViewContent / Search (v1.1).
- US shipping zone live in code (ver 3): Continental US $8 / Priority $15 / Free $99+.
- Ads payment: platform_balance `ldgr_0YILGy67R2mFG` (~$14.98 USDT). Whop Cards •2079/•3707 exist; personal CC not used.


## WELCOME30 new-customer offer (2026-09-30 EAT ~18:35)

| Field | Value |
| --- | --- |
| Coupon | `WELCOME30` — 30% percent, individual use, 1× per customer |
| Scope | **Worldwide** on site (banner + cart/checkout); Meta ads still US-targeted |
| Seed | `includes/welcome-coupon.php` (ver `sa_welcome_coupon_ver=1`) |
| Landing | `https://www.supremeautoparts.co.ke/shop/?coupon=WELCOME30` |
| Ads | Updated `ad_bjDDYG2Afn5Kzvu` + `ad_ZDYcFzDEQnMjbKt` creatives `file_Gasl1vCsvHYrf` / `file_iH5qycLjSL5bN` |
| Budget | Unchanged — campaign lifetime **$10** (`adcamp_hfeEPR6lWM6`) |
