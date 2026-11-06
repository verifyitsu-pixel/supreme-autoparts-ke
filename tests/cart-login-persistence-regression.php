<?php
/** Stubbed WP/Woo only. No DB, network, orders or payments. Proves login never wipes a saved cart. */
define('ABSPATH', 1); define('DAY_IN_SECONDS', 86400);
$GLOBALS['login_cbs'] = []; $GLOBALS['meta'] = []; $GLOBALS['guest'] = [];
function add_filter(...$a) {}
function add_action($tag, $cb, $prio = 10, $n = 1) { if ($tag === 'wp_login') { $GLOBALS['login_cbs'][$prio][] = $cb; } }
function get_current_blog_id() { return 1; }
function get_user_meta($id, $k, $single = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_user_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
class WP_User { public int $ID = 0; public array $roles = []; public function __construct(int $id) { $this->ID = $id; } public function exists(): bool { return true; } }
class WooCommerce {}
class FixtureCart { function get_cart_for_session() { return $GLOBALS['guest']; } }
function WC() { static $w; return $w ??= new class { public $cart; public $session = true; function __construct() { $this->cart = new FixtureCart; } }; }
function check($ok, $label) { if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
require __DIR__ . '/../wp-content/plugins/supreme-autoparts-core/includes/cart-persistence.php';
function do_login(int $uid) { foreach ($GLOBALS['login_cbs'][20] ?? [] as $cb) { $cb('u', new WP_User($uid)); } }
$K = '_woocommerce_persistent_cart_1';
$saved = ['k_saved' => ['product_id' => 1, 'quantity' => 2]];
$GLOBALS['meta'][7][$K] = ['cart' => $saved]; $GLOBALS['guest'] = [];
do_login(7);
check(($GLOBALS['meta'][7][$K]['cart'] ?? null) === $saved, 'login with an empty guest cart keeps the saved cart');
$GLOBALS['guest'] = ['k_guest' => ['product_id' => 5, 'quantity' => 1]];
do_login(7);
check(($GLOBALS['meta'][7][$K]['cart'] ?? []) === $saved + $GLOBALS['guest'], 'login with a guest cart merges it into the saved cart');
$GLOBALS['guest'] = ['k_saved' => ['product_id' => 1, 'quantity' => 3]];
do_login(7);
check($GLOBALS['meta'][7][$K]['cart']['k_saved']['quantity'] === 3 && isset($GLOBALS['meta'][7][$K]['cart']['k_guest']), 'same cart key: guest quantity wins, other saved items stay');
$GLOBALS['guest'] = ['k_new' => ['product_id' => 9, 'quantity' => 1]];
do_login(8);
check(($GLOBALS['meta'][8][$K]['cart'] ?? null) === $GLOBALS['guest'], 'account without a saved cart receives the guest cart');
$GLOBALS['guest'] = [];
do_login(9);
check(!isset($GLOBALS['meta'][9][$K]), 'empty guest cart and no saved cart writes nothing');
