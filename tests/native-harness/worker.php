<?php
// Test-only worker. Env: HW_MODE=seed|save|dump  HW_CID  HW_OP  HW_PID  HW_CFG(json)  Run via `wp eval-file`.
define( 'SA_NATIVE_SESSION_HARNESS', true );
require __DIR__ . '/adapter.php';
require __DIR__ . '/adapter-dedicated.php';
$ADP = getenv( 'HW_ADAPTER' ) === 'dedicated' ? 'SA_Dedicated_Session_Test_Adapter' : 'SA_Native_Session_Test_Adapter';
SA_Dedicated_Session_Test_Adapter::$connection_factory = function () { $m = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); $m->set_charset( 'utf8mb4' ); return $m; };
$mode = getenv( 'HW_MODE' ); $cid = getenv( 'HW_CID' ); $op = getenv( 'HW_OP' ); $pid = (int) getenv( 'HW_PID' );
$cfg = json_decode( getenv( 'HW_CFG' ) ?: '{}', true );
$waitfor = function ( $f ) { for ( $i = 0; $i < 200 && ! file_exists( $f ); $i++ ) usleep( 100000 ); if ( ! file_exists( $f ) ) throw new RuntimeException( 'barrier timeout: ' . basename( $f ) ); };
function hw_out( $a ) { echo 'RESULT ' . wp_json_encode( $a ) . "\n"; }

if ( 'dump' === $mode ) {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=%s", $cid ) );
	$s = $v ? maybe_unserialize( $v ) : array();
	$cart = isset( $s['cart'] ) ? maybe_unserialize( $s['cart'] ) : array();
	$lines = array(); foreach ( (array) $cart as $i ) { $lines[] = array( 'pid' => (int) $i['product_id'], 'qty' => (int) $i['quantity'], 'var' => (int) $i['variation_id'] ); }
	usort( $lines, function ( $a, $b ) { return $a['pid'] <=> $b['pid']; } );
	$saved = array(); foreach ( (array) ( isset( $s['sa_test_saved'] ) ? maybe_unserialize( $s['sa_test_saved'] ) : array() ) as $k => $e ) { $saved[] = array( 'pid' => (int) $k, 'qty' => (int) $e['quantity'] ); }
	usort( $saved, function ( $a, $b ) { return $a['pid'] <=> $b['pid']; } );
	$ops = isset( $s['sa_test_operations'] ) ? array_keys( maybe_unserialize( $s['sa_test_operations'] ) ) : array(); sort( $ops );
	hw_out( array( 'cart' => $lines, 'saved' => $saved, 'ops' => $ops, 'marker' => $s['harness_marker'] ?? null, 'late' => isset( $s['late'] ), 'has_row' => (bool) $v, 'sha256' => $v ? hash( 'sha256', $v ) : null ) );
	return;
}

$ADP::$checkpoint = function ( $n, $handler = null ) use ( $cfg, $waitfor ) {
	if ( ( $cfg['notify_at'] ?? '' ) === $n ) touch( $cfg['notify_file'] );
	if ( ( $cfg['hold_at'] ?? '' ) === $n ) $waitfor( $cfg['hold_until'] );
	if ( ( $cfg['crash_at'] ?? '' ) === $n ) posix_kill( getmypid(), 9 );
	if ( ( $cfg['kill_ded_at'] ?? '' ) === $n && $handler ) { $id = $handler->harness_connection_id(); $k = new mysqli( DB_HOST, DB_USER, DB_PASSWORD ); $k->query( 'KILL ' . $id ); $k->close(); usleep( 300000 ); }
	if ( ( $cfg['kill_conn_at'] ?? '' ) === $n ) { global $wpdb; $id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ); $k = new mysqli( DB_HOST, DB_USER, DB_PASSWORD ); $k->query( 'KILL ' . $id ); $k->close(); usleep( 300000 ); }
};
if ( $cid ) {
	$exp = time() + 172800; $expiring = time() + 169200; $th = $cid . '|' . $exp;
	$_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] = $cid . '||' . $exp . '||' . $expiring . '||' . hash_hmac( 'md5', $th, wp_hash( $th ) );
}
add_filter( 'woocommerce_session_handler', function () use ( $ADP ) { return $ADP; } );
$res = null;
try {
	WC()->initialize_session(); WC()->initialize_cart(); WC()->cart->get_cart_from_session();
	$h = WC()->session;
	if ( 'seed' === $mode ) {
		$ids = array_map( 'intval', explode( ',', getenv( 'HW_PIDS' ) ) );
		$out = $h->operation( $op, 'seed', function ( $cart, $s ) use ( $ids ) { $s->set( 'harness_marker', 'keep' ); foreach ( $ids as $i ) { if ( ! $cart->add_to_cart( $i, 2 ) ) throw new RuntimeException( 'add failed' ); } return array( 'ok' => true ); } );
		$res = array( 'ok' => true, 'cid' => $h->get_customer_id() );
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
		$res = array( 'ok' => true, 'outcome' => $out, 'cid' => $h->get_customer_id() );
	}
	if ( 'latedirty' === getenv( 'HW_LATE' ) ) { $h->finish(); try { $h->set( 'late', 'x' ); $h->save_data(); $res['late_refused'] = false; } catch ( Throwable $e ) { $res['late_refused'] = true; $res['late_error'] = $e->getMessage(); } }
	$h->finish(); // result is printed only after finish (all shutdown-equivalent work done)
} catch ( Throwable $e ) {
	$res = array( 'ok' => false, 'error' => $e->getMessage() );
	if ( WC()->session && method_exists( WC()->session, 'finish' ) ) { try { WC()->session->finish(); } catch ( Throwable $x ) {} }
}
hw_out( $res );
