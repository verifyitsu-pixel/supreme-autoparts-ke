<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * G4S + Speedaf courier shipping for Kenya (Rock 2026-10-17: checkout build = Fargo, G4S, Speedaf only).
 *
 * Everything here is inert unless sa_couriers_enabled() is true:
 *   - option sa_couriers_enabled = 'yes' (master switch, ON by default since 2026-10-19), or
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
    // Live for Kenya since 2026-10-19 (user approval). Kill switch: option sa_couriers_live = 'no'
    // or env SUPREME_COURIERS_DISABLED=1. Weights come from product data on every calculation.
    $opt = get_option('sa_couriers_live', 'yes');
    if ($opt === 'yes') {
        return getenv('SUPREME_COURIERS_DISABLED') !== '1';
    }
    $env = getenv('SUPREME_COURIERS_ENABLED');
    if ($env === '1' || $env === 'yes') {
        return true;
    }
    if (isset($_GET['sa_couriers']) && $_GET['sa_couriers'] === '1') { // phpcs:ignore
        return true;
    }
    // Preview flag persists via cookie so it survives wc-ajax calls (update_order_review etc.)
    return isset($_COOKIE['sa_couriers_preview']) && $_COOKIE['sa_couriers_preview'] === '1';
}

add_action('init', function () {
    if (!isset($_GET['sa_couriers']) || headers_sent()) { // phpcs:ignore
        return;
    }
    $path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
    if ($_GET['sa_couriers'] === '1') { // phpcs:ignore
        setcookie('sa_couriers_preview', '1', time() + HOUR_IN_SECONDS, $path, $domain, is_ssl(), true);
    } elseif ($_GET['sa_couriers'] === '0') { // phpcs:ignore
        setcookie('sa_couriers_preview', '', time() - 3600, $path, $domain, is_ssl(), true);
    }
});

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
function sa_courier_stations(string $slug, string $state, string $city, ?array $anchor_override = null): array
{
    $norm = static fn(string $s): string => function_exists('sa_fargo_norm') ? sa_fargo_norm($s) : strtolower(trim($s));
    $county = function_exists('sa_fargo_county_name') ? sa_fargo_county_name($state) : '';
    $cn = $norm($county);
    $tn = $norm($city);
    $anchor = $anchor_override ?? sa_courier_anchor($state, $city);
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
/** Stable station IDs and immutable order snapshots. Coordinates remain advisory only. */
function sa_station_records(string $slug): array
{
    $out = [];
    foreach ((array) (sa_courier_data($slug)['stations'] ?? []) as $station) {
        $clean = static fn($v): string => trim((string) preg_replace('/[\x{E000}-\x{F8FF}\x{200B}-\x{200D}]/u', '', (string) $v));
        $row = array_map($clean, array_intersect_key($station, array_flip(['name', 'town', 'county', 'address', 'source'])));
        $id = $slug . ':' . substr(hash('sha256', implode('|', [$row['name'] ?? '', $row['town'] ?? '', $row['county'] ?? ''])), 0, 20);
        $row['id'] = $id;
        $row['courier'] = $slug;
        $out[$id] = $row;
    }
    return $out;
}

function sa_selected_station(): ?array
{
    $chosen = (array) ($_POST['shipping_method'] ?? []); // phpcs:ignore
    foreach ($chosen as $rate) {
        if (!preg_match('/^sa_(g4s|speedaf)[^_]*_pickup/', (string) $rate, $m)) continue;
        $id = sanitize_text_field(wp_unslash((string) ($_POST['sa_pickup_station'] ?? ''))); // phpcs:ignore
        $station = sa_station_records($m[1])[$id] ?? null;
        if (!$station) return null;
        $prefix = !empty($_POST['ship_to_different_address']) ? 'shipping_' : 'billing_'; // phpcs:ignore
        if (strtoupper(sanitize_text_field(wp_unslash((string) ($_POST[$prefix . 'country'] ?? '')))) !== 'KE') return null; // phpcs:ignore
        $state = sanitize_text_field(wp_unslash((string) ($_POST[$prefix . 'state'] ?? ''))); // phpcs:ignore
        $city = sanitize_text_field(wp_unslash((string) ($_POST[$prefix . 'city'] ?? ''))); // phpcs:ignore
        $county = function_exists('sa_fargo_county_name') ? sa_fargo_county_name($state) : '';
        $norm = static fn($v): string => function_exists('sa_fargo_norm') ? sa_fargo_norm((string) $v) : strtolower(trim((string) $v));
        if (($county !== '' && $norm($county) === $norm($station['county'] ?? '')) || ($city !== '' && $norm($city) === $norm($station['town'] ?? ''))) return $station;
        return null;
    }
    return null;
}

add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/courier-stations', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static function (WP_REST_Request $r) {
            $slug = (string) $r->get_param('courier');
            if (!in_array($slug, ['g4s', 'speedaf'], true) || !sa_couriers_enabled()) {
                return ['stations' => []];
            }
            // No distance labels: several legacy pins are only town/county anchors.
            $norm = static fn($v): string => function_exists('sa_fargo_norm') ? sa_fargo_norm((string) $v) : strtolower(trim((string) $v));
            $county = function_exists('sa_fargo_county_name') ? sa_fargo_county_name((string) $r->get_param('state')) : '';
            $city = (string) $r->get_param('city');
            $stations = [];
            foreach (sa_station_records($slug) as $id => $station) {
                $same_town = $city !== '' && $norm($city) === $norm($station['town'] ?? '');
                $same_county = $county !== '' && $norm($county) === $norm($station['county'] ?? '');
                if (!$same_town && !$same_county) continue;
                $address = $station['address'] ?? '';
                $stations[] = ['value' => $id, 'label' => ($station['name'] ?? '') . ', ' . ($station['town'] ?? ''), 'address' => $address, 'county' => $station['county'] ?? '', 'same_town' => $same_town, 'same_county' => $same_county];
            }
            usort($stations, static fn($a, $b): int => ($b['same_town'] <=> $a['same_town']) ?: strcmp($a['label'], $b['label']));
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
    $station = sa_selected_station();
    $order = wc_get_order($order_id);
    if ($station && $order) {
        $order->update_meta_data('_sa_pickup_station_id', $station['id']);
        $order->update_meta_data('_sa_pickup_station_snapshot', $station);
        // Keep existing admin/thankyou consumers compatible, now including the address.
        $order->update_meta_data('_sa_pickup_station', implode(', ', array_filter([$station['name'] ?? '', $station['town'] ?? '', $station['address'] ?? ''])));
        $order->save_meta_data();
    }
});

add_action('woocommerce_checkout_process', static function (): void {
    if (!sa_couriers_enabled()) return;
    foreach ((array) ($_POST['shipping_method'] ?? []) as $rate) { // phpcs:ignore
        if (preg_match('/^sa_(g4s|speedaf)[^_]*_pickup/', (string) $rate) && !sa_selected_station()) {
            wc_add_notice('Choose a listed courier pickup station for your delivery town or county.', 'error');
            break;
        }
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
var REST=<?php echo wp_json_encode($rest); ?>, sequence=0, signature='', records=[];
function val(id){var e=document.getElementById(id);return e?e.value:'';}
function rate(){var r=document.querySelector('input[name^="shipping_method"]:checked,input[name^="shipping_method"][type="hidden"]');return r?r.value:'';}
function refresh(){
  var wrap=document.getElementById('sa_pickup_station_field'),sel=document.getElementById('sa_pickup_station');if(!wrap||!sel)return;
  var delivery=document.querySelector('[data-sa-checkout-panel="shipping"] .sa-checkout-section__body');if(delivery&&wrap.parentElement!==delivery)delivery.appendChild(wrap);
  var match=rate().match(/^sa_(g4s|speedaf)[^_]*_pickup/),different=!!document.querySelector('#ship-to-different-address-checkbox:checked'),prefix=different?'shipping_':'billing_';
  var country=val(prefix+'country'),key=match&&country==='KE'?[match[1],val(prefix+'state'),val(prefix+'city')].join('|'):'';
  var note=wrap.querySelector('.sa-station-status');if(!note){note=document.createElement('p');note.className='sa-station-status';note.setAttribute('role','status');wrap.appendChild(note);}
  var detail=wrap.querySelector('.sa-station-selected');if(!detail){detail=document.createElement('p');detail.className='sa-station-selected';wrap.appendChild(detail);}
  var optional=wrap.querySelector('label .optional');if(optional)optional.hidden=!!key;
  var chosen=document.querySelector('input[name^="shipping_method"]:checked,input[name^="shipping_method"][type="hidden"]');var choice=chosen&&chosen.closest('li');if(key&&choice)choice.appendChild(wrap);
  sel.required=!!key;wrap.classList.toggle('validate-required',!!key);wrap.style.display=key?'':'none';
  if(!key){sequence++;signature='';sel.value='';detail.textContent='';return;}
  if(key===signature)return;signature=key;var request=++sequence;sel.replaceChildren(new Option('Choose the pickup station',''));sel.disabled=true;sel.dispatchEvent(new Event('change',{bubbles:true}));detail.textContent='';note.textContent='Loading stations for your delivery town or county.';
  fetch(REST+'?courier='+encodeURIComponent(match[1])+'&state='+encodeURIComponent(val(prefix+'state'))+'&city='+encodeURIComponent(val(prefix+'city')),{credentials:'same-origin'})
    .then(function(r){if(!r.ok)throw new Error('stations');return r.json();}).then(function(data){
      if(request!==sequence)return;records=Array.isArray(data.stations)?data.stations:[];var townGroup=document.createElement('optgroup');townGroup.label='In your delivery town';var countyGroup=document.createElement('optgroup');countyGroup.label='Other stations in your county';records.forEach(function(s){(s.same_town?townGroup:countyGroup).appendChild(new Option(s.label,s.value));});if(townGroup.children.length)sel.appendChild(townGroup);if(countyGroup.children.length)sel.appendChild(countyGroup);sel.disabled=false;sel.dispatchEvent(new Event('change',{bubbles:true}));
      note.textContent=records.length?'Choose a pickup station in your town or county. Other stations in your county are listed separately.':'No listed stations match this town or county. Check your address or choose another delivery option.';
    }).catch(function(){if(request!==sequence)return;signature='';sel.disabled=false;sel.dispatchEvent(new Event('change',{bubbles:true}));note.textContent='Stations could not load. Change the delivery option and try again, or reload checkout.';});
}
document.addEventListener('change',function(e){if(e.target&&e.target.id==='sa_pickup_station'){var station=records.find(function(s){return s.value===e.target.value;});var d=e.target.closest('.form-row').querySelector('.sa-station-selected');if(d)d.textContent=station?station.label+(station.same_town===false?' (another station in '+(station.county||'your county')+')':'')+' - '+(station.address&&station.address.toLowerCase()!==station.label.split(', ').pop().toLowerCase()?station.address:'Street details not supplied. Confirm the branch before travelling.') : '';return;}if(e.target&&/shipping_method|billing_(country|state|city)|shipping_(country|state|city)|ship-to-different/.test(e.target.name||e.target.id||''))refresh();});
if(window.jQuery)jQuery(document.body).on('updated_checkout',refresh);
setTimeout(refresh,800);
})();
</script>
    <?php
}, 60);
