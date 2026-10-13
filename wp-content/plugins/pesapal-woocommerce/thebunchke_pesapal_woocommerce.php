<?php
    /*
    Plugin Name: TheBunch KE Pesapal Woocommerce
    Description: Add PesaPal payment gateway to your Woocommerce plugin
    Version: 3.1.3
    Author: Rixeo & Pesapal
    Contributor: PesaPal Devs
    Author URI: http://thebunch.co.ke/
    Plugin URI: https://developer.pesapal.com/official-extensions?download=8:woocommerce
    */

use Google\Service\ShoppingContent\Account;

    if ( ! defined( 'ABSPATH' ) ) 
    	exit; // Exit if accessed directly
    
    //Define constants
    define( 'THEBUNCHKE_PESAPAL_WOO_PLUGIN_DIR', dirname(__FILE__).'/' );
    define( 'THEBUNCHKE_PESAPAL_WOO_PLUGIN_URL', plugin_dir_url(__FILE__));
    	
    if(!class_exists('thebunchke_pesapal_woo_init')){	
		function thebunchke_pesapal_woo_init(){
			//Load PesaPal OAuth Library
			require_once(THEBUNCHKE_PESAPAL_WOO_PLUGIN_DIR . 'lib/pesapalV30Helper.php');
		
			add_filter( 'woocommerce_locate_template', 'woo_adon_plugin_template', 1, 3 );
			function woo_adon_plugin_template( $template, $template_name, $template_path ) { 
				global $woocommerce;
				$_template = $template;
				if ( ! $template_path ) {
					$template_path = $woocommerce->template_url;
				}
				
				$plugin_path  = dirname(__FILE__);//untrailingslashit( plugin_dir_path( __FILE__ ) )  . '/template/woocommerce/';
				$plugin_template = $plugin_path.'/templates/'.$template_name;
				
				if( file_exists( $plugin_template ) ){
					$template = $plugin_template;
				}
		
				if ( ! $template ){
					$template = $_template;
				}
		
				return $template;
			}
			
			add_filter('woocommerce_payment_gateways', 'add_pesapal_gateway_class' );
			function add_pesapal_gateway_class( $methods ) {
				$methods[] = 'WC_TheBunchKE_PesaPal_Pay_Gateway'; 
				return $methods;
			}
			/**
			 * Custom function to declare compatibility with cart_checkout_blocks feature 
			*/
			function declare_cart_checkout_blocks_compatibility() {
				// Check if the required class exists
				if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
					// Declare compatibility for 'cart_checkout_blocks'
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
				}
			}
			// Hook the custom function to the 'before_woocommerce_init' action
			add_action('before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility');

			// Hook the custom function to the 'woocommerce_blocks_loaded' action
			add_action( 'woocommerce_blocks_loaded', 'pesapal_woocommerce_blocks_support' );

			function pesapal_woocommerce_blocks_support() {
				// Check if the required class exists
				if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
					return;
				}
			
				// Include the custom Blocks Checkout class
				require_once plugin_dir_path(__FILE__) . 'class-block.php';
			
				// Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
				add_action(
					'woocommerce_blocks_payment_method_type_registration',
					function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
						// Register an instance of Pesapal_Gateway_Blocks
						$payment_method_registry->register( new Pesapal_Gateway_Blocks );
					}
				);
			}
			
			/**
			 * Add Currencies
			 *
			 */
			add_filter( 'woocommerce_currencies', 'thebunchke_pesapal_woo_add_shilling' );
			function thebunchke_pesapal_woo_add_shilling( $currencies ) {
				if( !isset( $currencies['KES'] ) ||!isset( $currencies['KSH'] ) ) {
					$currencies['KES'] = __( 'Kenyan Shilling', 'woocommerce' );
					$currencies['TZS'] = __( 'Tanzanian Shilling', 'woocommerce' );
					$currencies['UGX'] = __( 'Ugandan Shilling', 'woocommerce' );
					return $currencies;
				}
			}
		
			/**
			 * Add Currency Symbols
			 *
			 */
			add_filter('woocommerce_currency_symbol', 'thebunchke_pesapal_woo_add_shilling_symbol', 10, 2);
			function thebunchke_pesapal_woo_add_shilling_symbol( $currency_symbol, $currency ) {
				switch( $currency ) {
					case 'KES': 
						$currency_symbol = 'KShs'; 
					break;
					case 'TZS': 
						$currency_symbol = 'TZs'; 
					break;
					case 'UGX': 
						$currency_symbol = 'UShs'; 
					break;
				}
				return $currency_symbol;
			}
			
			if(class_exists('WC_Payment_Gateway')){
				if(!class_exists('WC_TheBunchKE_PesaPal_Pay_Gateway')){
					class WC_TheBunchKE_PesaPal_Pay_Gateway extends WC_Payment_Gateway{	
					    public $id;
						public $method_title;
						public $icon;
						public $has_fields;
						protected $testmode;
						protected $debug; 
						public $title; 
						public $description;
						protected $apiVersion ;
						protected $orderstatus;
						protected $paymentsoptionspageloader;
						protected $loadjquery;
						protected $recurring; 
						protected $recurring_type; 
						protected $consumer_key;
						protected $consumer_secret;
						protected $notification_id;
						protected $notify_url;
						protected $cron_url;
						protected $apimode;
						protected $pesapalV30Helper;

						public function __construct(){
							global $woocommerce; 

							add_action('woocommerce_receipt_'.$this->id, array(&$this, 'payment_page'));
		
							//Settings
							$this->id = 'pesapal';
							$this->method_title = 'Pesapal';
							$this->icon = apply_filters('woocommerce_payment_gateway_icon', '');
							$this->method_description ='Pesapal lets your clients pay using card(Visa, MasterCard and Amexx) and Mobile moneys(Mpesa, Airtel Money, Mtn, TigoPesa) options available in your region';
							$this->has_fields = false;
							$this->testmode = ($this->get_option('testmode') === 'yes') ? true : false;
							$this->debug = $this->get_option( 'debug' ); 
							$this->title = $this->get_option('title'); 
							$this->description = $this->get_option('description');
							$this->apiVersion = (int) $this->get_option('apiversion');
							$this->orderstatus = $this->get_option('orderstatus');
							$this->paymentsoptionspageloader = $this->get_option('paymentsoptionspageloader');
							$this->loadjquery = $this->get_option('loadjquery');
							$this->recurring = $this->get_option('recurring'); 
							$this->recurring_type = $this->get_option('recurring_type'); 
							
							//Set up logging
							if ( 'yes' == $this->debug ) {
								if ( class_exists('WC_Logger') ) {
									$this->log = new WC_Logger();
								} else {
									$this->log = $woocommerce->logger();
								}
							}
		
							if( $this->testmode ) {
								$this->consumer_key = $this->get_option('testconsumerkey');
								$this->consumer_secret = $this->get_option('testsecretkey');
							} else {
								$this->consumer_key = $this->get_option('consumerkey');
								$this->consumer_secret = $this->get_option('secretkey');
							}
							
							$this->notification_id = $this->get_option('testnotification_id');
							$this->apimode = ( $this->testmode ) ? "demo" : "live"; 
							$this->pesapalV30Helper = new pesapalV30Helper($this->apimode);
							
							//IPN URL
							$this->notify_url = add_query_arg( 'wc-api', 'WC_Pesapal_Gateway', home_url( '/' ) );
							$this->cron_url = add_query_arg( 'wc-api', 'WC_Pesapal_Cron', home_url( '/' ) );
							
							$this->create_pesapal_table();
							if(!$this->testmode){
								$this->generate_notification_id();
							}
							$this->init_form_fields();
							$this->init_settings();
							
							if (is_admin()){
								add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
							}
							
							add_action('woocommerce_receipt_'.$this->id, array(&$this, 'payment_page'));
							add_action('woocommerce_api_wc_'.$this->id.'_gateway', array( $this, 'ipn_response' ) );
							add_action('woocommerce_api_wc_'.$this->id.'_cron', array( $this, 'pesapalCron' ) );
							add_action($this->id.'_process_valid_ipn_request', array($this, 'process_valid_ipn_request'));
							add_action('woocommerce_thankyou_pesapal', array(&$this, 'update_order_status'), 1, 1);
						}
						
						/**
						 * Get gateway icon.
						 *
						 * @return string
						 */
						public function get_icon() {
							// We need a base country for the link to work, bail if in the unlikely event no country is set.
							$base_country = WC()->countries->get_base_country();
		
							$icon_html = "<img style='max-width:33px; float: left; padding:0; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/visa.svg' alt='Visa' />";
							$icon_html .= "<img style='max-width:33px; float: left; padding:0; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/mastercard.svg' alt='MasterCard' />";
							$icon_html .= "<img style='max-width:33px; float: left; padding:0; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/amex.svg' alt='Amex' />";
		
							if($base_country=="KE"){
								$icon_html .= "<img style='max-width:33px; float: left; background:#FFF; padding:3px; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/mpesa.png' alt='Mpesa - KE' />";
								$icon_html .= "<img style='max-width:33px; float: left; background:#FFF; padding:3px; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/airtel.png' alt='Airtel Money - KE' />";
								$icon_html .= "<img style='max-width:33px; float: left; background:#FFF; padding:3px; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/mvisal.png' alt='Mvisa - KE' />";
							}else if($base_country=="UG"){
								$icon_html .= "<img style='max-width:33px; float: left; background:#FFF; padding:0; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/mtn.jpg' alt='MTN Mobile Money - UG' />";
								$icon_html .= "<img style='max-width:33px; float: left; background:#FFF; padding:3px; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/airtel.png' alt='Airtel Money - UG' />";
								$icon_html .= "<img style='max-width:33px; float: left; background:#FFF; padding:5px 3px; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/EZEEMONEY_s.png' alt='Eazzy' />";
							} else if($base_country=="TZ"){
								$icon_html .= "<img style='max-width:33px; float: left; background:#FFF; padding:0; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/mpesa_tz.jpg' alt='Mpesa - TZ' />";
								$icon_html .= "<img style='max-width:33px; float: left; background:#FFF; padding:5px 3px; margin: 0 4px 0 0;' src='http://payments.pesapal.com/images/pesapal/TigoPesa_s.png' alt='TigoPesa - TZ' />";
							}
							
							$icon_html .= "<img style='max-width:33px; float: left; background:#FFF; padding:3px; margin: 0;' src='http://payments.pesapal.com/images/pesapal/ewallet.png' alt='Pesapal E-wallet' />"; 
		
							return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
						}
						
						public function create_pesapal_table() {
							global $wpdb;
		
							$installSQL = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}woocommerce_pesapal_order_tracking_data` (
								`id` int(11) NOT NULL AUTO_INCREMENT,
								`order_id` int(11) NOT NULL,
								`pesapal_tracking_id` varchar(100) NOT NULL,
								`order_tracking_id` VARCHAR(100) NOT NULL,
								`redirect_url` VARCHAR(255) NOT NULL,
								`date_created` timestamp NOT NULL,
								PRIMARY KEY (`id`),
								UNIQUE KEY `tracking_url` (`pesapal_tracking_id`,`order_tracking_id`,`redirect_url`)
							)"; $wpdb->query($installSQL);

							$installSQL = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}woocommerce_pesapal_merchant_details` (
								`id` int(11) NOT NULL AUTO_INCREMENT,
								`pesapal_consumer_key` varchar(100) NOT NULL,
								`pesapal_secret_key` varchar(100) NOT NULL,
								`notification_id` varchar(100) NOT NULL,
								`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
								PRIMARY KEY (`id`) 
							)"; $wpdb->query($installSQL);
		
							$installSQL = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}woocommerce_pesapal_transactions` (
								`id` int(11) NOT NULL AUTO_INCREMENT,
								`order_id` int(11) NOT NULL,
								`recurring_product` varchar(100) DEFAULT NULL,
								`amount` varchar(100) NOT NULL,
								`currency` varchar(30) NOT NULL,
								`payment_method` varchar(100) DEFAULT NULL,
								`payment_status` varchar(100) NOT NULL,
								`confirmation_code` varchar(100) DEFAULT NULL,
								`merchant_ref` varchar(100) NOT NULL,
								`tracking_id` varchar(100) DEFAULT NULL,
								`notification_type` varchar(100) NOT NULL,
								`first_name` varchar(100) DEFAULT NULL,
								`last_name` varchar(100) DEFAULT NULL,
								`phone` varchar(100) DEFAULT NULL,
								`email` varchar(100) DEFAULT NULL,
								`recurring_account` varchar(100) DEFAULT NULL,
								`correlation_id` varchar(100) DEFAULT NULL,
								`callback_url` varchar(200) DEFAULT NULL,
								`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
								PRIMARY KEY (`id`) 
							)"; $wpdb->query($installSQL);
						}

						public function insert_transaction($order_id, $request) {
							global $wpdb;
							$insertSQL = "INSERT IGNORE INTO `{$wpdb->prefix}woocommerce_pesapal_transactions` (`order_id`, `recurring_product`, `amount`, `currency`, `payment_status`, `merchant_ref`, `notification_type`, `first_name`, `last_name`, `phone`, `email`, `recurring_account`, `callback_url`) ";
							$insertSQL .= "values ('".$order_id."', '".$request->recurring_product."', '".$request->amount."', '".$request->currency."', 'PLACED', '".$request->pesapalMerchantReference."', 'PLACED', '".$request->billing_first_name."', '".$request->billing_last_name."', '".$request->billing_phone."', '".$request->billing_email."', '".$request->account_number."', '".$request->callback_url."')";
							$wpdb->query($insertSQL);
							$this->write_log($insertSQL);
						}
						public function update_transaction($id, $ref, $type, $transaction) {
							global $wpdb;
							
							$updateSQL = "UPDATE `{$wpdb->prefix}woocommerce_pesapal_transactions` SET payment_method='".$transaction->payment_method."', payment_status='".$transaction->payment_status_description."', confirmation_code='".$transaction->confirmation_code."', tracking_id='".$id."', notification_type='".$type."' WHERE merchant_ref='".$ref."'";
							$wpdb->query($updateSQL);
							$this->write_log($updateSQL);
						}

						public function create_new_transaction($order, $id, $ref, $type, $transaction){
							global $wpdb;

							$first= $transaction->subscription_transaction_info->first_name;
							$last = $transaction->subscription_transaction_info->last_name;
							$recurring = $transaction->subscription_transaction_info->account_reference;
							$correlation = $transaction->subscription_transaction_info->correlation_id;
							$insertSQL = "INSERT IGNORE INTO `{$wpdb->prefix}woocommerce_pesapal_transactions` (`order_id`, `amount`, `currency`, `payment_method`, `payment_status`, `confirmation_code`, `merchant_ref`, `tracking_id`, `notification_type`, `first_name`, `last_name`, `recurring_account`, `correlation_id`, `callback_url`) ";
							$insertSQL .= "values ('".$order."', '".$transaction->amount."', '".$transaction->currency."', '".$transaction->payment_method."', '".$transaction->payment_status_description."', '".$transaction->confirmation_code."', '".$ref."',  '".$id."', '".$type."', '".$first."', '".$last."', '".$recurring."', '".$correlation."', '".$transaction->call_back_url."')";
							$wpdb->query($insertSQL);
							$this->write_log($insertSQL);
						}

						public function get_recurring_transaction($transaction) {
							global $wpdb; 
							$transaction_details = null;
							if ($transaction) {
								$sql = "SELECT order_id, recurring_product, amount, currency, merchant_ref, tracking_id, notification_type, first_name, recurring_account FROM {$wpdb->prefix}woocommerce_pesapal_transactions WHERE ";
								$sql .= "recurring_account = '".$transaction->subscription_transaction_info->account_reference."' ";
								// $sql .= "tracking_id = '".$id."' ";
								$transaction_details = $wpdb->get_row($sql);
							} 
		
							$this->write_log($transaction_details);
							
							return $transaction_details;
						}

						public function generate_notification_id(){
							$merchant = $this->get_merchant();
							if(!$merchant || $merchant->pesapal_consumer_key != $this->consumer_key || $merchant->pesapal_secret_key != $this->consumer_secret || $merchant->notification_id == '00000000-0000-0000-0000-000000000000'){
								$tokenResponse = $this->pesapalV30Helper->getAccessToken($this->consumer_key,$this->consumer_secret);
								$access_token = isset($tokenResponse->token) ? $tokenResponse->token : null;
								
								$this->write_log($tokenResponse);
								$access_token = $tokenResponse->token;
								$this->write_log($access_token);
								$callback_url = str_replace("https://","http://",$this->notify_url);
								$this->write_log($callback_url);
								
								$ipn_id = null;
								if($access_token){
									$ipn_id = $this->pesapalV30Helper->generateNotificationId($callback_url, $access_token);
									// $ipn_id = isset($merchant->notification_id) && $merchant->notification_id != 00000000-0000-0000-0000-000000000000 ? $merchant->notification_id : $this->pesapalV30Helper->generateNotificationId($callback_url, $access_token);
									if($ipn_id){
										global $wpdb;
								
										if(!$merchant){
											$insertSQL = "INSERT IGNORE INTO `{$wpdb->prefix}woocommerce_pesapal_merchant_details` (`pesapal_consumer_key`,`pesapal_secret_key`,`notification_id`) ";
											$insertSQL .= "values ('".$this->consumer_key."','".$this->consumer_secret."','".$ipn_id."')";
											$wpdb->query($insertSQL);
										}elseif(isset($merchant->notification_id) && $merchant->notification_id != $ipn_id){
											$updateSQL = "UPDATE `{$wpdb->prefix}woocommerce_pesapal_merchant_details` SET notification_id='".$ipn_id."' WHERE pesapal_consumer_key='".$this->consumer_key."' AND pesapal_secret_key = '".$this->consumer_secret."'";
											$wpdb->query($updateSQL);
											$this->write_log($updateSQL);
										}
									}
								}
								// return $ipn_id;
								$this->write_log($ipn_id);
							}
						} 

						public function get_merchant() {
							global $wpdb; 
							$merchant = null;
							if ($this->consumer_key && $this->consumer_secret) {
								$sql = "SELECT * FROM {$wpdb->prefix}woocommerce_pesapal_merchant_details WHERE ";
								$sql .= "pesapal_consumer_key = '".$this->consumer_key."' AND pesapal_secret_key = '".$this->consumer_secret."' ";
								$merchant = $wpdb->get_row($sql);
							} 

							$this->write_log($merchant);

							return $merchant;
						}
		
						public function insert_order_tracking_data($orderId, $pesapalTrackingId = null, $orderTrackingId = null, $redirectURL = null) {
							global $wpdb;
							$insertSQL = "INSERT IGNORE INTO `{$wpdb->prefix}woocommerce_pesapal_order_tracking_data` (`order_id`,`pesapal_tracking_id`,`order_tracking_id`,`redirect_url`) ";
							$insertSQL .= "values ('".$orderId."','".$pesapalTrackingId."','".$orderTrackingId."','".$redirectURL."')";
							$wpdb->query($insertSQL);
						}
		
						public function createMpesaSTKRequest($object,$order_id,$phone){
							global $wpdb;
							$insertSQL = "INSERT IGNORE INTO `{$wpdb->prefix}woocommerce_pesapal_mobile_payments` (`order_id`, `phone`, `reference`, `transaction_id`, `request_code`, `payment_status`, `status`, `confirmation_code`, `created_at`, `call_back_received`, `is_active`) ";
							$insertSQL .= "values ('".$order_id."','".$phone."','".$object->merchant_reference."','".$object->transaction_id."','".$object->request_code."','".$object->payment_status."','".$object->status."','".$object->confirmation_code."','".date("Y-m-d h:i:s")."',0,0)";
							$wpdb->query($insertSQL);
						}
		
						public function get_order_tracking_data($orderId,$orderTrackingId = null) {
							global $wpdb; 
							$order_tracking_data = null;
							if ($orderId || $orderTrackingId) {
								$sql = "SELECT order_id,pesapal_tracking_id,order_tracking_id,redirect_url FROM {$wpdb->prefix}woocommerce_pesapal_order_tracking_data WHERE ";
								$sql .= ($orderTrackingId) ? "order_tracking_id = '".$orderTrackingId."'" : "order_id = '".$orderId."'";
								$order_tracking_data = $wpdb->get_row($sql);
							} 
		
							return $order_tracking_data;
						}
						
						public function update_order_status($order_id) {
							$status = "";
							$statusResponseJson = "";
							$order = wc_get_order($order_id); 
							$paymethod = $order->get_payment_method();
							$orderTrackingId = $_REQUEST['OrderTrackingId'];
							if($orderTrackingId){
								$tokenResponse = $this->pesapalV30Helper->getAccessToken($this->consumer_key,$this->consumer_secret);

								$access_token = $tokenResponse->token;
								$response = $this->pesapalV30Helper->getTransactionStatus($orderTrackingId,$access_token);
								$this->write_log('UPDATE ORDER STATUS');
								$this->write_log($response);
								$statusResponseJson = json_encode($response);
								if(isset($response->payment_status_description) && $response->payment_status_description){
									$status = strtoupper($response->payment_status_description);
								}
							}
		
							if(count($_REQUEST)) $statusResponseJson .= " | ".json_encode($_REQUEST);

							// only update the order status when the order status is pending
							if($order->get_status() === 'pending'){
								if($status=="COMPLETED"){
									$order->update_status($this->orderstatus, '<strong>Order Update: Completed</strong>.<br><br>You can now deliver the goods or services<br><br>'.$statusResponseJson.'<br><br>');
									$order->payment_complete();
								}else if($status=="FAILED"){
									$order->update_status( 'wc-failed', '<strong>Order Update:  Failed</strong><br><br>'.$statusResponseJson.'<br><br>' );
								}else if($status=="REVERSED" ){
									$order->update_status( 'wc-refunded', '<strong>Order Update:  Reversed</strong><br><br>'.$statusResponseJson.'<br><br>' );
								}
							}
						}
						
						function init_form_fields() {
							$ppPrefix = substr(str_shuffle(str_repeat("ABCDEFGHJKMNPQRSTUVWXYZ", 4)), 0, 4);
							$this->form_fields = array(
								'enabled' => array(
									'title' => __( 'Enable / Disable', 'woothemes' ), 
									'type' => 'checkbox',
									'label' => __( 'Enable Pesapal Payment', 'woothemes' ),
									'default' => 'no'
								),
								'title' => array(
									'title' => __( 'Title', 'woothemes' ),
									'type' => 'text',
									'description' => __( 'This controls the title which the user sees during checkout.', 'woothemes' ),
									'default' => __( 'PesaPal (Mobile Money & Card payments)', 'woothemes' )
								),
								'ppprefix' => array(
									'title' => __( 'Order Prefix', 'woothemes' ),
									'type' => 'text',
									'description' => __( 'This is the prefix appended to all order to ensure you do not have duplicate pesapal merchant references generated by other systems connected to your Pesapal account.', 'woothemes' ),
									'default' => __('', 'woothemes' )
								),
								'description' => array(
									'title' => __( 'Description', 'woocommerce' ),
									'type' => 'textarea',
									'description' => __( 'This is the description which the user sees during checkout.', 'woocommerce' ), 
									'default' => __("Pay using PesaPal Gateway, you can pay by either credit/debit card or use mobile money payment option such as Mpesa, AirtelMoney, MTN Money...", 'woocommerce')
								),
								'customessage' => array(
									'title' => __( 'Custom Message', 'woocommerce' ),
									'type' => 'textarea',
									'description' => __( 'Message to be displayed to the user on checkout', 'woocommerce' ),
								),
								'testmode' => array(
									'title' => __( 'Use Demo Gateway', 'woothemes' ),
									'type' => 'checkbox',
									'label' => __( 'Use Demo Gateway', 'woothemes' ),
									'description' => __( 'Click <a href="https://developer.pesapal.com/api3-demo-keys.txt" target="_blank">here</a> for pesapal test credentials.', 'woothemes' ),
									'default' => 'no'
								),
								'orderstatus' => array(
									'title'    => esc_html__( 'Update Paid Orders To', 'woothemes' ),
									'type'     => 'select',
									'desc_tip' => esc_html__( 'PROCESSING - Payment received (paid) and stock has been reduced; order is awaiting fulfillment. | COMPLETED - Order fulfilled and complete – requires no further action.', 'woothemes' ),
									'default'  => 'wp-processing',
									'options'  => array(
										'wc-processing' => esc_html_x( 'Processing',  'Payment received (paid) and stock has been reduced; order is awaiting fulfillment.', 'woothemes' ),
										'wc-completed' => esc_html_x( 'Completed', 'Order fulfilled and complete – requires no further action.', 'woothemes' ),
									)
								),  
								'paymentsoptionspageloader' => array(
									'title'    => esc_html__( 'Payments Page Loader', 'woothemes' ),
									'type'     => 'select',
									'desc_tip' => esc_html__( 'Select style you wish to load your payments page using', 'woothemes' ),
									'default'  => 1,
									'options'  => array(
										1 => esc_html_x( 'Iframe',  'Iframe', 'woothemes' ),
										2 => esc_html_x( 'Pop Up Box', 'Pop Up Box', 'woothemes' ),
										3 => esc_html_x( 'Redirect', 'Redirect - New Tab', 'woothemes' )
									)
								),
								'loadjquery' => array(
									'title'    => esc_html__( 'Use Jquery Loader', 'woothemes' ),
									'type'     => 'select',
									'desc_tip' => esc_html__( 'Use Jquery Loader', 'woothemes' ),
									'default'  => 1,
									'options'  => array(
										0 => esc_html_x( 'No',  'No', 'woothemes' ),
										1 => esc_html_x( 'Yes', 'Yes', 'woothemes' )
									)
								),
								'consumerkey' => array(
									'title' => __( 'Consumer Key', 'woothemes' ),
									'type' => 'text',
									'description' => __( 'Your Pesapal consumer key which should have been emailed to you.', 'woothemes' ),
									'default' => ''
								),
								'secretkey' => array(
									'title' => __( 'Consumer Secret', 'woothemes' ),
									'type' => 'text',
									'description' => __( 'Your Pesapal consumer secret which should have been emailed to you.', 'woothemes' ),
									'default' => ''
								),
								'testconsumerkey' => array(
									'title' => __( 'Demo Consumer Key', 'woothemes' ),
									'type' => 'text',
									'description' => __( 'Your demo Pesapal consumer key which can be seen at demo.pesapal.com.', 'woothemes' ),
									'default' => ''
								),
								'testsecretkey' => array(
									'title' => __( 'Demo Consumer Secret', 'woothemes' ),
									'type' => 'text',
									'description' => __( 'Your demo Pesapal consumer secret which can be seen at demo.pesapal.com.', 'woothemes' ),
									'default' => ''
								),
								'testnotification_id' => array(
									'title' => __( 'Demo IPN Notification Id', 'woothemes' ),
									'type' => 'text',
									'desc_tip' => esc_html__( 'The demo ID of the notification URL to be triggered on status change.', 'woothemes' ),
									'description' => __( '<a href="https://cybqa.pesapal.com/PesapalIframe/PesapalIframe3/IpnRegistration" target="_blank">Register Here</a> to generate your IPN Id. IPN URL: <strong>' . str_replace("https://","http://",$this->notify_url) . '</strong>', 'woothemes' ),
									'default' => ''
								),
								'recurring' => array (
									'title' => __('Recurring Payments', 'woothemes'),
									'type' => 'checkbox',
									'label' => __('Enable recurring payments'),
									'description' => __('Enable recurring/subscription based payments for your customers', 'woothemes'),
									'default' => 'no'
								),
								'recurring_type' => array (
									'title' => __('Recurring Account Type', 'woothemes'),
									'type' => 'checkbox',
									'label' => __('Enable predefined account entry for your customers'),
									'description' => __('Enable your clients to input a predefined account number you issue to them before subscribing to your service. If not ticked and recurring payments above is enabled, system will generate one automatically on your behalf.', 'woothemes'),
									'default' => 'no'
								),
								'surcharge' => array (
									'title' => __('Surcharge', 'woothemes'),
									'type' => 'checkbox',
									'label' => __('Enable Surcharge ( % )'),
									'description' => __('Enable a surchage fee on all client Transactions', 'woothemes'),
									'default' => 'no'
								),
								'surcharge_rate' => array(
									'title' => __('Surcharge Rate', 'woothemes'),
									'type' => 'decimal',
									'label' => __('Enter the Surchage rate in Percentage','woothemes'),
									'description' => __('Enter the Surchage Rate in Percentage ( % ) , Eg: 3.5','woothemes'),
									'default' => '0.0'
								),
								'debug' => array(
									'title' => __( 'Debug Log', 'woocommerce' ),
									'type' => 'checkbox',
									'label' => __( 'Enable logging', 'woocommerce' ),
									'default' => 'no',
									'description' => sprintf( __( 'Log PesaPal events, such as IPN requests, inside <code>woocommerce/logs/pesapal-%s.txt</code>', 'woocommerce' ), sanitize_file_name( wp_hash( 'pesapal' ) ) ),
								),
								'ipnemails' => array(
									'title' => __( 'Send IPN Email logs', 'woothemes' ),
									'type' => 'checkbox',
									'label' => __( 'Send IPN Email logs', 'woothemes' ),
									'description' => __( 'Test whether IPN triggered by pesapal hits your server. If IPN is called, email will be set to the email list you will share below', 'woothemes' ),
									'default' => 'no'
								),
								'ipnemaillist' => array(
									'title' => __( 'Emails to receive IPN alerts (comma seperated)', 'woothemes' ),
									'type' => 'text',
									'description' => __( 'List emails you wish to receive emails each time IPN hits your server.', 'woothemes' ),
									'default' => ''
								)
							);
						}
						
						public function admin_options() { ?>
							<h3><?php _e('Pesapal', 'woothemes'); ?></h3>
							<table class="form-table"><?php $this->generate_settings_html(); ?></table>
							<script type="text/javascript">
								jQuery(function(){
									var testMode = jQuery("#woocommerce_pesapal_testmode");
									var live_consumer = jQuery("#woocommerce_pesapal_consumerkey");
									var live_secret = jQuery("#woocommerce_pesapal_secretkey");
									var live_notification_id = jQuery("#woocommerce_pesapal_notification_id");
									var test_consumer = jQuery("#woocommerce_pesapal_testconsumerkey");
									var test_secret = jQuery("#woocommerce_pesapal_testsecretkey");
									var test_notification_id = jQuery("#woocommerce_pesapal_testnotification_id");
									var loaderType = jQuery("#woocommerce_pesapal_paymentsoptionspageloader").val();
									var loadjquery = jQuery("#woocommerce_pesapal_loadjquery");
									var ipnemails = jQuery("#woocommerce_pesapal_ipnemails");
									var ipnemaillist = jQuery("#woocommerce_pesapal_ipnemaillist");
									var surcharge = jQuery("#woocommerce_pesapal_surcharge");
									var surcharge_rate = jQuery("#woocommerce_pesapal_surcharge_rate");
									var recurring = jQuery("#woocommerce_pesapal_recurring");
									var recurring_type = jQuery("#woocommerce_pesapal_recurring_type");
		
									
									if(testMode.is(":not(:checked)")){
										test_consumer.parents("tr").hide();
										test_secret.parents("tr").hide();
										test_notification_id.parents("tr").hide();
										
										live_consumer.parents("tr").show();
										live_secret.parents("tr").show();
										// live_notification_id.parents("tr").show();
									}else{
										test_consumer.parents("tr").show();
										test_secret.parents("tr").show();
										test_notification_id.parents("tr").show();

										live_consumer.parents("tr").hide();
										live_secret.parents("tr").hide();
										// live_notification_id.parents("tr").hide();
									}
		
									if(loaderType=="1" || loaderType=="2"){
										loadjquery.parents("tr").show();
									} else {
										loadjquery.parents("tr").hide();
									} 
		
									if (ipnemails.is(":not(:checked)")){
										ipnemaillist.parents("tr").hide();
										ipnemaillist.parents("tr").hide();
									}
		
									testMode.click(function(){            
										// If checked
										if (testMode.is(":checked")) {
											test_consumer.parents("tr").show("fast");
											test_secret.parents("tr").show("fast");
											test_notification_id.parents("tr").show();
											
											live_consumer.parents("tr").hide("fast");
											live_secret.parents("tr").hide("fast");
											// live_notification_id.parents("tr").hide("fast");
										} else {
											test_consumer.parents("tr").hide("fast");
											test_secret.parents("tr").hide("fast");
											test_notification_id.parents("tr").hide("fast");
		
											live_consumer.parents("tr").show("fast");
											live_secret.parents("tr").show("fast");
											// live_notification_id.parents("tr").show();
										} 
									});
		
									ipnemails.click(function(){
										// If checked
										if (ipnemails.is(":checked")) {
											//show the hidden div
											ipnemaillist.parents("tr").show("fast");
											ipnemaillist.parents("tr").show("fast");
										} else {
											//otherwise, hide it
											ipnemaillist.parents("tr").hide("fast");
											ipnemaillist.parents("tr").hide("fast");
										}
									});
									
									//hide or unhide the surcharge rate option
									if (surcharge.is(":not(:checked)")){
										surcharge_rate.parents("tr").hide();
										document.getElementById("woocommerce_pesapal_surcharge_rate").setAttribute('value','0.0');
		
									}
									surcharge.click(function(){
										//if surcharge is checked
										if (surcharge.is(":checked")){
											surcharge_rate.parents("tr").show("fast");
										}else {
											surcharge_rate.parents("tr").hide("fast");
											document.getElementById("woocommerce_pesapal_surcharge_rate").setAttribute('value','0.0');
										}
									});

									//hide or unhide the recurring type option
									if (recurring.is(":not(:checked)")){
										recurring_type.parents("tr").hide();
										// document.getElementById("woocommerce_pesapal_recurring_type").setAttribute('value','0.0');
		
									}
									recurring.click(function(){
										//if recurring is checked
										if (recurring.is(":checked")){
											recurring_type.parents("tr").show("fast");
										}else {
											recurring_type.parents("tr").hide("fast");
											// document.getElementById("woocommerce_pesapal_recurring_type").setAttribute('value','0.0');
										}
									});
								});
							</script>
							<?php
						}
						
						public function process_payment( $order_id ) {
							global $woocommerce;
						
							$order = wc_get_order( $order_id );
							if($order->get_status() === 'completed'){
								//Redirect to payment page
								return array(
									'result'    => 'success',
									'redirect'  => $this->get_return_url( $order )
								);
							}else{
								return array(
									'result'    => 'success',
									'redirect'  => $order->get_checkout_payment_url(true)
								);
							} 
						}
						
						//Create Payment Page
						public function payment_page($order_id){
							$order = wc_get_order( $order_id );
								$url = $this->create_url($order_id); 
								echo '<br>';
								echo '<p><strong>';
									echo $this->get_option('customessage');
								echo '</strong></p>';
								
								if($this->paymentsoptionspageloader==3){ 
									$linkID = $this->paymentsoptionspageloader*time(); ?>
									<div class="pesapal_container" style="position:relative;">
										<p><img class="pesapal_loading_preloader" src="<?php echo THEBUNCHKE_PESAPAL_WOO_PLUGIN_URL; ?>/assets/img/loader.gif" alt="loading" /></p><br />
										<p>Loading payment options... </p><br />
										<p>Please click <a id="click-<?php echo $linkID; ?>" href="<?php echo $url; ?>" target="_new">here</a> should you have trouble loading the payments options.</p><br />
									</div>
									<script type="text/javascript">
										jQuery(document).ready(function () {
											var newTab = window.open('<?php echo $url; ?>', '_new');
											newTab.location;
										});
									</script> <?php
								}else if($this->paymentsoptionspageloader==2){ ?>
									<button data-target="PesaPalpaymentOptions" data-toggle="modal">Make Payment...!</button>
									<link href="https://www.cssscript.com/demo/simplest-modal-component-pure-javascript/modal.css" rel="stylesheet">
									<script src="https://www.cssscript.com/demo/simplest-modal-component-pure-javascript/modal.js"></script>
									<div id="PesaPalpaymentOptions" class="modal">
										<div class="modal-window small">
											<span class="close" data-dismiss="modal">×</span>
											<?php
												$ch = curl_init();
												curl_setopt($ch, CURLOPT_HEADER, 1);
												curl_setopt($ch, CURLOPT_VERBOSE, 0);
												curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
												curl_setopt($ch, CURLOPT_URL, urlencode($url));
												curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
												curl_setopt($ch, CURLOPT_HTTPGET, 1);
												curl_setopt($ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false );
												curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 2 );
		
												$response = curl_exec($ch);
												if(curl_exec($ch) === false){
													echo 'Curl error: ' . curl_error($ch);
												}else{
													echo $response;
												}
												curl_close($ch);
											?>
										</div>
									</div>
								<?php }else{  ?>
									<div id="pesapal-iframe-holder">
										<div id="pesapal-iframe-container">
											<?php if($this->loadjquery){ ?>
												<div id="pesapal-iframe-loader-msg" class="text-center">
													<div class="block"><img src="https://payments.pesapal.com/images/loader_1.gif" id="loader-img"></div>
													<div id="loader-text">Loading Payment Options...</div>
												</div>
											<?php } ?>

											<iframe id="pesapal-iframe" class="pesapal_loading_frame" src="<?php echo $url; ?>" width="100%" height="1200px" scrolling="yes" frameBorder="0">
												<p><?php _e('Browser unable to load iFrame', 'woothemes'); ?></p>
											</iframe>
											
										</div>
										<?php if($this->loadjquery){ ?>
											<div class="pp-preloader" style="display: none; "></div>
										<?php } ?>
									</div> 
									
									<?php if($this->loadjquery || $this->apiVersion==25 || $this->apiVersion==26){ ?>
										<style type="text/css">
											#pesapal-iframe{ opacity: 0; }
											#pesapal-iframe-container{position:relative;}
											#pesapal-iframe-loader-msg{position:absolute;width:100%;top: 10%; text-align: center}
											#pesapal-iframe-container h5, #pesapal-iframe-container .btn-link{ width: 100%; text-align: left; border: none!important; color: #333!important; text-decoration: none!important; background: none; font-size: 22px; line-height: 22px; outline: none; }
											#pesapal-iframe-container .card{ margin-bottom: 5px; }
											#pesapal-iframe-container .btn.mpesabtn, #pesapal-iframe-container .btn.btn-complete{ background: #333; font-size: 18px; color: #FFF!important; border: none!important;  }
											#loader-text{ margin: 10px; }
											.block{ display: block; }
											.item-fade-in{ vertical-align: top; transition: opacity 3s; -webkit-transition: opacity 3s; opacity: 1!important;}
											.item-fade-out{ vertical-align: top; transition: opacity 1s; -webkit-transition: opacity 1s; opacity: 0!important; z-index:-10; transition: 1s; }
											.woocommerce{ width: 100%; max-width: 1080px; }
											.mobilenote{ background: #FBFBFB; padding: 10px; }
											.accordion dt { margin-top: 5px; }
											.accordion dt a { line-height: 40px; width: 100%; display: block; padding: 5px 15px; border: 1px solid #DEDEDE; color: #333; margin-bottom: -1px; background: #EFEFEF; }
											.accordion dd { margin: 0 0 10px 0;  padding: 15px; border: 1px solid #DEDEDE; border-top: 0;  font-size: 12px; }
											.mobileinstr .input-group-prepend { display: inline-block; float: left; padding: 4px 10px 3px 10px; background: #DEDEDE; width: 40px; }
											.mobileinstr #stkphone{ float: left; width: calc(100% - 140px);}
											.mobileinstr .mpesabtn { float: left; width: 100px; }
										</style>
										<script type="text/javascript">
											jQuery(document).ready(function() {
												var iframeLoaderMsg = jQuery("#pesapal-iframe-loader-msg");
												var pesapalIframe = jQuery("#pesapal-iframe");
												//pesapalIframe.load(function () {
													iframeLoaderMsg.addClass("item-fade-out");
													pesapalIframe.addClass("item-fade-in");
												//});
		
												var reference = '<?php echo ($this->get_option('ppprefix')) ? strtoupper(str_replace(" ","",$this->get_option('ppprefix')))."-".$order->get_order_number() : $order->get_order_number(); ?>';
												jQuery(document).on('click', '.mpesabtn', function () {
													jQuery.ajax({
														type: 'POST',
														data: {or:reference,phone:jQuery('#stkphone').val()},
														url: '<?php echo get_site_url(); ?>/?wc-api=WC_Pesapal_Stk',
														beforeSend: function(){
															jQuery(".mpesabtn").addClass("disabled").html('Sending...');
														},
														success: function(msg){
															msg = jQuery.parseJSON(msg);
															if (msg.status==true){
																jQuery('.mobilenote').html('<p><strong>If you did not receive the MPESA PIN Request </strong></p>'+msg.instructions.fallback);
																jQuery('.mobileinstr').html(msg.instructions.message);
															}else {
																jQuery('.mobilenote').html('<p>There was an error . Please Try again <br/><strong>'+ msg.message + '</strong></p>');
																jQuery(".mpesabtn").removeClass("disabled").html('Send');
															}
														},
														complete:function(msg){
		
														},
														error:function(err){
															jQuery('.mobilenote').html('<p><strong>There was an error . Please try again later</strong></p>');
															jQuery(".mpesabtn").removeClass("disabled").html('Send');
														}
		
													});
												});

		
												var allPanels = jQuery('.accordion > dd');
												jQuery('.accordion > dt > a').click(function() {
													allPanels.slideUp();
													jQuery(this).parent().next().slideDown();
													
													return false;
												});
												jQuery('.accordion > dd.cardsdata').hide();
												jQuery('.accordion > dd.mpesadata').slideDown();
											});
										</script> <?php 
									}
								}
						}

						// log data
						public function write_log($log) {
							if (true === WP_DEBUG) {
								if (is_null($log)) {
									error_log('Log data is null.');
								} elseif (is_array($log) || is_object($log)) {
									error_log(print_r($log, true));
								} else {
									error_log($log);
								}
							}
						}
						

						// phone number validation
						public function validate_phone_number($code, $phone){
							// Allow +, - and . in phone number
							$filtered_phone_number = filter_var($phone, FILTER_SANITIZE_NUMBER_INT);
							// Remove "-" from number
							$phone = str_replace("-", "", $filtered_phone_number);

							switch ($code) {
							case "KE":
								$phone = preg_replace('/^\+?254|\|1|\D/', '0', ($phone));
								return $phone;
								break;
							case "TZ":
								$phone = preg_replace('/^\+?255|\|1|\D/', '0', ($phone));
								return $phone;
								break;
							case "UG":
								$phone = preg_replace('/^\+?256|\|1|\D/', '0', ($phone));
								return $phone;
								break;
							default:
								return $phone;
							}
						}
						
						/**
						 * Create iframe URL
						 */
						public function create_url($order_id){
							$url = "";
							$order = wc_get_order($order_id);

							$tokenResponse = $this->pesapalV30Helper->getAccessToken($this->consumer_key,$this->consumer_secret);
							// var_dump($tokenResponse->token);exit('END');

							// global $product;
							// $p_id = $product->get_id();

							$access_token = $tokenResponse->token;
							if($access_token){
								$order_tracking_data = $this->get_order_tracking_data($order_id);
								$url = (isset($order_tracking_data->redirect_url) && $order_tracking_data->redirect_url) ? $order_tracking_data->redirect_url : "";
								if(!$url){
									$request = new stdClass();
									$request->amount = $order->get_total();
									$request->currency = $order->get_currency();
									$request->callback_url =  $this->get_return_url($order);
									$merchant = $this->get_merchant();
									// $request->notification_id = $merchant->notification_id;
									$request->notification_id = ( $this->testmode ) ? $this->notification_id : $merchant->notification_id;
									// $request->notification_id = $this->notification_id;
									$request->app_id = ( $this->testmode ) ? "9929d525-e667-46d3-a167-dee83c0db482" : "fec867a7-049a-4456-848e-dee87bf84011";
									$request->billing_address_1 = str_replace(' ', '', $order->get_billing_address_1());
									$request->billing_address_2 = str_replace(' ', '', $order->get_billing_address_2());
									if(!is_numeric($request->billing_address_1)) $request->billing_address_1 = "";
									$request->billing_email = $order->get_billing_email();
									$request->billing_country = $order->get_billing_country();
									$request->billing_phone = $this->validate_phone_number($request->billing_country, $order->get_billing_phone());
									// $request->billing_phone = preg_replace("/[^0-9]/", "", str_replace(' ', '', $order->get_billing_phone()));
									$request->billing_city = $order->get_billing_city();
									$request->billing_state = $order->get_billing_state();
									$request->billing_postcode = $order->get_billing_postcode();
									$request->pesapalMerchantReference = ($this->get_option('ppprefix')) ? strtoupper(str_replace(" ","",$this->get_option('ppprefix')))."-".$order->get_order_number() : $order->get_order_number();

									$request->billing_first_name = ucfirst($order->get_billing_first_name());
									$request->billing_last_name = ucfirst($order->get_billing_last_name());
									$get_bloginfo = (get_bloginfo('name')) ? " at ".get_bloginfo('name') : "";
									$request->pesapalDescription = "Order".$get_bloginfo." from ".$request->billing_first_name." ".$request->billing_last_name." | ".$request->billing_email." | ".$request->billing_phone;
									$request->pesapalDescription = trim(urldecode(html_entity_decode(strip_tags($request->pesapalDescription))));
									$request->pesapalDescription = str_replace(array( '(', ')' ), '', htmlentities(substr($request->pesapalDescription,0,99)));
									
									$request->billing_zipcode = "";
									$request->billing_state = "";

									$account = "";

									if($this->recurring ==='yes' && $this->recurring_type === 'yes'){
										$account = $order->get_meta('account_number');
									}else if($this->recurring === 'yes' && $this->recurring_type === 'no'){
										$this->write_log("Auto");
										$ref = str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',5);
										$this->write_log("Automatic ref ".$ref);
    									$account = substr(str_shuffle($ref),0,9);
										$this->write_log("Automatic acc ".$account);
									}else{
										$account ="";
									}

									$request->account_number = $account;
									
									$ids = array();

									foreach( WC()->cart->get_cart() as $cart_item ){
										$ids[] = $cart_item['product_id'];
									}

									$request->recurring_product = $account ? $ids[0] : "";

									$this->insert_transaction($order_id, $request);
									
									// write_log('THIS IS THE START OF MY CUSTOM DEBUG');
									//i can log data like objects
									$this->write_log($request);
									$response = $this->pesapalV30Helper->getMerchertOrderURL($request,$access_token);
									$this->write_log($response);

									// $this->write_log($order);
									$account = $order->get_meta('account_number');
									$this->write_log($account);
									
									// $car = WC()->cart->get_cart();
									// $this->write_log($car);

									// $products_ids = array();

									// foreach( WC()->cart->get_cart() as $cart_item ){
									// 	$products_ids[] = $cart_item['product_id'];
									// }

									// $this->write_log($products_ids);
									// $this->write_log($products_ids[0]);
								}
							}else{ 
								echo '<div class="alert alert-danger" role="alert">';
									echo '<h3>TOKEN: '.str_replace("_"," ",strtoupper($tokenResponse->error->error_type)).'</h3>';
									echo str_replace("_"," ",ucfirst($tokenResponse->error->code));
									if($tokenResponse->error->message) { echo ". ".$tokenResponse->error->message; }
								echo '</div>'; exit; 
							}

							if(!$url && $response->status=="200"){
								$url = $response->redirect_url; 
								$this->insert_order_tracking_data($order_id,null,$response->order_tracking_id,$url);
							}else if(!$url){
								echo '<div class="alert alert-danger" role="alert">';
									echo '<h3>ORDER: '.str_replace("_"," ",strtoupper($response->error->error_type)).'</h3>';
									echo str_replace("_"," ",ucfirst($response->error->code));
									if($response->error->message) { echo ". ".$response->error->message; }
								echo '</div>'; exit; 
							}
							
							return $url;
						}
		
						public function pesapalCron(){
							$orderId = $_REQUEST['orderId']; 
							if($orderId){
								$orders[] = wc_get_order($orderId);
							}else{
								$fetch = array('processing','pending','on-hold','failed');
								$args = array(
									'status' => $fetch,
									'date_created' => '>' . ( time() - HOUR_IN_SECONDS ),
									'return' => 'ids',
								);
		
								$orders = wc_get_orders($args);
							} 
						
							foreach($orders as $orderId){
								$status = "";
								$order = wc_get_order( $orderId );
								$order_tracking_data = $this->get_order_tracking_data($order->id);
								
								$now = new DateTime();
								$date = new DateTime($order->date_created);
								if($date->diff($now)->format("%i") > 3){
									echo "<br>Order ".$order->id;
									
									if(isset($order_tracking_data->order_tracking_id) && $order_tracking_data->order_tracking_id){
										$tokenResponse = $this->pesapalV30Helper->getAccessToken($this->consumer_key,$this->consumer_secret);

										$access_token = $tokenResponse->token;
										$response = $this->pesapalV30Helper->getTransactionStatus($order_tracking_data->order_tracking_id,$access_token);
										$statusResponseJson = "API3 | ".json_encode($response);
										if(isset($response->payment_status_description) && $response->payment_status_description){
											$status = strtoupper($response->payment_status_description);
										}
									}

									if(count($_REQUEST)) $statusResponseJson .= " | ".json_encode($_REQUEST);
									echo "<br>Status: ".$status;
									echo "<br>".$statusResponseJson; 
								
									// only update the order status when the order status is pending
									if($order->get_status() === 'pending'){
										if($status=="COMPLETED"){
											$order->update_status($this->orderstatus, '<strong>Payment Completed</strong>.<br><br>You can now deliver the goods or services.');
											$order->add_order_note( __( '<strong>Cron Job</strong><br>'.$statusResponseJson, 'woocommerce' ) );
											$order->payment_complete();
										} else if ($status=="FAILED"){
											$order->update_status( 'wc-failed', '<strong>Payment Failed.</strong>');
											$order->add_order_note( __( '<strong>Cron Job</strong><br>'.$statusResponseJson, 'woocommerce' ) );
										}else if ($status=="REVERSED" ){
											$order->update_status( 'wc-refunded', '<strong>Payment Reversed.</strong>');
											$order->add_order_note( __( '<strong>Cron Job</strong><br>'.$statusResponseJson, 'woocommerce' ) );
										}
									}
									echo "<br>------<br>";
								}
		
							} echo "<br><br><br><br> --- END Of Cron Job --- ";exit;
						} 
				
						/**
						 * IPN Response
						 * @return null
						 **/
						public function ipn_response(){
							$this->write_log('IPN_RESPONSE');
							$orderTrackingId = '';
							$orderMerchantRef = '';
							$orderNotificationType = '';
							
							if(isset($_REQUEST['OrderTrackingId'])){
								$orderTrackingId = $pesapalTrackingId = $_REQUEST['OrderTrackingId'];
								$orderMerchantRef = $_REQUEST['OrderMerchantReference'];
								$orderNotificationType = $_REQUEST['OrderNotificationType'];
							}
		
							//test if IPN runs on status change
							if($this->get_option('ipnemails')){
								$actual_link = home_url( '/' );
								$to = str_replace(" ","",$this->get_option('ipnemaillist'));
								$subject = 'IPN CALLED SUCCESSFULLY: '.$orderNotificationType." ".time();
								$message = '<b>Link: </b>'.$actual_link.'<br> ';
								if($pesapalTrackingId) $message .= '<b>Order Tracking ID: </b>'.$orderTrackingId.'<br> ';
								if($orderMerchantRef) $message .= '<b>Order Merchant Reference: </b>'.$orderMerchantRef.'<br> ';
								if($orderNotificationType) $message .= '<b>Order Nofication Type: </b>'.$orderNotificationType.'<br> ';
		
								if($orderTrackingId) {
									$message .= '<strong>This emails confirms IPN works. ';
									$message .= 'If orders are not updated, we are facing a plugin order update issue and not a PesaPal IPN trigger issue.<strong>';
								}
		
								$headers = array('Content-Type: text/html; charset=UTF-8');
								$response = wp_mail( $to, $subject, $message, $headers);
							}
							
							$status = "";
							$statusResponseJson = "";
							if($orderTrackingId && $orderNotificationType === 'IPNCHANGE'){
								$this->write_log('IPN_RESPONSE2 '.$orderNotificationType);
								$order_tracking_data = $this->get_order_tracking_data(null,$orderTrackingId);
								$orderId = (isset($order_tracking_data->order_id) && $order_tracking_data->order_id) ? $order_tracking_data->order_id : "";
								if(!$orderId){
									if(!$status) { echo "End Of IPN Call! No Order Id retrieved".json_encode($_REQUEST); exit; }
								}
								
								$this->write_log('IPN_RESPONSE3 '.$orderNotificationType);

								$order = wc_get_order( $orderId );
								$tokenResponse = $this->pesapalV30Helper->getAccessToken($this->consumer_key,$this->consumer_secret);

								$access_token = $tokenResponse->token;
								$response = $this->pesapalV30Helper->getTransactionStatus($orderTrackingId,$access_token);
								$this->update_transaction($orderTrackingId, $orderMerchantRef, $orderNotificationType, $response);
								$this->write_log($response);
								$statusResponseJson = json_encode($response);
								if(isset($response->payment_status_description) && $response->payment_status_description){
									$status = strtoupper($response->payment_status_description);
								}
							}elseif($orderTrackingId && $orderNotificationType === 'RECURRING'){
								$this->write_log('IPN_RESPONSE2 '.$orderNotificationType);

								//create order
								$order = wc_create_order();
								
								$tokenResponse = $this->pesapalV30Helper->getAccessToken($this->consumer_key,$this->consumer_secret);

								$access_token = $tokenResponse->token;
								$response = $this->pesapalV30Helper->getTransactionStatus($orderTrackingId,$access_token);
								$this->write_log($response);
								$recurring_transaction = $this->get_recurring_transaction($response);
								$recurring_product_id = $recurring_transaction->recurring_product;

								// Add product to the order
								$order->add_product(wc_get_product($recurring_product_id), 1);

								// order address
								$address = array(
									"first_name" => $response->subscription_transaction_info->first_name,
									"last_name" => $response->subscription_transaction_info->last_name,
									"company" => "",
									"email" => "",
									"phone" => "",
									"address_1" => "",
									"address_2" => "",
									"city" => "",
									"state" => "",
									"postal_code" => "",
									"country" => "",
								);
								// set order address
								$order->set_address($address, "billing");

								$order->calculate_totals();

								$order_id = $order->get_id();
								$this->create_new_transaction($order_id, $orderTrackingId, $orderMerchantRef, $orderNotificationType, $response);
								
								$statusResponseJson = json_encode($response);
								if(isset($response->payment_status_description) && $response->payment_status_description){
									$status = strtoupper($response->payment_status_description);
								}
							}

							if(count($_REQUEST)) $statusResponseJson .= " | ".json_encode($_REQUEST);
							
							// We are here so lets check status and do actions
							// only update the order status when the order status is pending
							//if($order->get_status() === 'pending'){
								switch ($status) {
									case 'COMPLETED' :
										$order->update_status($this->orderstatus, '<strong>Payment Completed</strong>.<br><br>You can now deliver the goods or services.');
										$order->add_order_note( __( '<strong>IPN Request</strong><br>'.$statusResponseJson, 'woocommerce' ) ); 	
										$order->payment_complete();
			
										break;
									case 'FAILED' :
										// Order failed
										$order->update_status( 'wc-failed', '<strong>Payment Failed.</strong>' );
										$order->add_order_note( __( '<strong>IPN Request</strong><br>'.$statusResponseJson, 'woocommerce' ) ); 	
										break;
									case 'REVERSED' :
										// Order failed
										$order->update_status( 'wc-refunded', '<strong>Payment Reversed.</strong>' );
										$order->add_order_note( __( '<strong>IPN Request</strong><br>'.$statusResponseJson, 'woocommerce' ) ); 	
										break;
			
									default :
										// No action
									break;
								}
							//}
		
							if($orderTrackingId){  
								$respObjct = new stdClass();
								if($orderNotificationType) $respObjct->orderNotificationType = $orderNotificationType;
								$respObjct->OrderTrackingId = $orderTrackingId;
								$respObjct->orderMerchantReference = $orderMerchantRef;
								$respObjct->status = "200";
								$resp = json_encode($respObjct);
							}else{
								$respObjct = new stdClass();
								$respObjct->status = "500";
								$resp = json_encode($respObjct);
							}
												
							ob_start();
							echo $resp;
							ob_flush();
							exit;
							
							if(!$status) { 
								echo "End Of IPN Call! ";
								if(count($_REQUEST)) echo json_encode($_REQUEST);
								exit; 
							}
						}
					}
				}
			}
		}
	}

	//Add a custom description
	if(!class_exists('add_custom_description')){
		function add_custom_description($custom_message, $combined_description){
			global $woocommerce;
			
			//get an instance of pesapal payment gateway
			$class_get_customessage = new WC_TheBunchKE_PesaPal_Pay_Gateway();
			$chosen_payment_method = WC() && WC()->session 
            ? WC()->session->get('chosen_payment_method') 
            : null;
			if ($chosen_payment_method == 'pesapal') {
				ob_start();

				echo '<div>';
					$description = $class_get_customessage->settings['description'];
					$custom_message = $class_get_customessage->settings['customessage'];

					printf("<p>".$description."</p>"."<p style='margin-top: 12px'>".$custom_message."</p>");
				
				echo '</div>';

				$combined_description .= ob_get_clean();
				return $combined_description;
			};

		};
	}
    
	if(!class_exists('woocommerce_pesapal_surcharge')){
		function woocommerce_pesapal_surcharge() {			
			global $woocommerce;
			
			//get an instance of pesapal payment gateway
			
			$class_get_surchargerate = new WC_TheBunchKE_PesaPal_Pay_Gateway();
			
		
			if ( is_admin() &&  !defined( 'DOING_AJAX' ) )
				return;
			
			if ( ! ( is_checkout() && ! is_wc_endpoint_url() ) )
				return; //only on checkout page
			
			$chosen_payment_method = WC()->session->get('chosen_payment_method');
			if ($chosen_payment_method == 'pesapal') {
				$percentage = $class_get_surchargerate->settings['surcharge_rate']/100;
				$surcharge = (( $woocommerce->cart->cart_contents_total + $woocommerce->cart->shipping_total ) / (1-$percentage)) - ( $woocommerce->cart->cart_contents_total + $woocommerce->cart->shipping_total );
				
				WC()->cart->add_fee(__('Surcharge Fee', 'txtdomain'), $surcharge);
			}
		}
	}

	// Recurring payments
	add_action('woocommerce_after_checkout_billing_form', 'pesapal_custom_checkout_field');

	if(!class_exists('pesapal_custom_checkout_field')){
		function pesapal_custom_checkout_field($checkout){
			global $woocommerce;
			
			//get an instance of pesapal payment gateway
			
			$class_get_recurring = new WC_TheBunchKE_PesaPal_Pay_Gateway();
			$chosen_payment_method = WC()->session->get('chosen_payment_method');
			if ($chosen_payment_method == 'pesapal') {
				$recurring = $class_get_recurring->settings['recurring'];
				$recurring_type = $class_get_recurring->settings['recurring_type'];
				if($recurring === 'yes' && $recurring_type === 'yes'){
					echo '<div>';

					woocommerce_form_field('account_number', array(
						'type' => 'text',
						'class' => array('my-field-class form-row-wide'),
						'label' => 'Add your account/subscription number',
						'placeholder' => __('Account number eg 444xyz'),
						'required' => true,
					), $checkout->get_value('account_number'));

					echo '</div>';
				}
			}
		}
	}

	/**
	 * Update the order meta with field value
	 */
	add_action( 'woocommerce_checkout_update_order_meta', 'pesapal_custom_checkout_field_update_order_meta' );

	if(!class_exists('pesapal_custom_checkout_field_update_order_meta')){
		function pesapal_custom_checkout_field_update_order_meta( $order_id ) {
			if ( ! empty( $_POST['account_number'] ) ) {
				update_post_meta( $order_id, 'account_number', sanitize_text_field( $_POST['account_number'] ) );
			}
		}
	}

	// Validate account number
	add_action('woocommerce_checkout_process', 'validate_account_number_field');

	if(!class_exists('validate_account_number_field')){
		function validate_account_number_field() {
			global $woocommerce;
			
			//get an instance of pesapal payment gateway
			
			$class_get_recurring = new WC_TheBunchKE_PesaPal_Pay_Gateway();
			$chosen_payment_method = WC()->session->get('chosen_payment_method');
			if ($chosen_payment_method == 'pesapal') {
				$recurring = $class_get_recurring->settings['recurring'];
				$recurring_type = $class_get_recurring->settings['recurring_type'];
				if($recurring === 'yes' && $recurring_type === 'yes'){
					// Check if set, if its not set add an error.
					if ( ! $_POST['account_number'] )
						wc_add_notice( __( '<b>Subscription Acount number</b> is required.' ), 'error' );
				}
			}
		}
	}
   
    //Calculate the surcharge
    add_action( 'woocommerce_cart_calculate_fees', 'woocommerce_pesapal_surcharge' );
    
    //Initialize the plugin
    add_action('plugins_loaded', 'thebunchke_pesapal_woo_init', 0);

	//Add the custom description
	add_filter( 'woocommerce_gateway_description', 'add_custom_description', 20, 2 );
    
    //refresh the checkout when another payment method is selected
    add_action('woocommerce_review_order_before_payment', function() {
    ?><script type="text/javascript">
    	(function($){
    			$('form.checkout').on('change', 'input[name^="payment_method"]', function() {
    			$('body').trigger('update_checkout');
    		});
    	})(jQuery);
    </script><?php
    });
?>