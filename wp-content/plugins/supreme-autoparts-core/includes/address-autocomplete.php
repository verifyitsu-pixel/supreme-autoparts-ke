<?php
declare(strict_types=1);
/**
 * Predictive address search at checkout (Rock 2026-10-17: "I need free one").
 *
 * Provider: Geoapify Address Autocomplete API.
 *   - Free plan: 3,000 credits/day, autocomplete = 1 credit per request, commercial
 *     production use explicitly allowed, soft limits, no credit card at signup
 *     (verified on geoapify.com 2026-10-17). OSM data - Kenya coverage tested strong.
 *   - Rejected: photon.komoot.io (demo server, maintainers forbid business use),
 *     hosted Nominatim (autocomplete explicitly banned), Google Places (billing required).
 *   - Needs a free API key: Rock signs up at myprojects.geoapify.com and sets either
 *     constant SA_GEOAPIFY_KEY in wp-config.php or option sa_geoapify_key.
 *   - Attribution required by the free plan: the "Powered by Geoapify" link the JS
 *     renders under the address field. Do not remove it.
 *
 * Everything is inert unless sa_couriers_enabled() is true AND a key is configured.
 * Requests are proxied server-side (key stays secret), cached 10 min per query, and
 * rate-limited like the tracking endpoints. Selected coordinates feed courier
 * station ranking (sa/v1/courier-stations accepts lat/lng).
 */

if (!defined('ABSPATH')) {
    exit;
}

const SA_AA_CACHE_TTL = 600; // 10 min per query

function sa_geoapify_key(): string
{
    if (defined('SA_GEOAPIFY_KEY') && is_string(SA_GEOAPIFY_KEY) && SA_GEOAPIFY_KEY !== '') {
        return SA_GEOAPIFY_KEY;
    }
    return (string) get_option('sa_geoapify_key', '');
}

function sa_aa_enabled(): bool
{
    return function_exists('sa_couriers_enabled') && sa_couriers_enabled() && sa_geoapify_key() !== '';
}

/* Server-side proxy: hides the key, caches, rate limits. Public, read-only. */
add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/address-suggest', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static function (WP_REST_Request $r) {
            if (!sa_aa_enabled()) {
                return ['suggestions' => []];
            }
            $q = trim((string) $r->get_param('q'));
            if (strlen($q) < 3 || strlen($q) > 120) {
                return ['suggestions' => []];
            }
            $country = strtolower(preg_replace('/[^a-z]/', '', (string) $r->get_param('country')) ?? '');
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (function_exists('sa_ft_rate_ok') && !sa_ft_rate_ok('aa' . $ip, 20, 60)) {
                return new WP_REST_Response(['suggestions' => [], 'message' => 'Too many lookups.'], 429);
            }
            $ck = 'sa_aa_' . md5(strtolower($q) . '|' . $country);
            $hit = get_transient($ck);
            if (is_array($hit)) {
                return ['suggestions' => $hit, 'cached' => true];
            }
            $args = [
                'text' => $q,
                'limit' => 5,
                'format' => 'json',
                'apiKey' => sa_geoapify_key(),
            ];
            if (strlen($country) === 2) {
                $args['filter'] = 'countrycode:' . $country;
            }
            $resp = wp_remote_get(add_query_arg($args, 'https://api.geoapify.com/v1/geocode/autocomplete'), [
                'timeout' => 8,
                'user-agent' => 'SupremeAutoparts-AddressAutocomplete/1.0 (+' . home_url('/') . ')',
            ]);
            $out = [];
            if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
                $j = json_decode((string) wp_remote_retrieve_body($resp), true);
                foreach ((array) ($j['results'] ?? []) as $it) {
                    if (!is_array($it) || !is_numeric($it['lat'] ?? null) || !is_numeric($it['lon'] ?? null)) {
                        continue;
                    }
                    $out[] = [
                        'label' => (string) ($it['formatted'] ?? ''),
                        'line1' => (string) ($it['address_line1'] ?? ''),
                        'city' => (string) ($it['city'] ?? ($it['town'] ?? ($it['village'] ?? ($it['county'] ?? '')))),
                        'state' => (string) ($it['state'] ?? ($it['county'] ?? '')),
                        'postcode' => (string) ($it['postcode'] ?? ''),
                        'lat' => (float) $it['lat'],
                        'lng' => (float) $it['lon'],
                    ];
                }
            }
            set_transient($ck, $out, SA_AA_CACHE_TTL);
            return ['suggestions' => $out];
        },
    ]);
});

/* Hidden coordinate fields, filled when the customer picks a suggestion. */
add_filter('woocommerce_checkout_fields', static function (array $fields): array {
    if (!sa_aa_enabled()) {
        return $fields;
    }
    $fields['billing']['sa_addr_lat'] = ['type' => 'hidden', 'required' => false, 'class' => ['sa-addr-coords'], 'priority' => 116];
    $fields['billing']['sa_addr_lng'] = ['type' => 'hidden', 'required' => false, 'class' => ['sa-addr-coords'], 'priority' => 117];
    return $fields;
});

add_action('woocommerce_checkout_update_order_meta', static function ($order_id): void {
    if (!sa_aa_enabled()) {
        return;
    }
    $o = wc_get_order($order_id);
    if (!$o instanceof WC_Order) {
        return;
    }
    $lat = isset($_POST['sa_addr_lat']) ? (float) $_POST['sa_addr_lat'] : 0.0; // phpcs:ignore
    $lng = isset($_POST['sa_addr_lng']) ? (float) $_POST['sa_addr_lng'] : 0.0; // phpcs:ignore
    if ($lat !== 0.0 && $lng !== 0.0 && abs($lat) <= 90 && abs($lng) <= 180) {
        $o->update_meta_data('_sa_addr_lat', $lat);
        $o->update_meta_data('_sa_addr_lng', $lng);
        $o->save_meta_data();
    }
});

/* Checkout JS: dropdown on the street address field, fills address + coords. */
add_action('wp_footer', static function (): void {
    if (!sa_aa_enabled() || !function_exists('is_checkout') || !is_checkout()) {
        return;
    }
    $rest = esc_url_raw(rest_url('sa/v1/address-suggest'));
    ?>
<style>
.sa-aa-wrap{position:relative}
.sa-aa-list{position:absolute;z-index:9999;left:0;right:0;top:100%;margin:2px 0 0;padding:0;list-style:none;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:260px;overflow:auto}
.sa-aa-list li{padding:10px 12px;font-size:14px;cursor:pointer;border-bottom:1px solid #f1f1f1}
.sa-aa-list li:last-child{border-bottom:0}
.sa-aa-list li:hover,.sa-aa-list li.is-active{background:#f6f3ea}
.sa-aa-credit{font-size:11px;color:#999;margin-top:4px}
</style>
<script>
(function(){
var REST=<?php echo wp_json_encode($rest); ?>;
function field(){return document.getElementById('billing_address_1');}
function country(){var e=document.getElementById('billing_country');return e?e.value:'';}
function setVal(id,v){var e=document.getElementById(id);if(e&&v){e.value=v;}}
function wrap(el){if(el.parentNode&&el.parentNode.classList.contains('sa-aa-wrap'))return el.parentNode;var w=document.createElement('div');w.className='sa-aa-wrap';el.parentNode.insertBefore(w,el);w.appendChild(el);return w;}
var listEl=null,timer=null,items=[];
function closeList(){if(listEl){listEl.remove();listEl=null;}items=[];}
function credit(w){if(w.parentNode.querySelector('.sa-aa-credit'))return;var c=document.createElement('div');c.className='sa-aa-credit';c.innerHTML='Address search powered by <a href="https://www.geoapify.com/" target="_blank" rel="noopener">Geoapify</a>';w.parentNode.insertBefore(c,w.nextSibling);}
function pick(it){
  var f=field();if(!f)return;
  f.value=it.line1||it.label;
  setVal('billing_city',it.city);
  var st=document.getElementById('billing_state');
  if(st&&it.state){
    var opts=st.options||[];
    var want=it.state.toLowerCase();
    for(var i=0;i<opts.length;i++){
      if((opts[i].text||'').toLowerCase()===want||(opts[i].value||'').toLowerCase()===want){st.selectedIndex=i;break;}
    }
  }
  setVal('billing_postcode',it.postcode);
  setVal('sa_addr_lat',String(it.lat));
  setVal('sa_addr_lng',String(it.lng));
  closeList();
  if(window.jQuery){jQuery(document.body).trigger('update_checkout');}
  document.dispatchEvent(new Event('change',{bubbles:true}));
}
function render(w){
  closeList();
  if(!items.length)return;
  listEl=document.createElement('ul');listEl.className='sa-aa-list';
  items.forEach(function(it){
    var li=document.createElement('li');li.textContent=it.label;
    li.addEventListener('mousedown',function(e){e.preventDefault();pick(it);});
    listEl.appendChild(li);
  });
  w.appendChild(listEl);
}
function suggest(){
  var f=field();if(!f)return;
  var q=f.value.trim();
  if(q.length<3){closeList();return;}
  var u=REST+'?q='+encodeURIComponent(q)+'&country='+encodeURIComponent(country());
  fetch(u,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
    if(f.value.trim()!==q)return;
    items=(d&&d.suggestions)||[];
    render(wrap(f));
  }).catch(function(){});
}
function boot(){
  var f=field();if(!f)return;
  var w=wrap(f);credit(w);
  f.setAttribute('autocomplete','off');
  f.addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(suggest,300);});
  f.addEventListener('blur',function(){setTimeout(closeList,200);});
  document.addEventListener('click',function(e){if(listEl&&!w.contains(e.target)){closeList();}});
}
if(document.readyState!=='loading'){boot();}else{document.addEventListener('DOMContentLoaded',boot);}
})();
</script>
    <?php
}, 60);
