<?php
/**
 * Front-end display conversion only. Order totals / Whop amounts stay USD.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SA_Geo_Display
{
    private static bool $busy = false;
    private static bool $note_printed = false;

    public static function init(): void
    {
        // Convert formatted prices on storefront (shop / PDP / cart / mini-cart).
        add_filter('wc_price', [self::class, 'filter_wc_price'], 20, 4);

        // Checkout / cart note.
        add_action('woocommerce_before_add_to_cart_form', [self::class, 'render_charge_note'], 5);
        add_action('woocommerce_single_product_summary', [self::class, 'render_charge_note_near_price'], 11);
        add_action('woocommerce_before_cart_totals', [self::class, 'render_charge_note'], 5);
        add_action('woocommerce_review_order_before_payment', [self::class, 'render_charge_note'], 5);
        add_action('woocommerce_before_mini_cart', [self::class, 'render_charge_note'], 5);
        add_action('wp_footer', [self::class, 'maybe_footer_note_script'], 99);

        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('send_headers', [self::class, 'send_vary_header']);

        // Expose display currency for theme tweaks.
        add_filter('body_class', [self::class, 'body_class']);
    }

    /**
     * Whether this request should show converted prices (never change order math).
     */
    public static function should_convert(): bool
    {
        if (self::$busy) {
            return false;
        }

        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }

        // Cron / CLI / REST order writes — keep USD.
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }
        if (wp_doing_cron()) {
            return false;
        }

        // Emails: keep USD (matches charged amount).
        if (doing_action('woocommerce_email_before_order_table') || doing_action('woocommerce_email_after_order_table')) {
            return false;
        }

        $display = SA_Geo_Detector::display_currency();
        $checkout = sa_geo_checkout_currency();
        if (strtoupper($display) === strtoupper($checkout)) {
            return false;
        }

        // Need a valid rate; else fall back to USD display.
        if (SA_FX_Rates::rate_for($display) === null) {
            return false;
        }

        return true;
    }

    /**
     * @param string               $html
     * @param float|string         $price   Formatted numeric fragment (already through raw filters)
     * @param array<string,mixed>  $args
     * @param float|string         $unformatted_price Original USD amount passed to wc_price()
     */
    public static function filter_wc_price($html, $price, $args, $unformatted_price): string
    {
        if (!self::should_convert()) {
            return (string) $html;
        }

        // If caller already forced a non-checkout currency, leave it.
        $forced = isset($args['currency']) ? strtoupper((string) $args['currency']) : '';
        $checkout = sa_geo_checkout_currency();
        if ($forced !== '' && $forced !== $checkout) {
            return (string) $html;
        }

        $usd = (float) $unformatted_price;
        $display = SA_Geo_Detector::display_currency();
        $converted = SA_FX_Rates::convert_usd($usd, $display);
        if ($converted === null) {
            return (string) $html;
        }

        self::$busy = true;
        try {
            $new_args = is_array($args) ? $args : [];
            $new_args['currency'] = $display;
            $out = wc_price($converted, $new_args);
        } finally {
            self::$busy = false;
        }

        return (string) $out;
    }

    public static function render_charge_note(): void
    {
        if (!self::should_show_note()) {
            return;
        }
        echo self::note_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        self::$note_printed = true;
    }

    public static function render_charge_note_near_price(): void
    {
        // Only once on PDP (summary already has price at priority 10).
        if (self::$note_printed) {
            return;
        }
        self::render_charge_note();
    }

    private static function should_show_note(): bool
    {
        if (!function_exists('is_woocommerce')) {
            return false;
        }
        // Show whenever geo display is active OR always on cart/checkout so shoppers know.
        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }
        return true;
    }

    public static function note_html(): string
    {
        $display = SA_Geo_Detector::display_currency();
        $checkout = sa_geo_checkout_currency();
        $country = SA_Geo_Detector::country_code();

        $extra = '';
        if (self::should_convert()) {
            $extra = sprintf(
                /* translators: 1: display currency 2: country code */
                ' ' . esc_html__('(showing approx. %1$s for %2$s)', 'sa-geo-currency'),
                esc_html($display),
                esc_html($country)
            );
        }

        return '<p class="sa-geo-currency-note" data-sa-geo-cc="' . esc_attr($country) . '" data-sa-geo-display="' . esc_attr($display) . '">'
            . esc_html__('Charged in USD at checkout', 'sa-geo-currency')
            . $extra
            . '</p>';
    }

    public static function enqueue_assets(): void
    {
        if (is_admin()) {
            return;
        }
        $css = '.sa-geo-currency-note{display:block;margin:.35rem 0 .75rem;font-size:.8rem;line-height:1.35;opacity:.85;color:inherit}'
            . '.sa-product-card .sa-geo-currency-note,.price .sa-geo-currency-note{font-size:.75rem;margin-top:.25rem}'
            . '.woocommerce-checkout .sa-geo-currency-note,.cart_totals .sa-geo-currency-note{font-weight:500}';
        wp_register_style('sa-geo-currency', false, [], SA_GEO_CURRENCY_VERSION);
        wp_enqueue_style('sa-geo-currency');
        wp_add_inline_style('sa-geo-currency', $css);
    }

    public static function send_vary_header(): void
    {
        if (headers_sent()) {
            return;
        }
        // So Cloudflare / CDN can vary HTML cache by visitor country.
        header('Vary: CF-IPCountry', false);
    }

    /**
     * @param string[] $classes
     * @return string[]
     */
    public static function body_class(array $classes): array
    {
        $classes[] = 'sa-geo-currency';
        $classes[] = 'sa-geo-cc-' . strtolower(SA_Geo_Detector::country_code());
        $classes[] = 'sa-geo-cur-' . strtolower(SA_Geo_Detector::display_currency());
        return $classes;
    }

    /**
     * Inject a small note under loop prices once if theme did not hook us.
     */
    public static function maybe_footer_note_script(): void
    {
        if (is_admin() || self::$note_printed) {
            return;
        }
        if (!function_exists('is_shop') || (!is_shop() && !is_product_category() && !is_product_tag() && !is_product())) {
            return;
        }
        // Soft inject after first .price on shop cards when note missing.
        $html = wp_json_encode(self::note_html());
        echo '<script>(function(){try{var n=' . $html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            . ';if(!n)return;var p=document.querySelector(".summary .price, .sa-product-card__price");'
            . 'if(p&&!document.querySelector(".sa-geo-currency-note")){p.insertAdjacentHTML("afterend",n);}'
            . '}catch(e){}})();</script>';
    }
}
