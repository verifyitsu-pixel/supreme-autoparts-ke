<?php
/**
 * Plugin Name: TransactWorld Payment Integration
 * Plugin URI:  https://transactworld.com/#about-area
 * Description: Integrates the TransactWorld payment gateway with WooCommerce.
 * Version:     1.0.0
 * Author:      TransactWorld
 * Author URI:  https://transactworld.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 * Text Domain: transactworld-payment-integration
 * Domain Path: /languages
 * WC requires at least: 2.0
 * WC tested up to: 6.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action( 'admin_notices', 'twld_woocommerce_missing_notice' );
    return;
}


/**
 * Display a notice if WooCommerce is not installed or activated.
 */
function twld_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>' . esc_html__( 'TransactWorld Payment Gateway requires WooCommerce to be installed and active.', 'transactworld-payment-integration' ) . '</strong></p></div>';
}

// Main plugin initialization function.
function twld_payment_gateway_init() {

    // Load translations
    load_plugin_textdomain( 'transactworld-payment-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );


    require_once plugin_dir_path( __FILE__ ) . 'includes/class-transactworld-payment-gateway.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-transactworld-encryption.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-transactworld-admin-reconciliation.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-transactworld-logger.php';  // Include the logger


    // Add the gateway to WooCommerce.
    add_filter( 'woocommerce_payment_gateways', 'twld_add_gateway' );
    function twld_add_gateway( $gateways ) {
        $gateways[] = 'TWLD_TransactWorld_Payment_Gateway';
        return $gateways;
    }

    // Add reconciliation page to admin menu.
    add_action( 'admin_menu', 'twld_admin_menu' );

    //Enqueue scripts
    add_action( 'wp_enqueue_scripts', 'twld_enqueue_scripts' );

    // Custom thank you page
    add_filter( 'woocommerce_thankyou_order_received_text', 'twld_custom_thankyou_text', 10, 2 );


}
add_action( 'plugins_loaded', 'twld_payment_gateway_init' );

// HPOS Compatibility Declaration
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Create database table on activation.
register_activation_hook( __FILE__, 'twld_create_table' );

// Check for HPOS compatibility on activation.
register_activation_hook( __FILE__, 'twld_hpos_check' );

/**
 * Add reconciliation page to admin menu.
 */
function twld_admin_menu() {
    add_menu_page(
        __( 'TransactWorld Reconciliation', 'transactworld-payment-integration' ), 
        __( 'TransactWorld', 'transactworld-payment-integration' ),           
        'manage_options',                                  
        'twld-transactworld-reconciliation',                     
        array( new TWLD_TransactWorld_Admin_Reconciliation(), 'render_reconciliation_page' ),  
        'dashicons-filter',                                  
        56                                               
    );
}

/**
 * Create custom database table.
 */
function twld_create_table() {
    global $wpdb;
    $twld       = apply_filters( 'twld_database', $wpdb );
    $table_name = $twld->prefix.'twld_trans_forms';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        woocommerce_order_id varchar(255) NOT NULL,
        transactworld_order_id varchar(255) NOT NULL,
        order_status varchar(255) NOT NULL,
        tracking_id varchar(255) DEFAULT '' NOT NULL,
        transaction_time timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY id (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}


/**
 * Enqueue scripts.
 */
function twld_enqueue_scripts() {
    if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) { // Only on checkout, not thank you page
             //From woocommerce
        wp_enqueue_script( 'transactworld-checkout', plugins_url( 'assets/js/transactworld-checkout.js', __FILE__ ), array( 'jquery', 'jquery-blockui' ), '1.0.0', true );
    }
}



/**
 * Custom thank you page text.
 */

function twld_custom_thankyou_text( $default_text, $order ) {
    if ( ! $order ) {
        return $default_text;
    }

    $payment_method = $order->get_payment_method();

    if ( 'transactworld' !== $payment_method ) {  // Check for our gateway ID.
        return $default_text;
    }

    // Get order status directly from the order object. It's more reliable.
    $order_status = $order->get_status();


    if ( 'completed' === $order_status || 'processing' === $order_status) {
        $custom_text = '<div style="padding: 5px; background-color: #4CAF50; color: white;"><strong>' . esc_html__( 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.', 'transactworld-payment-integration' ) . '</strong></div>';
    } elseif ( 'failed' === $order_status ) {
         $custom_text = '<div style="padding: 5px; background-color: #ff9800; color: white;"><strong>' . esc_html__( 'Thank you for shopping with us. However, the transaction has been cancelled.', 'transactworld-payment-integration' ) . '</strong></div>';
    }
     else {
        $custom_text = '<div style="padding: 5px; background-color: #ff9800; color: white;"><strong>' . esc_html__( 'Thank you for shopping with us. However, the transaction has been cancelled.', 'transactworld-payment-integration' ) . '</strong></div>';
    }

    return $custom_text;
}

/**
 * Check for HPOS compatibility on plugin activation.
 */
function twld_hpos_check() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        if ( ! \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'custom_order_tables' ) ) {
            // HPOS is not enabled, deactivate the plugin and display a notice.
            deactivate_plugins( plugin_basename( __FILE__ ) ); // Deactivate the plugin

            add_action( 'admin_notices', 'twld_hpos_missing_notice' );

        }
    }
}

/**
 * Display a notice if HPOS is not enabled.
 */
function twld_hpos_missing_notice(){
     echo '<div class="error"><p><strong>' . esc_html__( 'TransactWorld Payment Gateway requires High-Performance Order Storage(HPOS) feature to be enabled in WooCommerce.', 'transactworld-payment-integration' ) . '</strong></p></div>';

    if ( isset( $_GET['activate'], $_GET['_wpnonce'] ) 
        && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tw_plugin_activation' )
    ) {
        unset( $_GET['activate'] );
    }
}