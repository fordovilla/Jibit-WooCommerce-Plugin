<?php

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Jibit_Gateway_Blocks_Support extends AbstractPaymentMethodType {

    private $gateway;

    protected $name = 'WC_Jibit';

    public function initialize() {
        $this->settings = get_option("woocommerce_{$this->name}_settings", array());

        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset($gateways[$this->name]) ? $gateways[$this->name] : null;
    }

    public function is_active() {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-jibit-blocks-integration',
            JIBIT_WC_PLUGIN_URL . 'assets/js/index.js',
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            JIBIT_WC_VERSION,
            true
        );

        return array('wc-jibit-blocks-integration');
    }

    public function get_payment_method_data() {
        // Title & description are fixed on the gateway itself (not merchant-editable settings),
        // so pull them from the live gateway instance rather than the settings option array.
        return array(
            'title' => $this->gateway ? $this->gateway->title : '',
            'description' => $this->gateway ? $this->gateway->description : '',
            'icon' => JIBIT_WC_PLUGIN_URL . 'assets/images/logo.png',
        );
    }
}
