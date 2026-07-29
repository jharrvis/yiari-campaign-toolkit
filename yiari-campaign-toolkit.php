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

$ykt_autoload = YKT_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $ykt_autoload ) ) {
	require_once $ykt_autoload;
}

require_once YKT_PLUGIN_DIR . 'includes/class-ykt-order-status.php';
require_once YKT_PLUGIN_DIR . 'includes/class-ykt-checkout.php';
require_once YKT_PLUGIN_DIR . 'includes/class-ykt-certificate.php';
require_once YKT_PLUGIN_DIR . 'includes/class-ykt-shipping-sync.php';
require_once YKT_PLUGIN_DIR . 'includes/class-ykt-progress-counter.php';
require_once YKT_PLUGIN_DIR . 'includes/class-ykt-campaign-frontend.php';
require_once YKT_PLUGIN_DIR . 'includes/class-ykt-midtrans-bridge.php';
require_once YKT_PLUGIN_DIR . 'includes/class-ykt-admin.php';
require_once YKT_PLUGIN_DIR . 'includes/class-ykt-segmentation.php';

register_activation_hook( __FILE__, 'ykt_activate' );
register_deactivation_hook( __FILE__, 'ykt_deactivate' );

add_action( 'plugins_loaded', 'ykt_bootstrap', 20 );

/**
 * Run plugin activation routines.
 */
function ykt_activate(): void {
	YKT_Order_Status::activate();
	YKT_Shipping_Sync::activate();
}

/**
 * Run plugin deactivation routines.
 */
function ykt_deactivate(): void {
	YKT_Shipping_Sync::deactivate();
	wp_clear_scheduled_hook( 'ykt_reconcile_midtrans_pending_orders' );
}

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
	( new YKT_Certificate() )->init();
	( new YKT_Shipping_Sync() )->init();
	( new YKT_Progress_Counter() )->init();
	( new YKT_Campaign_Frontend() )->init();
	( new YKT_Midtrans_Bridge() )->init();
	( new YKT_Admin() )->init();
	( new YKT_Segmentation() )->init();

	if ( ! class_exists( 'WC_Email' ) && defined( 'WC_ABSPATH' ) ) {
		require_once WC_ABSPATH . 'includes/emails/class-wc-email.php';
	}

	if ( class_exists( 'WC_Email' ) ) {
		require_once YKT_PLUGIN_DIR . 'includes/class-ykt-emails.php';
		( new YKT_Emails() )->init();
	}
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
