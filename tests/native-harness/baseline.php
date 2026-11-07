<?php
list( , $role, $dir ) = array_merge( $args ?? array(), array( null, null ) ) + array( null, null, null );
$role = $args[0] ?? 'A'; $dir = $args[1] ?? sys_get_temp_dir();
$sid  = 'harness-guest-1';
$h = new WC_Session_Handler();
$set = function ( $name, $val ) use ( $h ) { $p = new ReflectionProperty( 'WC_Session_Handler', $name ); $p->setAccessible( true ); $p->setValue( $h, $val ); };
$set( '_customer_id', $sid );
$data = $h->get_session( $sid, array() );
$set( '_data', is_array( $data ) ? $data : array() );
$wait = function ( $f ) { for ( $i = 0; $i < 100 && ! file_exists( $f ); $i++ ) usleep( 100000 ); };
touch( "$dir/loaded-$role" );
$wait( "$dir/loaded-" . ( $role === 'A' ? 'B' : 'A' ) ); // both loaded the same snapshot
if ( $role === 'B' ) { $h->set( 'cart_b', 'B-item' ); $h->save_data(); touch( "$dir/saved-B" ); }
else { $wait( "$dir/saved-B" ); $h->set( 'cart_a', 'A-item' ); $h->save_data(); touch( "$dir/saved-A" ); }
