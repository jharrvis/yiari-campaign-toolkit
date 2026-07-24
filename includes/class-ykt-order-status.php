<?php
/**
 * Campaign order statuses and transition log.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the campaign lifecycle statuses and logs order transitions.
 */
class YKT_Order_Status {
	/**
	 * Statuses in lifecycle order. Keys are WooCommerce status slugs without wc-.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			'pending-payment'    => __( 'Menunggu Pembayaran', 'yiari-campaign-toolkit' ),
			'paid'               => __( 'Dibayar', 'yiari-campaign-toolkit' ),
			'certificate-sent'   => __( 'Sertifikat Terkirim', 'yiari-campaign-toolkit' ),
			'ready-to-ship'      => __( 'Siap Dikirim', 'yiari-campaign-toolkit' ),
			'shipped'            => __( 'Sedang Dikirim', 'yiari-campaign-toolkit' ),
			'delivered'          => __( 'Diterima', 'yiari-campaign-toolkit' ),
			'impact-sent' => __( 'Laporan Dampak Terkirim', 'yiari-campaign-toolkit' ),
		);
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_statuses_to_wc' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'log_transition' ), 10, 4 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'advance_paid_campaign_order' ), 20, 4 );
	}

	/**
	 * Activation routine.
	 */
	public static function activate(): void {
		self::create_status_log_table();
	}

	/**
	 * Create the auditable transition log table.
	 */
	public static function create_status_log_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::log_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			status_from VARCHAR(40) NULL,
			status_to VARCHAR(40) NOT NULL,
			changed_at DATETIME NOT NULL,
			actor VARCHAR(60) NOT NULL DEFAULT 'system',
			PRIMARY KEY  (id),
			KEY order_id (order_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Get the status log table name.
	 */
	public static function log_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'ykt_order_status_log';
	}

	/**
	 * Register WordPress post statuses for WooCommerce orders.
	 */
	public function register_statuses(): void {
		foreach ( self::statuses() as $slug => $label ) {
			register_post_status(
				'wc-' . $slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: order count. */
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'yiari-campaign-toolkit' ),
				)
			);
		}
	}

	/**
	 * Insert campaign statuses into WooCommerce's status list.
	 *
	 * @param array<string, string> $order_statuses Existing statuses.
	 * @return array<string, string>
	 */
	public function add_statuses_to_wc( array $order_statuses ): array {
		$campaign_statuses = array();
		foreach ( self::statuses() as $slug => $label ) {
			$campaign_statuses[ 'wc-' . $slug ] = $label;
		}

		$new_statuses = array();
		foreach ( $order_statuses as $status => $label ) {
			$new_statuses[ $status ] = $label;

			if ( 'wc-pending' === $status ) {
				$new_statuses['wc-pending-payment'] = $campaign_statuses['wc-pending-payment'];
			}

			if ( 'wc-processing' === $status ) {
				foreach ( $campaign_statuses as $campaign_status => $campaign_label ) {
					if ( 'wc-pending-payment' === $campaign_status ) {
						continue;
					}
					$new_statuses[ $campaign_status ] = $campaign_label;
				}
			}
		}

		return $new_statuses;
	}

	/**
	 * Log every WooCommerce order status transition.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from Previous status without wc-.
	 * @param string   $to New status without wc-.
	 * @param WC_Order $order Order object.
	 */
	public function log_transition( int $order_id, string $from, string $to, $order ): void {
		unset( $order );

		global $wpdb;

		$actor = 'system';
		if ( is_admin() && is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user && $user->user_login ) {
				$actor = sanitize_user( $user->user_login, true );
			}
		}

		$wpdb->insert(
			self::log_table_name(),
			array(
				'order_id'    => absint( $order_id ),
				'status_from' => sanitize_key( $from ),
				'status_to'   => sanitize_key( $to ),
				'changed_at'  => current_time( 'mysql', true ),
				'actor'       => $actor,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Move paid campaign orders from gateway/core paid statuses into wc-paid.
	 *
	 * Midtrans owns payment verification. This plugin only advances confirmed
	 * campaign orders after Midtrans/WooCommerce marks them paid.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from Previous status.
	 * @param string   $to New status.
	 * @param WC_Order $order Order object.
	 */
	public function advance_paid_campaign_order( int $order_id, string $from, string $to, $order ): void {
		unset( $from );

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order || ! class_exists( 'YKT_Checkout' ) ) {
			return;
		}

		if ( ! YKT_Checkout::order_has_campaign_package( $order ) ) {
			return;
		}

		if ( ! in_array( $to, array( 'processing', 'completed' ), true ) ) {
			return;
		}

		$order->update_status( 'paid', __( 'Campaign payment confirmed.', 'yiari-campaign-toolkit' ) );
	}
}
