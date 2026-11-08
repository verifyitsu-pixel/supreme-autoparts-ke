<?php
/**
 * Google Customer Reviews (GCR) survey opt-in module on the order confirmation page.
 *
 * Free Merchant Center programme that feeds Google store ratings (the stars shown on
 * Search/Shopping). Customers see a one-click opt-in after checkout; Google emails them
 * a short survey a few days after the estimated delivery date. Merchant Center ID
 * 5876902187 (Supreme Autoparts). Badge/store widget intentionally not added yet.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SA_GCR_MERCHANT_ID')) {
    define('SA_GCR_MERCHANT_ID', '5876902187');
}

/**
 * Estimated delivery date for the GCR survey. No promised windows exist in the shipping
 * setup, so use conservative buffers: Kenya courier +4 days, US +10, rest of world +14.
 */
function sa_gcr_estimated_delivery(WC_Order $o): string
{
    $country = strtoupper((string) ($o->get_shipping_country() ?: $o->get_billing_country()));
    $days = $country === 'KE' ? 4 : ($country === 'US' ? 10 : 14);
    $base = $o->get_date_paid() ?: $o->get_date_created();
    $ts = $base ? $base->getTimestamp() : time();
    return gmdate('Y-m-d', $ts + $days * DAY_IN_SECONDS);
}

/**
 * True when the order holds a new source-marked special-order item with no
 * verified lead time. Such orders have no honest estimated delivery date, so
 * the survey opt-in is withheld rather than sending Google an invented date.
 */
function sa_gcr_order_has_unknown_special_order(WC_Order $o): bool
{
    foreach ($o->get_items() as $item) {
        $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
        if (!$product instanceof WC_Product) {
            continue;
        }
        $pid = $product->get_parent_id() ?: $product->get_id();
        if (((string) get_post_meta((int) $pid, '_sa_source_special_order', true) === 'yes'
                || (string) get_post_meta((int) $pid, '_sa_source_orderable_unknown', true) === 'yes')
            && (string) get_post_meta((int) $pid, '_sa_stock_lead_days', true) === ''
        ) {
            return true;
        }
    }
    return false;
}

/* Render the opt-in module on the order confirmation page for paid orders only —
 * a survey invite must never go out for an unpaid, pending or failed order. */
add_action('woocommerce_thankyou', static function ($order_id): void {
    $o = wc_get_order($order_id);
    if (!$o instanceof WC_Order || !$o->is_paid()) {
        return;
    }
    if (sa_gcr_order_has_unknown_special_order($o)) {
        return; // no verified delivery window: do not invent a survey date.
    }
    $email = (string) $o->get_billing_email();
    if ($email === '' || !is_email($email)) {
        return;
    }
    $country = strtoupper((string) ($o->get_shipping_country() ?: $o->get_billing_country()));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        $country = 'KE';
    }
    $data = [
        'merchant_id'             => SA_GCR_MERCHANT_ID,
        'order_id'                => (string) $o->get_order_number(),
        'email'                   => $email,
        'delivery_country'        => $country,
        'estimated_delivery_date' => sa_gcr_estimated_delivery($o),
        'opt_in_style'            => 'CENTER_DIALOG',
    ];
    ?>
    <!-- BEGIN GCR Opt-in Module Code -->
    <script src="https://apis.google.com/js/platform.js?onload=renderOptIn" async defer></script>
    <script>
      window.renderOptIn = function() {
        window.gapi.load('surveyoptin', function() {
          window.gapi.surveyoptin.render(<?php echo wp_json_encode($data); ?>);
        });
      }
    </script>
    <!-- END GCR Opt-in Module Code -->
    <?php
}, 20);
