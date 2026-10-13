<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Pesapal_Gateway_Blocks extends AbstractPaymentMethodType {
    private $methods;
    protected $name = 'pesapal';

    public function initialize() {
        $this->settings = get_option('woocommerce_pesapal_settings', []);
        $this->methods = new WC_TheBunchKE_PesaPal_Pay_Gateway();
        add_action('wp_enqueue_scripts', [$this, 'register_payment_scripts']);
    }

    public function register_payment_scripts() {
        if (!is_checkout()) {
            return;
        }

        // Get proper file paths
        $plugin_path = dirname(__FILE__);
        $plugin_url = plugins_url('', __FILE__);
        
        $script_path = $plugin_path . '/checkout.js';
        $script_url = $plugin_url . '/checkout.js';
        
        // Generate version number safely
        $version = file_exists($script_path) ? filemtime($script_path) : '1.0.0';

        wp_register_script(
            'pesapal-blocks-integration',
            $script_url,
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            $version,
            true
        );

        wp_localize_script(
            'pesapal-blocks-integration',
            'pesapalBlocksData',
            [
                'gatewayData' => [
                    'title' => $this->methods->get_title(),
                    'description' => $this->methods->description,
                    'supports' => [
                        'products' => true,
                        'refunds' => true,
                    ],
                    'icon' => $this->methods->icon,
                    'enabled' => $this->methods->enabled === 'yes'
                ]
            ]
        );

        wp_enqueue_script('pesapal-blocks-integration');
    }

    public function is_active() {
        return $this->methods->is_available();
    }

    public function get_payment_method_script_handles() {
        return ['pesapal-blocks-integration'];
    }
}