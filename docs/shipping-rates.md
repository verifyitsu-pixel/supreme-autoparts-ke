# Supreme Autoparts — shipping rates (USD)

Configured by `sa_core_ensure_shipping_zones()` in `wp-content/plugins/supreme-autoparts-core/includes/shipping.php`  
(version option: `sa_shipping_zones_ver`).

Checkout currency is **USD**. Totals recalculate when the customer changes address or shipping method.

## Kenya zone (`KE`)

| Method | Type | Rate |
|--------|------|------|
| Free shipping (orders $99+) | `free_shipping` / min_amount | $0 when cart subtotal ≥ **$99** (env/option `SUPREME_FREE_SHIPPING_THRESHOLD` / `sa_free_shipping_threshold`) |
| Nairobi Delivery | `flat_rate` | **$8.00** |
| Upcountry Kenya | `flat_rate` | **$15.00** |
| Local pickup (Nairobi) | `local_pickup` | **$0.00** |

## Rest of world

| Method | Type | Rate |
|--------|------|------|
| Free shipping (orders $99+) | `free_shipping` / min_amount | $0 when subtotal ≥ **$99** |
| International shipping | `flat_rate` | **$25.00** |

## Notes

- Free-shipping threshold matches the site banner (default $99 USD).
- Oversized / supplier-direct exclusions are product-level; always confirm at checkout.
- Shipping calculator enabled on cart; destination defaults follow billing address.
- Customer-facing copy: Shipping Policy, Shipping to Kenya, Free Shipping pages.
