<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kenya storefront shipping zones (USD rates — checkout currency).
 *
 * Versioned via sa_shipping_zones_ver. Idempotent: recreates SA-managed methods
 * when the version bumps; leaves unrelated custom zones alone.
 *
 * Rates (USD):
 * - Nairobi Delivery ........ $8.00  (flat_rate)
 * - Upcountry Kenya ......... $15.00 (flat_rate)
 * - Free shipping ........... $0 when cart subtotal ≥ $99 (free_shipping)
 * - Local pickup (Nairobi) .. $0.00  (local_pickup)
 * - International ........... $25.00 (Rest of World flat_rate)
 */
const SA_SHIPPING_ZONES_VER = '2';

/**
 * Free-shipping minimum in store currency (USD).
 */
function sa_core_shipping_free_min(): float
{
    $raw = getenv('SUPREME_FREE_SHIPPING_THRESHOLD');
    if ($raw === false || $raw === '') {
        $raw = get_option('sa_free_shipping_threshold', '99');
    }
    $usd = (float) $raw;
    if ($usd <= 0 || $usd > 1000) {
        $usd = 99.0;
    }
    return $usd;
}

/**
 * Ensure Woo shipping methods exist and Kenya / ROW zones are configured.
 */
function sa_core_ensure_shipping_zones(): void
{
    if (!class_exists('WooCommerce') || !class_exists('WC_Shipping_Zones')) {
        return;
    }

    // Enable shipping + calculator + destination defaults for Kenya.
    update_option('woocommerce_ship_to_countries', '');
    update_option('woocommerce_ship_to_destinations', 'billing');
    update_option('woocommerce_enable_shipping_calc', 'yes');
    update_option('woocommerce_shipping_cost_requires_address', 'no');
    // Geolocate (with page caching support) — sa-geo-currency also uses CF-IPCountry.
    update_option('woocommerce_default_customer_address', 'geolocation_ajax');

    $free_min = sa_core_shipping_free_min();

    // --- Kenya zone ---
    $ke_zone = sa_core_find_or_create_zone('Kenya', [['country' => 'KE']], 10);
    if ($ke_zone) {
        sa_core_sync_zone_methods($ke_zone, [
            [
                'id'       => 'flat_rate',
                'title'    => 'Nairobi Delivery',
                'cost'     => '8',
                'tax'      => 'no',
                'order'    => 1,
                'meta_key' => '_sa_ship_code',
                'meta_val' => 'nairobi',
            ],
            [
                'id'       => 'flat_rate',
                'title'    => 'Upcountry Kenya',
                'cost'     => '15',
                'tax'      => 'no',
                'order'    => 2,
                'meta_key' => '_sa_ship_code',
                'meta_val' => 'upcountry',
            ],
            [
                'id'         => 'free_shipping',
                'title'      => 'Free shipping (orders $' . rtrim(rtrim(number_format($free_min, 2, '.', ''), '0'), '.') . '+)',
                'requires'   => 'min_amount',
                'min_amount' => (string) $free_min,
                'order'      => 0,
                'meta_key'   => '_sa_ship_code',
                'meta_val'   => 'free',
            ],
            [
                'id'       => 'local_pickup',
                'title'    => 'Local pickup (Nairobi)',
                'cost'     => '0',
                'tax'      => 'no',
                'order'    => 3,
                'meta_key' => '_sa_ship_code',
                'meta_val' => 'pickup',
            ],
        ]);
    }

    // --- Rest of world ---
    $row = WC_Shipping_Zones::get_zone(0);
    if ($row) {
        sa_core_sync_zone_methods($row, [
            [
                'id'       => 'flat_rate',
                'title'    => 'International shipping',
                'cost'     => '25',
                'tax'      => 'no',
                'order'    => 1,
                'meta_key' => '_sa_ship_code',
                'meta_val' => 'intl',
            ],
            [
                'id'         => 'free_shipping',
                'title'      => 'Free shipping (orders $' . rtrim(rtrim(number_format($free_min, 2, '.', ''), '0'), '.') . '+)',
                'requires'   => 'min_amount',
                'min_amount' => (string) $free_min,
                'order'      => 0,
                'meta_key'   => '_sa_ship_code',
                'meta_val'   => 'free_intl',
            ],
        ]);
    }

    update_option('sa_shipping_zones_ver', SA_SHIPPING_ZONES_VER);
    update_option('sa_shipping_rates_doc', [
        'currency' => 'USD',
        'updated'  => gmdate('c'),
        'kenya'    => [
            'nairobi_delivery' => 8.0,
            'upcountry'        => 15.0,
            'free_min'         => $free_min,
            'local_pickup'     => 0.0,
        ],
        'international' => [
            'flat'     => 25.0,
            'free_min' => $free_min,
        ],
    ]);
}

/**
 * @param array<int, array{country?:string,state?:string}> $locations
 */
function sa_core_find_or_create_zone(string $name, array $locations, int $order = 0): ?WC_Shipping_Zone
{
    foreach (WC_Shipping_Zones::get_zones() as $z) {
        if (!is_array($z)) {
            continue;
        }
        $zone_name = (string) ($z['zone_name'] ?? '');
        if (strcasecmp($zone_name, $name) === 0) {
            $zone = WC_Shipping_Zones::get_zone((int) $z['id']);
            if ($zone instanceof WC_Shipping_Zone) {
                $zone->set_zone_order($order);
                $zone->set_locations($locations);
                $zone->save();
                return $zone;
            }
        }
    }

    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name($name);
    $zone->set_zone_order($order);
    $zone->set_locations($locations);
    $zone->save();
    return $zone;
}

/**
 * Replace SA-managed methods on a zone (matched by _sa_ship_code meta).
 *
 * @param array<int, array<string,mixed>> $desired
 */
function sa_core_sync_zone_methods(WC_Shipping_Zone $zone, array $desired): void
{
    $existing = $zone->get_shipping_methods(true);
    $by_code  = [];
    foreach ($existing as $instance_id => $method) {
        if (!is_object($method) || !method_exists($method, 'get_instance_id')) {
            continue;
        }
        $code = '';
        if (method_exists($method, 'get_option')) {
            // Stored in instance settings under our key when present.
            $settings = get_option('woocommerce_' . $method->id . '_' . (int) $instance_id . '_settings', []);
            if (is_array($settings) && isset($settings['_sa_ship_code'])) {
                $code = (string) $settings['_sa_ship_code'];
            }
        }
        // Also match by known titles from prior boots.
        if ($code === '' && isset($method->title)) {
            $title = strtolower((string) $method->title);
            if (str_contains($title, 'nairobi') && str_contains($title, 'deliver')) {
                $code = 'nairobi';
            } elseif (str_contains($title, 'upcountry')) {
                $code = 'upcountry';
            } elseif (str_contains($title, 'local pickup') || str_contains($title, 'pickup')) {
                $code = 'pickup';
            } elseif (str_contains($title, 'international')) {
                $code = 'intl';
            } elseif (str_contains($title, 'free shipping')) {
                $code = str_contains($title, 'intl') ? 'free_intl' : 'free';
            }
        }
        if ($code !== '') {
            $by_code[$code] = (int) $instance_id;
        }
    }

    foreach ($desired as $spec) {
        $code = (string) ($spec['meta_val'] ?? '');
        $id   = (string) ($spec['id'] ?? 'flat_rate');
        $instance_id = $by_code[$code] ?? 0;

        if ($instance_id <= 0) {
            $instance_id = $zone->add_shipping_method($id);
            if (!$instance_id) {
                continue;
            }
        }

        $opt_key = 'woocommerce_' . $id . '_' . (int) $instance_id . '_settings';
        $settings = get_option($opt_key, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['title']    = (string) ($spec['title'] ?? $id);
        $settings['tax_status'] = (string) ($spec['tax'] ?? 'none');
        if (isset($spec['cost'])) {
            $settings['cost'] = (string) $spec['cost'];
        }
        if (isset($spec['requires'])) {
            $settings['requires'] = (string) $spec['requires'];
        }
        if (isset($spec['min_amount'])) {
            $settings['min_amount'] = (string) $spec['min_amount'];
        }
        $settings['enabled'] = 'yes';
        $settings['_sa_ship_code'] = $code;
        update_option($opt_key, $settings);

        // Keep method enabled at zone level.
        $methods = $zone->get_shipping_methods(true);
        if (isset($methods[$instance_id]) && is_object($methods[$instance_id])) {
            $m = $methods[$instance_id];
            if (method_exists($m, 'update_from_api_request') === false) {
                // Force enabled via DB row if needed.
                global $wpdb;
                if (isset($wpdb) && $wpdb instanceof wpdb) {
                    $wpdb->update(
                        "{$wpdb->prefix}woocommerce_shipping_zone_methods",
                        [
                            'is_enabled' => 1,
                            'method_order' => (int) ($spec['order'] ?? 0),
                        ],
                        ['instance_id' => (int) $instance_id],
                        ['%d', '%d'],
                        ['%d']
                    );
                }
            }
        }
        unset($by_code[$code]);
    }
}

add_action('init', static function (): void {
    if (get_option('sa_shipping_zones_ver') === SA_SHIPPING_ZONES_VER) {
        return;
    }
    if (!class_exists('WooCommerce')) {
        return;
    }
    sa_core_ensure_shipping_zones();
}, 35);

// Refresh WC shipping cache after zone sync on admin requests.
add_action('woocommerce_init', static function (): void {
    if (get_option('sa_shipping_zones_ver') !== SA_SHIPPING_ZONES_VER) {
        sa_core_ensure_shipping_zones();
        if (function_exists('WC') && WC()->shipping()) {
            WC()->shipping()->load_shipping_methods();
        }
    }
}, 40);
