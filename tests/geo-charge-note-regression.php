<?php
// Isolated factual note tests: no WordPress database, network or prices changed.
define('ABSPATH', 1);
$GLOBALS['note_currency'] = 'KES';
function esc_html__($s, $domain = '') { return $s; }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function sa_geo_currency_enabled() { return true; }
function sa_geo_checkout_currency() { return 'USD'; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_account_page() { return false; }
function is_wc_endpoint_url($x) { return false; }
function get_query_var($x) { return ''; }
function doing_action($x) { return false; }
function did_action($x) { return false; }
class SA_Geo_Detector {
    static function display_currency() { return $GLOBALS['note_currency']; }
    static function country_code() { return 'US'; }
}
class SA_FX_Rates { static function rate_for($currency) { return 130; } }
require __DIR__ . '/../wp-content/plugins/sa-geo-currency/includes/class-sa-geo-display.php';
$note = strip_tags(SA_Geo_Display::note_html());
if ($note !== 'Final amount shown at checkout (estimated KES amount)') throw new RuntimeException($note);
if (strpos($note, 'for US') !== false) throw new RuntimeException('IP country leaked into currency claim');
$GLOBALS['note_currency'] = 'USD';
if (strip_tags(SA_Geo_Display::note_html()) !== 'Final amount shown at checkout') throw new RuntimeException('USD wording changed');
echo "PASS factual estimate note independent of visitor country; USD unchanged\n";
