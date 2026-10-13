<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    return;  // Don't load if WooCommerce isn't available.
}

class TWLD_TransactWorld_Payment_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'transactworld'; // Unique ID for the gateway.
		$this->method_title       = __( 'TransactWorld', 'transactworld-payment-integration' );
		$this->method_description = __( 'Process payments using the TransactWorld payment gateway.', 'transactworld-payment-integration' );
		$this->has_fields         = false; // No custom checkout fields.
		$this->supports           = array( 'products' );  // Supports simple product payments.

		// Initialize settings.
		$this->init_form_fields();
		$this->init_settings();

		// Get settings.
		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->merchant_id    = $this->get_option( 'merchant_id' );
		$this->working_key    = $this->get_option( 'working_key' );
		$this->totype         = $this->get_option( 'totype' );
		$this->partenerid     = $this->get_option( 'partenerid' );
		$this->language       = $this->get_option( 'language' );
		$this->ipaddr         = $this->get_option( 'ipaddr' );
		$this->livemode       = $this->get_option( 'livemode' );
		$this->liveurl        = $this->get_option( 'liveurl' );
		$this->testurl        = $this->get_option( 'testurl' );
		$this->queryurl       = $this->get_option( 'queryurl' );
        $this->paymenttype    = ""; // Default
        $this->cardtype       = "";  // Default
        $this->reservedField1 = "";   // Default
        $this->reservedField2 = "";    // Default

		$this->notify_url = WC()->api_request_url( 'TWLD_TransactWorld_Payment_Gateway' );  // Built-in WC API URL.


		// Hooks
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_wc_transactworld_payment_gateway', array( $this, 'check_response' ) );  // Listen for callbacks.
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) ); // receipt page hook
	}



	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'       => array(
				'title'   => __( 'Enable/Disable', 'transactworld-payment-integration' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable TransactWorld Payment Gateway', 'transactworld-payment-integration' ),
				'default' => 'no',
			),
			'title'         => array(
				'title'       => __( 'Title', 'transactworld-payment-integration' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'transactworld-payment-integration' ),
				'default'     => __( 'TransactWorld', 'transactworld-payment-integration' ),
				'desc_tip'    => true,
			),
			'description'   => array(
				'title'       => __( 'Description', 'transactworld-payment-integration' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'transactworld-payment-integration' ),
				'default'     => __( 'Pay securely via TransactWorld.', 'transactworld-payment-integration' ),
				'desc_tip'    => true,
			),
			'merchant_id'   => array(
				'title'       => __( 'Merchant ID', 'transactworld-payment-integration' ),
				'type'        => 'text',
				'description' => __( 'Your TransactWorld Merchant ID.', 'transactworld-payment-integration' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'working_key'   => array(
				'title'       => __( 'Working Key', 'transactworld-payment-integration' ),
				'type'        => 'text',
				'description' => __( 'Your TransactWorld Working Key.', 'transactworld-payment-integration' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'totype'        => array(
				'title'       => __( 'Partner Name', 'transactworld-payment-integration' ),
				'type'        => 'text',
				'description' => __( 'Processor Name', 'transactworld-payment-integration' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'partenerid'    => array(
				'title'       => __( 'Partner ID', 'transactworld-payment-integration' ),
				'type'        => 'text',
				'description' => __( 'Enter Partner Id', 'transactworld-payment-integration' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'language'      => array(
				'title'       => __( 'Your Store Language', 'transactworld-payment-integration' ),
				'type'        => 'select',
				'options'     => array(
					'bg' => __( 'Bulgarian', 'transactworld-payment-integration' ),
					'en' => __( 'English', 'transactworld-payment-integration' ),
					'ja' => __( 'Japanese', 'transactworld-payment-integration' ),
					'ro' => __( 'Romanian', 'transactworld-payment-integration' ),
					'sp' => __( 'Spanish', 'transactworld-payment-integration' ),
				),
				'description' => __( 'Your Store Language', 'transactworld-payment-integration' ),
				'default'     => 'en',
				'desc_tip'    => true,
			),
			'ipaddr'        => array(
				'title'       => __( 'IP Address', 'transactworld-payment-integration' ),
				'type'        => 'text',
				'description' => __( 'Enter your IP address', 'transactworld-payment-integration' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'livemode'      => array(
				'title'       => __( 'Live Mode Activation', 'transactworld-payment-integration' ),
				'type'        => 'select',
				'options'     => array(
					'N' => __( 'No', 'transactworld-payment-integration' ),
					'Y' => __( 'Yes', 'transactworld-payment-integration' ),
				),
				'description' => __( 'Enable or disable live mode.', 'transactworld-payment-integration' ),
				'default'     => 'N',
				'desc_tip'    => true,
			),
			'liveurl'       => array(
				'title'       => __( 'Live Mode URL', 'transactworld-payment-integration' ),
				'type'        => 'text',
				'description' => __( 'Live Mode Transaction URL', 'transactworld-payment-integration' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'testurl'       => array(
				'title'       => __( 'Test Mode URL', 'transactworld-payment-integration' ),
				'type'        => 'text',
				'description' => __( 'Test Mode Transaction URL', 'transactworld-payment-integration' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'queryurl'      => array(
				'title'       => __( 'Query URL', 'transactworld-payment-integration' ),
				'type'        => 'text',
				'description' => __( 'Query URL', 'transactworld-payment-integration' ),
				'default'     => '',
				'desc_tip'    => true,
			),
		);
	}


	/**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     */
    public function admin_options() {
        echo '<h3>' . esc_html__( 'TransactWorld Payment Gateway', 'transactworld-payment-integration' ) . '</h3>';
        echo '<p>' . esc_html__( 'Configure Transact payment gateway settings.', 'transactworld-payment-integration' ) . '</p>';
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

	/**
	 * There are no payment fields for TransactWorld, but we want to show the description if set.
	 */
	public function payment_fields() {
		if ( $this->description ) {
        		echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
	}

	/**
	 * Generate the TransactWorld form.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string
	 */
	public function generate_transactworld_form( $order_id ) {
		$order = wc_get_order( $order_id );

		// Get the customer's IP address, handling proxies correctly.
        $ip = $this->get_customer_ip();

		$redirecturl = $this->notify_url;
		$country     = $order->get_billing_country();  // Use WooCommerce's built-in methods
		$currency    = get_woocommerce_currency();   // Use WooCommerce's function

		// Construct the order description for TransactWorld.
		$order_description = $order_id . '-' . $this->merchant_id;


		// Calculate the checksum.
        $checksum_string = trim($this->merchant_id) . "|" . trim($this->totype) . "|" . trim(number_format((float)$order->get_total(), 2, '.', '')) . "|" . trim($order_description) . "|" . trim($redirecturl) . "|" . trim($this->working_key);
		$checksum = md5( $checksum_string );

		// Determine the correct URL based on live mode.
        $transactworld_url = ( 'Y' === $this->livemode ) ? $this->liveurl : $this->testurl;


		// Country code mapping (moved here for better organization)
        $country_code = $this->get_country_codes();


		// Prepare the arguments for the TransactWorld API.
		$transactworld_args = array(
			'toid'             => $this->merchant_id,
			'partenerid'       => $this->partenerid,
			'pctype'           => '1_1|1_2',  // What is this?  Document it!
			'ipaddr'           => $this->ipaddr,   // Configured IP Address
			'paymenttype'      => $this->paymenttype, //From settings
			'cardtype'         => $this->cardtype,    //From settings
			'reservedField1'   => $this->reservedField1,  //From settings
			'reservedField2'   => $this->reservedField2,   //From settings
			'totype'           => $this->title,      // Gateway Title
			'amount'           => number_format( $order->get_total(), 2, '.', '' ), // Format amount correctly.
			'description'      => $order_description,
			'orderdescription' => $order_description,
			'redirecturl1'     => $redirecturl,     // Use built-in WC API URL.
			'billing_name'     => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'TMPL_street'      => $order->get_billing_address_1(),
            'billing_country'  => isset( WC()->countries->countries[ $country ] ) ? WC()->countries->countries[ $country ] : $country, // Full country name.
            'TMPL_state'       => $order->get_billing_state(),
            'TMPL_city'        => $order->get_billing_city(),
            'TMPL_zip'         => $order->get_billing_postcode(),
            'TMPL_telno'       => $order->get_billing_phone(),
            'TMPL_emailaddr'   => $order->get_billing_email(),
			'delivery_name'    => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
			'delivery_address' => $order->get_shipping_address_1(),
			'delivery_country' => $order->get_shipping_country(),
			'delivery_state'   => $order->get_shipping_state(),
			'delivery_tel'     => '',  // You might want a shipping phone field in your checkout.
			'delivery_city'    => $order->get_shipping_city(),
			'delivery_zip'     => $order->get_shipping_postcode(),
			'language'         => get_locale(),     // Use WordPress locale.
			'ipaddr'           => $ip,          // Customer's IP address
			'TMPL_COUNTRY'     => $country,      // Two-letter country code
			'TMPL_CURRENCY'    => $currency,
			'currency'         => $currency,
            'telnocc'         => isset($country_code[$country]) ? $country_code[$country] : '', // Get country code
			'checksum'         => $checksum,
		);

		// Encrypt the data.
		$encryption_helper = new TWLD_TransactWorld_Encryption();

        if ( version_compare( PHP_VERSION, '7.1', '>=' ) ) {
             $encrypted_data = $encryption_helper->encrypt2( http_build_query( $transactworld_args ), $this->working_key ); // Use http_build_query
        } else {
             $encrypted_data = $encryption_helper->encrypt1( http_build_query( $transactworld_args ), $this->working_key ); // Use http_build_query

        }

		// Build the form.
		$form_fields = array();
        $form_fields[] = "<input type='hidden' name='encRequest' value='{$encrypted_data}'/>";
        $form_fields[] = "<input type='hidden' name='toid' value='{$this->merchant_id}'/>";
        $form_fields[] = "<input type='hidden' name='partenerid' value='{$this->partenerid}'/>";
        $form_fields[] = "<input type='hidden' name='lang' value='{$this->language}'/>"; //Corrected name
		$form_fields[] = "<input type='hidden' name='ipaddr' value='{$this->ipaddr}'/>";
		$form_fields[] = "<input type='hidden' name='paymenttype' value='{$this->paymenttype}'/>";
		$form_fields[] = "<input type='hidden' name='cardtype' value='{$this->cardtype}'/>";
		$form_fields[] = "<input type='hidden' name='reservedField1' value='{$this->reservedField1}'/>";
		$form_fields[] = "<input type='hidden' name='reservedField2' value='{$this->reservedField2}'/>";
		$form_fields[] = "<input type='hidden' name='totype' value='{$this->totype}'/>";
		$form_fields[] = "<input type='hidden' name='pctype' value='1_1|1_2'/>";
		$form_fields[] = "<input type='hidden' name='amount' value='".number_format( $order->get_total(), 2, '.', '' )."'/>";
		$form_fields[] = "<input type='hidden' name='orderdescription' value='{$order_description}'/>";
		$form_fields[] = "<input type='hidden' name='description' value='{$order_description}'/>";
		$form_fields[] = "<input type='hidden' name='redirecturl' value='{$redirecturl}'/>";
		$form_fields[] = "<input type='hidden' name='TMPL_street' value='{$order->get_billing_address_1()}'/>";
		$form_fields[] = "<input type='hidden' name='TMPL_state' value='{$order->get_billing_state()}'/>";
		$form_fields[] = "<input type='hidden' name='TMPL_city' value='{$order->get_billing_city()}'/>";
		$form_fields[] = "<input type='hidden' name='TMPL_zip' value='{$order->get_billing_postcode()}'/>";
		$form_fields[] = "<input type='hidden' name='TMPL_telno' value='{$order->get_billing_phone()}'/>";
        $country_value = isset($country_code[$country]) ? $country_code[$country] : '';
        $form_fields[] = "<input type='hidden' name='telnocc' value='{$country_value}'/>";
		$form_fields[] = "<input type='hidden' name='TMPL_emailaddr' value='{$order->get_billing_email()}'/>";
		$form_fields[] = "<input type='hidden' name='language' value='".esc_attr( get_locale() )."'/>";
		$form_fields[] = "<input type='hidden' name='ipaddr' value='".esc_attr( $ip )."'/>";
		$form_fields[] = "<input type='hidden' name='TMPL_COUNTRY' value='".esc_attr( $country )."'/>";
		$form_fields[] = "<input type='hidden' name='TMPL_CURRENCY' value='".esc_attr( $currency )."'/>";
		$form_fields[] = "<input type='hidden' name='currency' value='".esc_attr( $currency )."'/>";
		$form_fields[] = "<input type='hidden' name='checksum' value='".esc_attr( $checksum )."'/>";


		$form = '<form action="' . esc_url( $transactworld_url ) . '" method="post" id="transactworld_payment_form" target="_top">
            ' . implode( '', $form_fields ) . '
            <input type="submit" class="button alt" id="submit_transactworld_payment_form" value="' . esc_attr__( 'Pay via TransactWorld', 'transactworld-payment-integration' ) . '" />
            <a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . esc_html__( 'Cancel order &amp; restore cart', 'transactworld-payment-integration' ) . '</a>
        </form>';
		return $form;
	}

    /**
     * Return an array of country codes.
     */
    private function get_country_codes() {
       return array(
            "AF"=>"093",
            "AX"=>"358",
            "AL"=>"355",
            "DZ"=>"231",
            "AS"=>"684",
            "AD"=>"376",
            "AO"=>"244",
            "AI"=>"001",
            "AQ"=>"000",
            "AG"=>"001",
            "AR"=>"054",
            "AM"=>"374",
            "AW"=>"297",
            "AU"=>"061",
            "AT"=>"043",
            "AZ"=>"994",
            "BS"=>"001",
            "BH"=>"973",
            "BD"=>"880",
            "BB"=>"001",
            "BY"=>"375",
            "BE"=>"032",
            "BZ"=>"501",
            "BJ"=>"229",
            "BM"=>"001",
            "BT"=>"975",
            "BO"=>"591",
            "BA"=>"387",
            "BW"=>"267",
            "BV"=>"000",
            "BR"=>"055",
            "IO"=>"246",
            "VG"=>"001",
            "BN"=>"673",
            "BG"=>"359",
            "BF"=>"226",
            "BI"=>"257",
            "KH"=>"855",
            "CM"=>"237",
            "CA"=>"001",
            "CV"=>"238",
            "KY"=>"001",
            "CF"=>"236",
            "TD"=>"235",
            "CL"=>"056",
            "CN"=>"086",
            "CX"=>"061",
            "CC"=>"061",
            "CC"=>"061",
            "CO"=>"057",
            "KM"=>"269",
            "CK"=>"682",
            "CR"=>"506",
            "CI"=>"225",
            "HR"=>"385",
            "CU"=>"053",
            "CY"=>"357",
            "CZ"=>"420",
            "CD"=>"243",
            "DK"=>"045",
            "DJ"=>"253",
            "DM"=>"001",
            "DO"=>"001",
            "EC"=>"593",
            "EG"=>"020",
            "SV"=>"503",
            "GQ"=>"240",
            "ER"=>"291",
            "EE"=>"372",
            "ET"=>"251",
            "FK"=>"500",
            "FO"=>"298",
            "FJ"=>"679",
            "FI"=>"358",
            "FR"=>"033",
            "GF"=>"594",
            "PF"=>"689",
            "TF"=>"000",
            "GA"=>"241",
            "GM"=>"220",
            "GE"=>"995",
            "DE"=>"049",
            "GH"=>"233",
            "GI"=>"350",
            "GR"=>"030",
            "GL"=>"299",
            "GD"=>"001",
            "GP"=>"590",
            "GU"=>"001",
            "GT"=>"502",
            "GG"=>"000",
            "GN"=>"224",
            "GW"=>"245",
            "GY"=>"592",
            "HT"=>"509",
            "HM"=>"672",
            "HN"=>"504",
            "HK"=>"852",
            "HU"=>"036",
            "IS"=>"354",
            "IN"=>"091",
            "ID"=>"062",
            "IR"=>"098",
            "IQ"=>"964",
            "IE"=>"353",
            "IL"=>"972",
            "IT"=>"039",
            "JM"=>"001",
            "JP"=>"081",
            "JE"=>"044",
            "JO"=>"962",
            "KZ"=>"007",
            "KE"=>"254",
            "KI"=>"686",
            "KW"=>"965",
            "KG"=>"996",
            "LA"=>"856",
            "LV"=>"371",
            "LB"=>"961",
            "LS"=>"266",
            "LR"=>"231",
            "LY"=>"218",
            "LI"=>"423",
            "LT"=>"370",
            "LU"=>"352",
            "MO"=>"853",
            "MK"=>"389",
            "MG"=>"261",
            "MW"=>"265",
            "MY"=>"060",
            "MV"=>"960",
            "ML"=>"223",
            "MT"=>"356",
            "MH"=>"692",
            "MQ"=>"596",
            "MR"=>"222",
            "MU"=>"230",
            "YT"=>"269",
            "MX"=>"052",
            "FM"=>"691",
            "MD"=>"373",
            "MC"=>"377",
            "MN"=>"976",
            "ME"=>"382",
            "MS"=>"001",
            "MA"=>"212",
            "MZ"=>"258",
            "MM"=>"095",
            "NA"=>"264",
            "NR"=>"674",
            "NP"=>"977",
            "AN"=>"599",
            "NL"=>"031",
            "NC"=>"687",
            "NZ"=>"064",
            "NI"=>"505",
            "NE"=>"227",
            "NG"=>"234",
            "NU"=>"683",
            "NF"=>"672",
            "KP"=>"850",
            "MP"=>"001",
            "NO"=>"047",
            "OM"=>"968",
            "PK"=>"092",
            "PW"=>"680",
            "PS"=>"970",
            "PA"=>"507",
            "PG"=>"675",
            "PY"=>"595",
            "PE"=>"051",
            "PH"=>"063",
            "PN"=>"064",
            "PL"=>"048",
            "PT"=>"351",
            "PR"=>"001",
            "QA"=>"974",
            "CG"=>"242",
            "RE"=>"262",
            "RO"=>"040",
            "RU"=>"007",
            "RW"=>"250",
            "BL"=>"590",
            "SH"=>"290",
            "KN"=>"001",
            "LC"=>"001",
            "MF"=>"590",
            "PM"=>"508",
            "VC"=>"001",
            "WS"=>"685",
            "SM"=>"378",
            "ST"=>"239",
            "SA"=>"966",
            "SN"=>"221",
            "RS"=>"381",
            "SC"=>"248",
            "SL"=>"232",
            "SG"=>"065",
            "SK"=>"421",
            "SI"=>"386",
            "SB"=>"677",
            "SO"=>"252",
            "ZA"=>"027",
            "GS"=>"000",
            "KR"=>"082",
            "ES"=>"034",
            "LK"=>"094",
            "SD"=>"249",
            "SR"=>"597",
            "SJ"=>"047",
            "SZ"=>"268",
            "SE"=>"046",
            "CH"=>"041",
            "SY"=>"963",
            "TW"=>"886",
            "TJ"=>"992",
            "TZ"=>"255",
            "TH"=>"066",
            "TL"=>"670",
            "TG"=>"228",
            "TK"=>"690",
            "TO"=>"676",
            "TT"=>"001",
            "TN"=>"216",
            "TR"=>"090",
            "TM"=>"993",
            "TC"=>"001",
            "TV"=>"688",
            "UG"=>"256",
            "UA"=>"380",
            "AE"=>"971",
            "GB"=>"044",
            "US"=>"001",
            "VI"=>"001",
            "UY"=>"598",
            "UZ"=>"998",
            "VU"=>"678",
            "VA"=>"379",
            "VE"=>"058",
            "VN"=>"084",
            "WF"=>"681",
            "EH"=>"212",
            "YE"=>"967",
            "ZM"=>"260",
            "ZW"=>"263",
            );
    }

    /**
     * Get Customer IP.
     *
     * @return string
     */
	private function get_customer_ip() {
	    $ip = '';

	    // Check for HTTP_CLIENT_IP
	    if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) && isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
	        $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
	    }
	    // Check for HTTP_X_FORWARDED_FOR
	    elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
	        // Sanitize before processing
	        $forwarded_ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
	        $ip = trim( $forwarded_ips[0] );
	    }
	    // Fallback to REMOTE_ADDR
	    elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
	        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	    }

	    // Validate IP format (optional, but recommended)
	    if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
	        $ip = ''; // Return empty string if IP is invalid
	    }

	    return $ip;
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

        // Mark as on-hold (we're awaiting the payment)
        $order->update_status( 'pending', __( 'Awaiting TransactWorld payment.', 'transactworld-payment-integration' ) );


		// Store initial order status in the database.
		global $wpdb;
		$twld       = apply_filters( 'twld_database', $wpdb );
		$table_name = $twld->prefix.'twld_trans_forms';
		$wpdb->insert(
			$table_name,
			array(
				'woocommerce_order_id'  => $order_id,
				'transactworld_order_id' => $order_id . '-' . $this->merchant_id,  // Consistent format
				'order_status'          => 'authstarted',
			)
		);

		// Reduce stock levels.
		wc_reduce_stock_levels( $order_id );

		// Remove cart.
		WC()->cart->empty_cart();

		// Return success.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ), // Redirect to receipt page
		);
	}


    /**
     * Receipt page.
     *
     * @param int $order_id
     * @return void
     */
	public function receipt_page( $order_id ) {

        global $allowedposttags;

	   // Add <input> with specific attributes
	   $allowedposttags['input'] = array(
		    'type'  => true,
		    'name'  => true,
		    'value' => true,
		    'id'    => true,
		    'class' => true,
		);

        echo '<p>' . esc_html__( 'Thank you for your order, please click the button below to pay with TransactWorld.', 'transactworld-payment-integration' ) . '</p>';
        echo wp_kses_post($this->generate_transactworld_form( $order_id ));
    }



	/**
	 * Check TransactWorld response.
	 */
	public function check_response() {
		if ( ! isset( $_POST['twld_reconciliation_nonce'] ) 
		    || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['twld_reconciliation_nonce'] ) ), 'twld_reconciliation_action' ) 
		) {
		   print 'Sorry, your nonce did not verify.';
		   exit;
		}
		
		if ( ! isset( $_REQUEST['status'] ) ) { //check for status
			return;
		}

		$trackingid   = isset( $_REQUEST['trackingid'] )   ? sanitize_text_field( wp_unslash( $_REQUEST['trackingid'] ) )   : '';
		$order_id     = isset( $_REQUEST['desc'] )         ? sanitize_text_field( wp_unslash( $_REQUEST['desc'] ) )         : '';
		$amount       = isset( $_REQUEST['amount'] )       ? sanitize_text_field( wp_unslash( $_REQUEST['amount'] ) )       : '';
		$descriptor   = isset( $_REQUEST['descriptor'] )   ? sanitize_text_field( wp_unslash( $_REQUEST['descriptor'] ) )   : '';
		$checksum     = isset( $_REQUEST['checksum'] )     ? sanitize_text_field( wp_unslash( $_REQUEST['checksum'] ) )     : '';
		$order_status = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) );

		if ( empty( $order_id ) ) { //check for order id
			return;
		}


		list( $woocommerce_order_id, $merchant_id_from_response ) = explode( '-', $order_id );
        	$order = wc_get_order( $woocommerce_order_id );

        	if ( ! $order ) { //check for order
           	TWLD_TransactWorld_Logger::log( 'Error: Order not found for order ID: ' . $woocommerce_order_id );
           	wp_die( esc_html__( 'Order not found', 'transactworld-payment-integration' ), esc_html__( 'Error', 'transactworld-payment-integration' ), 404 ); // Stop execution
        	}



		global $wpdb;
		$twld       = apply_filters( 'twld_database', $wpdb );
		$table_name = $twld->prefix.'twld_trans_forms';

		try {
			switch ( $order_status ) {
				case 'Y':
                    $order->payment_complete( $trackingid ); // Mark as complete and store transaction ID.
                    $order->add_order_note( __( 'TransactWorld payment successful.', 'transactworld-payment-integration' ) );
					$cache_key = 'transactworld_order_' . $woocommerce_order_id;
					if (false === wp_cache_get($cache_key, 'transactworld')) {
					    $result = $wpdb->update(
					        $table_name,
					        array(
					            'order_status' => 'capturesuccess',
					            'tracking_id' => $trackingid,
					        ),
					        array('woocommerce_order_id' => $woocommerce_order_id),
					        array('%s', '%s'),
					        array('%d')
					    );
					    if ($result) {
					        wp_cache_set($cache_key, 'capturesuccess', 'transactworld', 3600);
					    }
					}
					break;

				case 'P':
                    $order->update_status( 'pending', __( 'TransactWorld payment pending.', 'transactworld-payment-integration' ) );
					$cache_key = 'transactworld_order_' . $woocommerce_order_id;
					if (false === wp_cache_get($cache_key, 'transactworld')) {
					    $result = $wpdb->update(
					        $table_name,
					        array(
					            'order_status' => 'authstarted',
					            'tracking_id' => $trackingid,
					        ),
					        array('woocommerce_order_id' => $woocommerce_order_id),
					        array('%s', '%s'),
					        array('%d')
					    );
					    if ($result) {
					        wp_cache_set($cache_key, 'authstarted', 'transactworld', 3600);
					    }
					}
					break;

				case 'C':
                    $order->update_status( 'cancelled', __( 'TransactWorld payment cancelled.', 'transactworld-payment-integration' ) );
					$cache_key = 'transactworld_order_' . $woocommerce_order_id;
					if (false === wp_cache_get($cache_key, 'transactworld')) {
					    $result = $wpdb->update(
					        $table_name,
					        array(
					            'order_status' => 'cancelled',
					            'tracking_id' => $trackingid,
					        ),
					        array('woocommerce_order_id' => $woocommerce_order_id),
					        array('%s', '%s'),
					        array('%d')
					    );
					    if ($result) {
					        wp_cache_set($cache_key, 'cancelled', 'transactworld', 3600);
					    }
					}
					break;

				case 'N':
                    $order->update_status( 'failed', __( 'TransactWorld payment failed.', 'transactworld-payment-integration' ) );
					$cache_key = 'transactworld_order_' . $woocommerce_order_id;
					if (false === wp_cache_get($cache_key, 'transactworld')) {
					    $result = $wpdb->update(
					        $table_name,
					        array(
					            'order_status' => 'authfailed',
					            'tracking_id' => $trackingid,
					        ),
					        array('woocommerce_order_id' => $woocommerce_order_id),
					        array('%s', '%s'),
					        array('%d')
					    );
					    if ($result) {
					        wp_cache_set($cache_key, 'authfailed', 'transactworld', 3600);
					    }
					}
					break;


				default:
                    TWLD_TransactWorld_Logger::log( 'Unknown order status received: ' . $order_status );
                    $order->update_status( 'failed', __( 'TransactWorld payment failed. Unknown status.', 'transactworld-payment-integration' ) ); // Consider a "failed" or "on-hold" status.

					break;
			}

		} catch ( Exception $e ) {
            TWLD_TransactWorld_Logger::log( 'Error processing response: ' . $e->getMessage() );
             // translators: %s is the error message returned by the TransactWorld payment gateway.
		 $error_message = sprintf( __( 'Error processing TransactWorld response: %s', 'transactworld-payment-integration' ), $e->getMessage() );
            wc_add_notice( $error_message, 'error' ); // Display error using WooCommerce notice
		}

		// Redirect to the thank you page.
		wp_safe_redirect( $this->get_return_url( $order ) ); // Use wp_safe_redirect
		exit;
	}
}