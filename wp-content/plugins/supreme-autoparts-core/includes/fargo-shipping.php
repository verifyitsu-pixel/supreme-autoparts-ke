<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fargo Courier shipping for Kenya addresses.
 *
 * Fee (KES, VAT incl.) = destination branch rate (covers first 5 kg)
 *   + excess kg above 5 x 65 / 100 (starred towns) / 150 (remote towns).
 * Branch: town typed/selected in the City field if it is on the card,
 * otherwise the county's default branch. Converted KES -> store currency (USD)
 * via SA_FX_Rates. Rate card: data/fargo-rates.json (see docs/fargo-rates.json).
 */

const SA_FARGO_DEFAULT_ITEM_KG = 1.0;

function sa_fargo_card(): array
{
    static $card = null;
    if ($card === null) {
        $raw  = @file_get_contents(SA_CORE_DIR . 'data/fargo-rates.json');
        $card = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }
    return $card;
}

function sa_fargo_norm(string $s): string
{
    $s = strtolower(html_entity_decode($s, ENT_QUOTES));
    $s = str_replace(['’', "'", '-', ' county', ' city'], ['', '', ' ', '', ''], $s);
    return trim((string) preg_replace('/[^a-z ]+/', '', preg_replace('/\s+/', ' ', $s)));
}

/** Resolve a WC KE state code (or county name) to the card's county name. */
function sa_fargo_county_name(string $state): string
{
    $label = $state;
    if (function_exists('WC') && WC()->countries) {
        $states = WC()->countries->get_states('KE');
        if (is_array($states) && isset($states[$state])) {
            $label = (string) $states[$state];
        }
    }
    $n = sa_fargo_norm($label);
    foreach (array_keys(sa_fargo_card()['county_default_town'] ?? []) as $county) {
        if (sa_fargo_norm($county) === $n) {
            return $county;
        }
    }
    return '';
}

/**
 * @return array{town:string,county:string,kes:float,excess:float}|null
 */
function sa_fargo_destination(string $state, string $city): ?array
{
    $card  = sa_fargo_card();
    $towns = $card['towns'] ?? [];
    $cityn = sa_fargo_norm($city);
    $county = sa_fargo_county_name($state);
    $pick = '';
    if ($cityn !== '') {
        foreach ($towns as $t => $row) {
            if (sa_fargo_norm($t) === $cityn && ($county === '' || $row['county'] === $county || in_array($county, $card['counties_without_branch'] ?? [], true))) {
                $pick = $t;
                break;
            }
        }
    }
    if ($pick === '' && $county !== '') {
        $pick = (string) ($card['county_default_town'][$county] ?? '');
    }
    if ($pick === '' || !isset($towns[$pick])) {
        return null;
    }
    $ex = $card['excess_per_kg_kes'] ?? [];
    $excess = (float) ($ex['default'] ?? 65);
    if (in_array($pick, $card['starred_towns'] ?? [], true)) {
        $excess = (float) ($ex['starred_towns'] ?? 100);
    }
    if (in_array($pick, $card['remote_150_towns'] ?? [], true)) {
        $excess = (float) ($ex['remote_150'] ?? 150);
    }
    return ['town' => $pick, 'county' => (string) $towns[$pick]['county'], 'kes' => (float) $towns[$pick]['kes'], 'excess' => $excess];
}

function sa_fargo_fee_kes(array $dest, float $kg): float
{
    $base_kg = (float) (sa_fargo_card()['base_covers_kg'] ?? 5);
    $over = max(0.0, ceil(round($kg - $base_kg, 3))); // round first: 10.0000001 kg must not bill an extra kg
    return $dest['kes'] + $over * $dest['excess'];
}

/** Product weight in kg (store unit aware), default when missing. */
function sa_fargo_item_kg($product): float
{
    $w = is_object($product) ? (float) $product->get_weight() : 0.0;
    if ($w <= 0) {
        return SA_FARGO_DEFAULT_ITEM_KG;
    }
    return function_exists('wc_get_weight') ? (float) wc_get_weight($w, 'kg') : $w;
}

function sa_fargo_kes_to_store(float $kes): ?float
{
    $cur = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
    if ($cur === 'KES') {
        return $kes;
    }
    if (class_exists('SA_FX_Rates')) {
        $rate = SA_FX_Rates::rate_for('KES');
        if ($rate && $rate > 0) {
            if (abs((float) get_option('sa_fargo_last_kes_rate', 0) - $rate) > 0.01) {
                update_option('sa_fargo_last_kes_rate', $rate, false);
            }
        } else {
            $rate = (float) get_option('sa_fargo_last_kes_rate', 0);
        }
        if ($rate > 0) {
            $usd_per = SA_FX_Rates::rate_for($cur) ?: 1.0; // store currency per USD
            return $kes / $rate * $usd_per; // unrounded so the KES display converts back exactly
        }
    }
    return null;
}

add_action('woocommerce_shipping_init', static function (): void {
    if (class_exists('SA_Fargo_Shipping_Method')) {
        return;
    }

    class SA_Fargo_Shipping_Method extends WC_Shipping_Method
    {
        public function __construct($instance_id = 0)
        {
            $this->id                 = 'sa_fargo';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = 'Fargo Courier';
            $this->method_description = 'Kenya courier fee from the Fargo Courier branch rate card (weight + county/town).';
            $this->supports           = ['shipping-zones', 'instance-settings'];
            $this->instance_form_fields = [
                'title' => ['title' => 'Title', 'type' => 'text', 'default' => 'Fargo Courier'],
            ];
            $this->init_settings();
            $this->title   = (string) $this->get_option('title', 'Fargo Courier');
            $this->enabled = 'yes';
        }

        public function calculate_shipping($package = []): void
        {
            $dest = $package['destination'] ?? [];
            if (strtoupper((string) ($dest['country'] ?? '')) !== 'KE') {
                return;
            }
            $d = sa_fargo_destination((string) ($dest['state'] ?? ''), (string) ($dest['city'] ?? ''));
            if (!$d) {
                return; // County not on card (Wajir/Mandera) or not chosen yet.
            }
            $kg = 0.0;
            foreach (($package['contents'] ?? []) as $item) {
                $kg += sa_fargo_item_kg($item['data'] ?? null) * (int) ($item['quantity'] ?? 1);
            }
            $kes  = sa_fargo_fee_kes($d, $kg);
            $cost = sa_fargo_kes_to_store($kes);
            if ($cost === null) {
                return;
            }
            $label = sprintf('%s to %s (KES %s, %s kg)', $this->title, $d['town'], number_format($kes), rtrim(rtrim(number_format($kg, 1), '0'), '.'));
            $this->add_rate([
                'id'        => $this->get_rate_id(),
                'label'     => $label,
                'cost'      => $cost,
                'taxes'     => false,
                'meta_data' => ['Fargo branch' => $d['town'], 'County' => $d['county'], 'Weight (kg)' => round($kg, 2), 'Fee (KES)' => $kes],
            ]);
        }
    }
});

add_filter('woocommerce_shipping_methods', static function (array $methods): array {
    $methods['sa_fargo'] = 'SA_Fargo_Shipping_Method';
    return $methods;
});

/** Town suggestions for the City field (Kenya only), filtered by county. */
add_action('wp_footer', static function (): void {
    if (!function_exists('is_checkout') || !(is_checkout() || is_cart())) {
        return;
    }
    $map = [];
    foreach ((sa_fargo_card()['towns'] ?? []) as $t => $row) {
        $map[$row['county']][] = $t;
    }
    $codes = [];
    if (function_exists('WC') && WC()->countries) {
        foreach ((array) WC()->countries->get_states('KE') as $code => $label) {
            $codes[$code] = sa_fargo_county_name((string) $code);
        }
    }
    ?>
<datalist id="sa-fargo-towns"></datalist>
<script>
(function(){var M=<?php echo wp_json_encode($map); ?>,C=<?php echo wp_json_encode($codes); ?>;
function sync(p){var c=document.getElementById(p+'_country'),s=document.getElementById(p+'_state'),t=document.getElementById(p+'_city');if(!c||!t)return;
var ke=c.value==='KE';if(ke){t.setAttribute('list','sa-fargo-towns');t.setAttribute('placeholder','Town (pick nearest Fargo branch)');}else{t.removeAttribute('list');}
var dl=document.getElementById('sa-fargo-towns');var towns=(s&&C[s.value]&&M[C[s.value]])||[];dl.innerHTML=towns.map(function(x){return '<option value="'+x+'">';}).join('');}
function all(){sync('billing');sync('shipping');}
document.addEventListener('change',function(e){if(/_(country|state)$/.test(e.target.id||'')){all();if(window.jQuery)jQuery(document.body).trigger('update_checkout');}if(/_city$/.test(e.target.id||'')&&window.jQuery)jQuery(document.body).trigger('update_checkout');});
if(window.jQuery){jQuery(document.body).on('updated_checkout country_to_state_changed',all);jQuery(document).on('change','select[id$=_state]',all);}all();})();
</script>
    <?php
}, 50);

/** Kenya counties with no Fargo branch (Wajir, Mandera): explain instead of a silent gap. */
function sa_fargo_no_branch_notice(): void
{
    if (!function_exists('WC') || !WC()->customer) {
        return;
    }
    $cu = WC()->customer;
    if (strtoupper((string) $cu->get_shipping_country()) !== 'KE') {
        return;
    }
    $state = (string) $cu->get_shipping_state();
    if ($state === '' || sa_fargo_destination($state, (string) $cu->get_shipping_city())) {
        return;
    }
    $county = sa_fargo_county_name($state) ?: $state;
    echo '<tr class="sa-fargo-nobranch"><td colspan="2" style="font-size:.85rem;color:#6b6b6b;line-height:1.4">'
        . esc_html(sprintf('Fargo Courier has no branch in %s. Choose Store pickup, or enter the nearest town with a Fargo branch (e.g. Garissa or Isiolo) in Town / City. Need help? WhatsApp +1 917 437 5121.', $county))
        . '</td></tr>';
}
add_action('woocommerce_review_order_after_shipping', 'sa_fargo_no_branch_notice');
add_action('woocommerce_cart_totals_after_shipping', 'sa_fargo_no_branch_notice');

/** Public quote + weight stats (read-only), used for QA. */
add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/fargo-quote', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => static function (WP_REST_Request $r) {
            $d = sa_fargo_destination((string) $r->get_param('state'), (string) $r->get_param('city'));
            $kg = max(0.0, (float) $r->get_param('kg'));
            $stats = get_transient('sa_fargo_weight_stats');
            if (!is_array($stats)) {
                global $wpdb;
                $row = $wpdb->get_row("SELECT COUNT(*) total, SUM(IFNULL(pm.meta_value,'') IN ('','0')) missing FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_weight' WHERE p.post_type='product' AND p.post_status='publish'", ARRAY_A);
                $stats = ['published' => (int) ($row['total'] ?? 0), 'missing_weight' => (int) ($row['missing'] ?? 0), 'default_kg' => SA_FARGO_DEFAULT_ITEM_KG, 'unit' => get_option('woocommerce_weight_unit')];
                set_transient('sa_fargo_weight_stats', $stats, HOUR_IN_SECONDS);
            }
            if (!$d) {
                return ['ok' => false, 'reason' => 'no Fargo branch for this county/town', 'weights' => $stats];
            }
            $kes = sa_fargo_fee_kes($d, $kg);
            return ['ok' => true, 'dest' => $d, 'kg' => $kg, 'fee_kes' => $kes, 'fee_store' => sa_fargo_kes_to_store($kes), 'store_currency' => get_woocommerce_currency(), 'weights' => $stats];
        },
    ]);
});
