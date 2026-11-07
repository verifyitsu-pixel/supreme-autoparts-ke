<?php
// TEST-ONLY mu-plugin for the throwaway CI site. Inert unless the request carries X-Harness-Mode (CLI and normal requests untouched).
$sa_mode = $_SERVER['HTTP_X_HARNESS_MODE'] ?? '';
if ( '' === $sa_mode ) { return; }
$sa_cfg = json_decode( $_SERVER['HTTP_X_HARNESS_CFG'] ?? '{}', true ) ?: array();
$sa_wait = function ( $f ) { for ( $i = 0; $i < 200 && ! file_exists( $f ); $i++ ) usleep( 100000 ); if ( ! file_exists( $f ) ) { touch( $f . '.TIMEOUT' ); } };
if ( 'adapter' === $sa_mode ) {
	add_action( 'woocommerce_loaded', function () use ( $sa_cfg, $sa_wait ) {
		define( 'SA_NATIVE_SESSION_HARNESS', true );
		require __DIR__ . '/../../../../wp-content/harness-adapter.php';
		SA_Native_Session_Test_Adapter::$checkpoint = function ( $n ) use ( $sa_cfg, $sa_wait ) {
			if ( ( $sa_cfg['notify_at'] ?? '' ) === $n ) touch( $sa_cfg['notify_file'] );
			if ( ( $sa_cfg['hold_at'] ?? '' ) === $n ) $sa_wait( $sa_cfg['hold_until'] );
		};
		add_filter( 'woocommerce_session_handler', function () { return 'SA_Native_Session_Test_Adapter'; } );
	}, 1 );
} elseif ( 'stock' === $sa_mode ) { // negative control: a normal Woo writer mixed in
	add_action( 'woocommerce_cart_loaded_from_session', function () use ( $sa_cfg ) { if ( ! empty( $sa_cfg['loaded_file'] ) ) touch( $sa_cfg['loaded_file'] ); } );
	add_action( 'shutdown', function () use ( $sa_cfg, $sa_wait ) { if ( ! empty( $sa_cfg['hold_until'] ) ) $sa_wait( $sa_cfg['hold_until'] ); }, 0 );
}
