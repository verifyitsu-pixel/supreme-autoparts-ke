<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
class TWLD_TransactWorld_Logger {

    public static function log( $message ) {
        if ( WP_DEBUG === true ) {
            if ( is_array( $message ) || is_object( $message ) ) {
            } 
        }
    }
}