<?php
// Test-only worker. Env: HW_MODE=seed|save|dump  HW_CID  HW_OP  HW_PID  HW_CFG(json)  Run via `wp eval-file`.
define( 'SA_NATIVE_SESSION_HARNESS', true );
require __DIR__ . '/adapter.php';
$mode = getenv( 'HW_MODE' ); $cid = getenv( 'HW_CID' ); $op = getenv( 'HW_OP' ); $pid = (int) getenv( 'HW_PID' );
$cfg = json_decode( getenv( 'HW_CFG' ) ?: '{}', true );
$waitfor = function ( $f ) { for ( $i = 0; $i < 200 && ! file_exists( $f ); $i++ ) usleep( 100000 ); };
function hw_out( $a ) { echo 'RESULT ' . wp_json_encode( $a ) . "\n"; }

if ( 'dump' === $mode ) {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=%s", $cid ) );
	$s = $v ? maybe_unserialize( $v ) : array();
	$cart = isset( $s['cart'] ) ? maybe_unserialize( $s['cart'] ) : array();
	$ids = array(); foreach ( (array) $cart as $i ) { $ids[] = (int) $i['product_id']; }
	$saved = isset( $s['sa_test_saved'] ) ? array_map( 'intval', array_keys( maybe_unserialize( $s['sa_test_saved'] ) ) ) : array();
	$ops = isset( $s['sa_test_operations'] ) ? array_keys( maybe_unserialize( $s['sa_test_operations'] ) ) : array();
	sort( $ids ); sort( $saved ); sort( $ops );
	hw_out( array( 'cart' => $ids, 'saved' => $saved, 'ops' => $ops, 'has_row' => (bool) $v ) );
	return;
}

SA_Native_Session_Test_Adapter::$checkpoint = function ( $n ) use ( $cfg, $waitfor ) {
	if ( ( $cfg['notify_at'] ?? '' ) === $n ) touch( $cfg['notify_file'] );
	if ( ( $cfg['hold_at'] ?? '' ) === $n ) $waitfor( $cfg['hold_until'] );
	if ( ( $cfg['crash_at'] ?? '' ) === $n ) posix_kill( getmypid(), 9 );
};
if ( $cid ) {
	$exp = time() + 172800; $expiring = time() + 169200; $th = $cid . '|' . $exp;
	$_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] = $cid . '||' . $exp . '||' . $expiring . '||' . hash_hmac( 'md5', $th, wp_hash( $th ) );
}
add_filter( 'woocommerce_session_handler', function () { return 'SA_Native_Session_Test_Adapter'; } );
try {
	WC()->initialize_session(); WC()->initialize_cart(); WC()->cart->get_cart_from_session();
	$h = WC()->session;
	if ( 'seed' === $mode ) {
		$ids = array_map( 'intval', explode( ',', getenv( 'HW_PIDS' ) ) );
		$out = $h->operation( $op, 'seed', function ( $cart ) use ( $ids ) { foreach ( $ids as $i ) { if ( ! $cart->add_to_cart( $i, 1 ) ) throw new RuntimeException( 'add failed' ); } return array( 'ok' => true ); } );
		hw_out( array( 'ok' => true, 'cid' => $h->get_customer_id() ) );
	} else { // save: move one native cart line into saved list
		$out = $h->operation( $op, 'save-' . $pid, function ( $cart, $s ) use ( $pid ) {
			foreach ( $cart->get_cart() as $k => $i ) {
				if ( (int) $i['product_id'] === $pid ) {
					$saved = $s->get( 'sa_test_saved', array() ); $saved[ $pid ] = array( 'product_id' => $pid, 'quantity' => $i['quantity'] );
					$s->set( 'sa_test_saved', $saved ); $cart->remove_cart_item( $k ); return array( 'ok' => true, 'moved' => $pid );
				}
			}
			throw new RuntimeException( 'line not in cart' );
		} );
		hw_out( array( 'ok' => true, 'outcome' => $out, 'cid' => $h->get_customer_id() ) );
	}
	$h->finish();
} catch ( Throwable $e ) {
	hw_out( array( 'ok' => false, 'error' => $e->getMessage() ) );
	if ( WC()->session && method_exists( WC()->session, 'finish' ) ) { try { WC()->session->finish(); } catch ( Throwable $x ) {} }
}
