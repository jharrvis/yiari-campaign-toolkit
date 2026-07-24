<?php
/**
 * Plugin Name: YIARI Campaign Toolkit
 * Description: Campaign orchestration layer for the Karmila & Gito book fundraising flow.
 * Version: 0.1.0
 * Author: YIARI
 * Text Domain: yiari-campaign-toolkit
 * Requires Plugins: woocommerce
 * Requires PHP: 8.1
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YKT_VERSION', '0.1.0' );
define( 'YKT_PLUGIN_FILE', __FILE__ );
define( 'YKT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YKT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once YKT_PLUGIN_DIR . 'includes/class-ykt-order-status.php';
require_once YKT_PLUGIN_DIR . 'includes/class-ykt-checkout.php';

register_activation_hook( __FILE__, array( 'YKT_Order_Status', 'activate' ) );

add_action( 'plugins_loaded', 'ykt_bootstrap', 20 );

/**
 * Bootstrap the campaign toolkit after dependency plugins have loaded.
 */
function ykt_bootstrap(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'ykt_missing_woocommerce_notice' );
		return;
	}

	( new YKT_Order_Status() )->init();
	( new YKT_Checkout() )->init();
}

/**
 * Show a dependency notice instead of failing when WooCommerce is inactive.
 */
function ykt_missing_woocommerce_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'YIARI Campaign Toolkit requires WooCommerce to be active.', 'yiari-campaign-toolkit' );
	echo '</p></div>';
}
