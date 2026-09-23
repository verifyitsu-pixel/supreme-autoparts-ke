# Organic SEO & visibility (Supreme Autoparts)

Lean custom SEO lives in `wp-content/plugins/supreme-autoparts-core/includes/seo.php` (no Yoast/Rank Math). Pure PHP in `wp_head` — titles, meta descriptions, canonicals, robots, Open Graph, Twitter cards, Organization/WebSite/Store JSON-LD, FAQ where seeded. WooCommerce keeps Product + BreadcrumbList schema (USD offers).

**Markets:** one English site for **United States** and **Kenya** (US-spec catalog, USD checkout, Kenya WhatsApp/email support). No thin `en-US` / `en-KE` duplicate locales — skip hreflang unless you later split real language variants.

## After deploy (you do this)

1. **Google Search Console**
   - Add property: `https://www.supremeautoparts.co.ke` (URL-prefix is fine; Domain property if you control DNS).
   - Copy the HTML-tag verification token.
   - Set Railway env `SA_GOOGLE_SITE_VERIFICATION=<token>` (or WP option `sa_google_site_verification`) and redeploy / wait for boot.
   - Confirm ownership, then **Sitemaps → add** `https://www.supremeautoparts.co.ke/wp-sitemap.xml`.
   - Optional: set geographic targets only if you use legacy tools; prefer letting US + KE queries compete naturally on one property.

2. **Bing Webmaster Tools** (optional): import from GSC or verify the same domain; submit the same sitemap.

3. **Google Merchant Center** (later, for free product listings)
   - Claim website + verify (same domain).
   - Products are **USD**; shipping/returns must match live policies.
   - Feed: start from WooCommerce product CSV/XML or a Merchant plugin when catalog import is stable — do not invent GTIN/MPN; use brand + SKU when present.
   - Shipping: configure US and KE (or “rest of world”) destinations you actually fulfill.

4. **Social previews**
   - Share home and a product URL into WhatsApp / Telegram / X — expect logo or product image via `og:image`.
   - PDP has **Share this part** (native share / copy link / WhatsApp).

## Guide URLs (seeded on deploy)

| Path | Intent |
|------|--------|
| `/guides/` | Hub |
| `/how-to-order/` | US + KE ordering |
| `/fitment-guide/` | Year/make/model |
| `/shipping-to-kenya/` | KE import shipping |
| `/auto-parts-kenya/` | KE commercial intent |
| `/performance-truck-parts/` | US truck/off-road intent |

Overrides: post meta `_sa_seo_title`, `_sa_seo_description`, `_sa_seo_faq`.

## Hygiene already handled in code

- `noindex` on cart, checkout, My Account, thank-you, search, `/pay/`
- Users sitemap provider removed (thin)
- Utility pages omitted from page sitemap entries where filtered
- Product image `alt` falls back to product title
- Internal links: homepage guides strip + footer Help links

## BIMI / email

Email logo / BIMI DNS is separate (`docs/email-sender-avatar.md`). Not required for organic web search.
