<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * G4S + Speedaf courier shipping for Kenya (Rock 2026-10-17: checkout build = Fargo, G4S, Speedaf only).
 *
 * Everything here is inert unless sa_couriers_enabled() is true:
 *   - option sa_couriers_enabled = 'yes' (master switch, off by default), or
 *   - SUPREME_COURIERS_ENABLED env = '1', or
 *   - ?sa_couriers=1 in the URL (preview for screenshots/review before go-live).
 *
 * Rules (Rock, this session):
 *   - A courier only shows where it has an office in/near the customer's county.
 *   - Station ranking: customer's own town first, then own county, then nearest (Jumia model).
 *   - Pickup at a station = base rate; door delivery = base + KES 250 on these NEW methods.
 *   - Checkout auto-suggests stations by actual distance from the customer's address
 *     (own town, then own county, then nearest by lat/lng) and preselects the closest
 *     (Rock 2026-10-17 10:54); door delivery is the alternative.
 *   - Speedaf prices from its own 2025 tariff (data/speedaf-tariff.json, origin Nairobi Region).
 *     G4S prices from the Fargo rate card math (no G4S tariff supplied).
 */

const SA_COURIER_DOOR_KES = 250.0;
const SA_COURIER_NEAR_KM = 100.0;   // "near the customer's county" cutoff
const SA_COURIER_MAX_STATIONS = 15;

function sa_couriers_enabled(): bool
{
    if (get_option('sa_couriers_enabled', 'no') === 'yes') {
        return true;
    }
    $env = getenv('SUPREME_COURIERS_ENABLED');
    if ($env === '1' || $env === 'yes') {
        return true;
    }
    return isset($_GET['sa_couriers']) && $_GET['sa_couriers'] === '1'; // phpcs:ignore
}

function sa_courier_data(string $slug): array
{
    static $cache = [];
    if (!isset($cache[$slug])) {
        $raw = @file_get_contents(SA_CORE_DIR . 'data/courier-' . $slug . '.json');
        $cache[$slug] = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }
    return $cache[$slug];
}

function sa_speedaf_tariff(): array
{
    static $t = null;
    if ($t === null) {
        $raw = @file_get_contents(SA_CORE_DIR . 'data/speedaf-tariff.json');
        $t = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }
    return $t;
}

/** Town -> [lat,lng] from every pinned dataset, for nearest-station ranking. */
function sa_courier_town_coords(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $norm = static function (string $s): string {
        return function_exists('sa_fargo_norm') ? sa_fargo_norm($s) : strtolower(trim($s));
    };
    $add = static function (string $town, $lat, $lng) use (&$map, $norm): void {
        $k = $norm($town);
        if ($k !== '' && is_numeric($lat) && is_numeric($lng) && !isset($map[$k])) {
            $map[$k] = [(float) $lat, (float) $lng];
        }
    };
    $raw = @file_get_contents(SA_CORE_DIR . 'data/fargo-branches.json');
    $fb = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    foreach ((array) $fb as $b) {
        if (is_array($b)) {
            $add((string) ($b['city'] ?? ''), $b['lat'] ?? null, $b['lng'] ?? null);
        }
    }
    foreach (['speedaf', 'g4s'] as $slug) {
        foreach ((array) (sa_courier_data($slug)['stations'] ?? []) as $s) {
            if (is_array($s) && !empty($s['maps_verified'])) {
                $add((string) ($s['town'] ?? ''), $s['lat'] ?? null, $s['lng'] ?? null);
            }
        }
    }
    return $map;
}

/** Customer anchor coords: their typed town, else the county's default branch town. */
function sa_courier_anchor(string $state, string $city): ?array
{
    $coords = sa_courier_town_coords();
    $norm = static fn(string $s): string => function_exists('sa_fargo_norm') ? sa_fargo_norm($s) : strtolower(trim($s));
    $c = $norm($city);
    if ($c !== '' && isset($coords[$c])) {
        return $coords[$c];
    }
    if (function_exists('sa_fargo_county_name') && function_exists('sa_fargo_card')) {
        $county = sa_fargo_county_name($state);
        $dt = (string) (sa_fargo_card()['county_default_town'][$county] ?? '');
        $d = $norm($dt);
        if ($d !== '' && isset($coords[$d])) {
            return $coords[$d];
        }
    }
    return null;
}

function sa_courier_km(array $a, array $b): float
{
    $r = 6371.0;
    $dla = deg2rad($b[0] - $a[0]);
    $dlo = deg2rad($b[1] - $a[1]);
    $h = sin($dla / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dlo / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($h)));
}

/**
 * Stations for a courier near the customer: own town, then own county, then nearest by
 * distance, capped. Empty when the courier has no office in/near the county.
 *
 * @return array<int, array{name:string,town:string,county:string,address:string,same_town:bool,same_county:bool,km:float|null}>
 */
function sa_courier_stations(string $slug, string $state, string $city): array
{
    $norm = static fn(string $s): string => function_exists('sa_fargo_norm') ? sa_fargo_norm($s) : strtolower(trim($s));
    $county = function_exists('sa_fargo_county_name') ? sa_fargo_county_name($state) : '';
    $cn = $norm($county);
    $tn = $norm($city);
    $anchor = sa_courier_anchor($state, $city);
    $out = [];
    foreach ((array) (sa_courier_data($slug)['stations'] ?? []) as $s) {
        if (!is_array($s)) {
            continue;
        }
        $same_town = $tn !== '' && $norm((string) ($s['town'] ?? '')) === $tn;
        $same_county = $cn !== '' && $norm((string) ($s['county'] ?? '')) === $cn;
        $km = null;
        if ($anchor && is_numeric($s['lat'] ?? null) && is_numeric($s['lng'] ?? null)) {
            $km = sa_courier_km($anchor, [(float) $s['lat'], (float) $s['lng']]);
        }
        // In/near rule: same county always qualifies; otherwise only within SA_COURIER_NEAR_KM.
        if (!$same_town && !$same_county && ($km === null || $km > SA_COURIER_NEAR_KM)) {
            continue;
        }
        $out[] = [
            'name' => (string) ($s['name'] ?? ''),
            'town' => (string) ($s['town'] ?? ''),
            'county' => (string) ($s['county'] ?? ''),
            'address' => (string) ($s['address'] ?? ''),
            'same_town' => $same_town,
            'same_county' => $same_county,
            'km' => $km,
        ];
    }
    usort($out, static function (array $a, array $b): int {
        if ($a['same_town'] !== $b['same_town']) {
            return $a['same_town'] ? -1 : 1;
        }
        if ($a['same_county'] !== $b['same_county']) {
            return $a['same_county'] ? -1 : 1;
        }
        return ($a['km'] ?? 9999) <=> ($b['km'] ?? 9999);
    });
    return array_slice($out, 0, SA_COURIER_MAX_STATIONS);
}

/** Speedaf tariff region for a destination town/county ('' when not on the tariff). */
function sa_speedaf_region(string $state, string $city): string
{
    $norm = static fn(string $s): string => function_exists('sa_fargo_norm') ? sa_fargo_norm($s) : strtolower(trim($s));
    $t = sa_speedaf_tariff();
    $lookup = [];
    foreach ((array) ($t['region_towns'] ?? []) as $region => $towns) {
        foreach ((array) $towns as $town) {
            $lookup[$norm((string) $town)] = (string) $region;
        }
    }
    $c = $norm($city);
    if ($c !== '' && isset($lookup[$c])) {
        return $lookup[$c];
    }
    if (function_exists('sa_fargo_county_name') && function_exists('sa_fargo_card')) {
        $county = sa_fargo_county_name($state);
        $dt = $norm((string) (sa_fargo_card()['county_default_town'][$county] ?? ''));
        if ($dt !== '' && isset($lookup[$dt])) {
            return $lookup[$dt];
        }
    }
    return '';
}

/** Speedaf fee in KES from Nairobi Region to the destination region. */
function sa_speedaf_fee_kes(string $region_to, float $kg): ?float
{
    $t = sa_speedaf_tariff();
    $row = $t['rates']['Nairobi Region'][$region_to] ?? null;
    if (!is_array($row)) {
        return null;
    }
    $over = max(0.0, ceil(round($kg - 5.0, 3)));
    return (float) $row['base_0_5kg'] + $over * (float) $row['extra_kg'];
}

add_action('woocommerce_shipping_init', static function (): void {
    if (class_exists('SA_Courier_Shipping_Method')) {
        return;
    }

    class SA_Courier_Shipping_Method extends WC_Shipping_Method
    {
        protected string $courier_slug = '';
        protected string $courier_title = '';

        public function __construct($instance_id = 0)
        {
            $this->instance_id = absint($instance_id);
            $this->supports = ['shipping-zones', 'instance-settings'];
            $this->instance_form_fields = [
                'title' => ['title' => 'Title', 'type' => 'text', 'default' => $this->courier_title],
            ];
            $this->method_description = $this->courier_title . ' for Kenya: station pickup or door delivery, offices near the customer only.';
            $this->init_settings();
            $this->method_title = $this->courier_title;
            $this->title = (string) $this->get_option('title', $this->courier_title);
            $this->enabled = 'yes';
        }

        /** Base fee in KES (pickup at station), or null when it must be confirmed manually. */
        protected function base_fee_kes(string $state, string $city, float $kg): ?float
        {
            return null;
        }

        public function calculate_shipping($package = []): void
        {
            if (!sa_couriers_enabled()) {
                return;
            }
            $dest = $package['destination'] ?? [];
            if (strtoupper((string) ($dest['country'] ?? '')) !== 'KE') {
                return;
            }
            $state = (string) ($dest['state'] ?? '');
            $city = (string) ($dest['city'] ?? '');
            // Courier only shows where it has an office in/near the customer's county.
            $stations = sa_courier_stations($this->courier_slug, $state, $city);
            if (!$stations) {
                return;
            }
            $kg = 0.0;
            foreach (($package['contents'] ?? []) as $item) {
                $kg += sa_fargo_item_kg($item['data'] ?? null) * (int) ($item['quantity'] ?? 1);
            }
            $base_kes = $this->base_fee_kes($state, $city, $kg);
            $meta = [
                'Courier' => $this->courier_title,
                'Stations near you' => (string) count($stations),
                'Weight (kg)' => round($kg, 2),
            ];
            if ($base_kes === null) {
                $this->add_rate([
                    'id' => $this->get_rate_id(),
                    'label' => $this->title . ', rate confirmed before dispatch',
                    'cost' => 0,
                    'taxes' => false,
                    'meta_data' => $meta,
                ]);
                return;
            }
            $pickup = sa_fargo_kes_to_store($base_kes);
            $door = sa_fargo_kes_to_store($base_kes + SA_COURIER_DOOR_KES);
            if ($pickup === null || $door === null) {
                return;
            }
            $nearest = $stations[0];
            $this->add_rate([
                'id' => $this->get_rate_id() . '_pickup',
                'label' => sprintf('%s, pickup at station (%s%s)', $this->title, $nearest['town'], $nearest['same_county'] || $nearest['same_town'] ? '' : ', nearest to you'),
                'cost' => $pickup,
                'taxes' => false,
                'meta_data' => $meta + ['Fee (KES)' => $base_kes],
            ]);
            $this->add_rate([
                'id' => $this->get_rate_id() . '_door',
                'label' => sprintf('%s, door delivery', $this->title),
                'cost' => $door,
                'taxes' => false,
                'meta_data' => $meta + ['Fee (KES)' => $base_kes + SA_COURIER_DOOR_KES],
            ]);
        }
    }

    class SA_G4S_Shipping_Method extends SA_Courier_Shipping_Method
    {
        public function __construct($instance_id = 0)
        {
            $this->id = 'sa_g4s';
            $this->courier_slug = 'g4s';
            $this->courier_title = 'G4S Courier';
            parent::__construct($instance_id);
        }

        protected function base_fee_kes(string $state, string $city, float $kg): ?float
        {
            // No G4S tariff supplied: Fargo rate card math per Rock's rule.
            $d = sa_fargo_destination($state, $city);
            return $d ? sa_fargo_fee_kes($d, $kg) : null;
        }
    }

    class SA_Speedaf_Shipping_Method extends SA_Courier_Shipping_Method
    {
        public function __construct($instance_id = 0)
        {
            $this->id = 'sa_speedaf';
            $this->courier_slug = 'speedaf';
            $this->courier_title = 'Speedaf Courier';
            parent::__construct($instance_id);
        }

        protected function base_fee_kes(string $state, string $city, float $kg): ?float
        {
            $region = sa_speedaf_region($state, $city);
            return $region !== '' ? sa_speedaf_fee_kes($region, $kg) : null;
        }
    }
});

add_filter('woocommerce_shipping_methods', static function (array $methods): array {
    $methods['sa_g4s'] = 'SA_G4S_Shipping_Method';
    $methods['sa_speedaf'] = 'SA_Speedaf_Shipping_Method';
    return $methods;
});

/* ---------- Pickup-station select at checkout ---------- */

/** Sorted stations for the checkout select (public, read-only). */
add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/courier-stations', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static function (WP_REST_Request $r) {
            $slug = (string) $r->get_param('courier');
            if (!in_array($slug, ['g4s', 'speedaf'], true) || !sa_couriers_enabled()) {
                return ['stations' => []];
            }
            $stations = array_map(static function (array $s): array {
                return [
                    'value' => $s['name'] . ', ' . $s['town'],
                    'label' => trim($s['town'] . ' - ' . $s['name'] . ($s['address'] !== '' ? ' (' . $s['address'] . ')' : '')),
                    'same_town' => $s['same_town'],
                    'same_county' => $s['same_county'],
                    'km' => $s['km'] !== null ? round($s['km'], 1) : null,
                ];
            }, sa_courier_stations($slug, (string) $r->get_param('state'), (string) $r->get_param('city')));
            return ['stations' => $stations];
        },
    ]);
});

/** Hidden field carried through checkout; JS turns it into a visible select when a pickup rate is chosen. */
add_filter('woocommerce_checkout_fields', static function (array $fields): array {
    if (!sa_couriers_enabled()) {
        return $fields;
    }
    $fields['billing']['sa_pickup_station'] = [
        'type' => 'select',
        'label' => 'Courier pickup station',
        'required' => false,
        'class' => ['form-row-wide', 'sa-pickup-station-wrap'],
        'options' => ['' => 'Choose the pickup station'],
        'priority' => 115,
    ];
    return $fields;
});

add_action('woocommerce_checkout_update_order_meta', static function ($order_id): void {
    if (!empty($_POST['sa_pickup_station'])) { // phpcs:ignore
        $o = wc_get_order($order_id);
        if ($o) {
            $o->update_meta_data('_sa_pickup_station', sanitize_text_field(wp_unslash((string) $_POST['sa_pickup_station']))); // phpcs:ignore
            $o->save_meta_data();
        }
    }
});

add_action('woocommerce_checkout_process', static function (): void {
    if (!sa_couriers_enabled()) {
        return;
    }
    $chosen = (array) ($_POST['shipping_method'] ?? []); // phpcs:ignore
    $is_pickup = false;
    foreach ($chosen as $rate_id) {
        if (preg_match('/^sa_(g4s|speedaf)[^_]*_pickup/', (string) $rate_id)) {
            $is_pickup = true;
        }
    }
    if ($is_pickup && empty($_POST['sa_pickup_station'])) { // phpcs:ignore
        wc_add_notice('Choose the courier station you want to pick up from.', 'error');
    }
});

add_action('woocommerce_thankyou', static function ($order_id): void {
    $o = wc_get_order($order_id);
    if ($o && ($st = (string) $o->get_meta('_sa_pickup_station')) !== '') {
        echo '<section class="sa-pickup-station"><h2 class="woocommerce-column__title">Pickup station</h2><p>' . esc_html($st) . '</p></section>';
    }
}, 4);

add_action('woocommerce_admin_order_data_after_shipping_address', static function ($o): void {
    if ($o instanceof WC_Order && ($st = (string) $o->get_meta('_sa_pickup_station')) !== '') {
        echo '<p><strong>Courier pickup station:</strong> ' . esc_html($st) . '</p>';
    }
});

/** Checkout JS: show/populate the station select when a courier pickup rate is chosen. */
add_action('wp_footer', static function (): void {
    if (!sa_couriers_enabled() || !function_exists('is_checkout') || !is_checkout()) {
        return;
    }
    $rest = esc_url_raw(rest_url('sa/v1/courier-stations'));
    ?>
<script>
(function(){
var REST=<?php echo wp_json_encode($rest); ?>;
function chosenRate(){var r=document.querySelector('input[name^="shipping_method"]:checked');return r?r.value:'';}
function courierOf(v){var m=v.match(/^sa_(g4s|speedaf)[0-9_]*_pickup/);return m?m[1]:null;}
function fieldWrap(){return document.getElementById('sa_pickup_station_field');}
function val(id){var e=document.getElementById(id);return e?e.value:'';}
function refresh(){
  var w=fieldWrap();if(!w)return;
  var c=courierOf(chosenRate());
  if(!c){w.style.display='none';return;}
  var u=REST+'?courier='+c+'&state='+encodeURIComponent(val('billing_state'))+'&city='+encodeURIComponent(val('billing_city'));
  fetch(u,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
    var sel=document.getElementById('sa_pickup_station');if(!sel)return;
    var list=(d&&d.stations)||[];
    if(!list.length){w.style.display='none';return;}
    var cur=sel.value;
    sel.innerHTML='<option value="">Choose the pickup station</option>'+list.map(function(s){return '<option value="'+String(s.value).replace(/"/g,'&quot;')+'">'+s.label+(s.km!=null?' ('+s.km+' km)':'')+'</option>';}).join('');
    if(cur){sel.value=cur;}
    if(!sel.value&&sel.options.length>1){sel.selectedIndex=1;}
    w.style.display='';
  }).catch(function(){w.style.display='none';});
}
if(window.jQuery){jQuery(document.body).on('updated_checkout change',refresh);}
document.addEventListener('change',function(e){if(e.target&&/shipping_method|billing_(state|city)/.test(e.target.name||e.target.id||'')){setTimeout(refresh,300);}});
setTimeout(refresh,800);
})();
</script>
    <?php
}, 60);
