# Supreme Autoparts — shipping rates (USD)

Configured by `sa_core_ensure_shipping_zones()` in `wp-content/plugins/supreme-autoparts-core/includes/shipping.php`  
(version option: `sa_shipping_zones_ver`, current **5**).

Checkout currency is **USD**. Totals recalculate when the customer changes address or shipping method.

Storefront copy is **US-primary** (continental US free shipping threshold, Standard / Priority). Kenya methods remain for KE addresses.

## United States zone (`US`) — primary

| Method | Type | Rate |
|--------|------|------|
| Free shipping (orders $99+) | `free_shipping` / min_amount | $0 when cart subtotal ≥ **$99** (env/option `SUPREME_FREE_SHIPPING_THRESHOLD` / `sa_free_shipping_threshold`) |
| Standard Shipping (Continental US) | `flat_rate` | **$8.00** |
| Priority Shipping (US) | `flat_rate` | **$15.00** |

## Kenya zone (`KE`) — secondary

| Method | Type | Rate |
|--------|------|------|
| Free shipping (orders $99+) | `free_shipping` / min_amount | $0 when cart subtotal ≥ **$99** |
| Fargo Courier | `sa_fargo` | Fargo branch rate (KES, first 5 kg) + KES 65/kg above 5 kg (100 or 150/kg for remote towns), converted to USD. Town from City field, else county default branch. Card: `docs/fargo-rates.json`. Products without weight count as 1 kg. Wajir & Mandera: no branch on card, method hidden. |
| Local pickup (Nairobi) | `local_pickup` | **$0.00** |

## Rest of world

| Method | Type | Rate |
|--------|------|------|
| Free shipping (orders $99+) | `free_shipping` / min_amount | $0 when subtotal ≥ **$99** |
| International shipping | `flat_rate` | **$25.00** |

## Notes

- Free-shipping threshold matches the site banner (default $99 USD).
- Oversized / supplier-direct exclusions are product-level; always confirm at checkout.
- Shipping calculator enabled on cart; destination defaults follow billing address / geolocation.
- Customer-facing copy: Shipping Policy, Free Shipping, How to Order (US-primary). Shipping to Kenya remains a secondary guide page.
