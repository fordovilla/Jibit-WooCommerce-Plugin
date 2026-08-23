<?php
/*
Plugin Name: افزونه پرداخت جیبیت برای ووکامرس (Jibit for WooCommerce)
Version: 1.7.3
Description: افزونه درگاه پرداخت جیبیت (Jibit PPG) برای فروشگاه ساز ووکامرس
Plugin URI: https://jibit.ir
Author: Jibit for WooCommerce
Author URI: https://jibit.ir
Text Domain: jibit-woocommerce-payment-gateway
WC requires at least: 3.0
WC tested up to: 9.5
Requires at least: 5.8
Requires PHP: 7.2
Tested up to: 7.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
*/

if (!defined('ABSPATH')) {
    exit;
}

define('JIBIT_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JIBIT_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('JIBIT_WC_VERSION', '1.7.3');

include_once JIBIT_WC_PLUGIN_DIR . 'class-wc-gateway-jibit.php';

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('woocommerce_blocks_loaded', 'jibit_gateway_block_support');
function jibit_gateway_block_support() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    require_once JIBIT_WC_PLUGIN_DIR . 'includes/class-wc-jibit-gateway-blocks-support.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new WC_Jibit_Gateway_Blocks_Support());
        }
    );
}

const JIBIT_WC_RECONCILE_HOOK = 'jibit_reconcile_pending_purchases';
const JIBIT_WC_RECONCILE_SCHEDULE = 'jibit_every_15_minutes';

add_filter('cron_schedules', 'jibit_register_cron_schedule');
function jibit_register_cron_schedule($schedules) {
    if (!isset($schedules[JIBIT_WC_RECONCILE_SCHEDULE])) {
        $schedules[JIBIT_WC_RECONCILE_SCHEDULE] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('هر ۱۵ دقیقه (جیبیت)', 'jibit-woocommerce-payment-gateway'),
        );
    }
    return $schedules;
}

register_activation_hook(__FILE__, 'jibit_wc_activate');
function jibit_wc_activate() {
    if (!wp_next_scheduled(JIBIT_WC_RECONCILE_HOOK)) {
        wp_schedule_event(time() + 15 * MINUTE_IN_SECONDS, JIBIT_WC_RECONCILE_SCHEDULE, JIBIT_WC_RECONCILE_HOOK);
    }
}

register_deactivation_hook(__FILE__, 'jibit_wc_deactivate');
function jibit_wc_deactivate() {
    wp_clear_scheduled_hook(JIBIT_WC_RECONCILE_HOOK);
}
