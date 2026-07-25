<?php
/**
 * KiriminAja shipping status synchronization.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observes KiriminAja transaction rows and maps them into campaign statuses.
 */
class YKT_Shipping_Sync {
	private const META_PACKAGE_TYPE = '_campaign_package_type';
	private const META_AWB_NUMBER = '_shipping_awb_number';
	private const META_COURIER_NAME = '_shipping_courier_name';
	private const META_KIRIMINAJA_ORDER_ID = '_kiriminaja_order_id';
	private const META_KIRIMINAJA_STATUS = '_kiriminaja_status';
	private const CRON_HOOK = 'ykt_shipping_sync_poll';
	private const CRON_RECURRENCE = 'ykt_every_fifteen_minutes';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'prepare_b_package_after_certificate' ), 45, 4 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_order_after_status_change' ), 65, 4 );
		add_action( self::CRON_HOOK, array( $this, 'poll_open_shipments' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_RECURRENCE, self::CRON_HOOK );
		}
	}

	/**
	 * Schedule polling when the plugin is activated.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_RECURRENCE, self::CRON_HOOK );
		}
	}

	/**
	 * Remove scheduled polling when the plugin is deactivated.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Add a 15-minute polling interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_cron_schedule( array $schedules ): array {
		$schedules[ self::CRON_RECURRENCE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'yiari-campaign-toolkit' ),
		);

		return $schedules;
	}

	/**
	 * Paket B enters the shipping branch after its certificate is generated.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from Previous status.
	 * @param string   $to New status.
	 * @param WC_Order $order Order object.
	 */
	public function prepare_b_package_after_certificate( int $order_id, string $from, string $to, $order ): void {
		unset( $from );

		if ( 'certificate-sent' !== $to ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order || ! $this->order_needs_campaign_shipping( $order ) ) {
			return;
		}

		$order->update_status( 'ready-to-ship', __( 'Campaign Paket B is ready for KiriminAja shipment processing.', 'yiari-campaign-toolkit' ) );
	}

	/**
	 * Sync one order immediately when relevant statuses change.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from Previous status.
	 * @param string   $to New status.
	 * @param WC_Order $order Order object.
	 */
	public function sync_order_after_status_change( int $order_id, string $from, string $to, $order ): void {
		unset( $from );

		if ( ! in_array( $to, array( 'ready-to-ship', 'shipped', 'completed' ), true ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( $order instanceof WC_Order ) {
			$this->sync_order_from_kiriminaja( $order );
		}
	}

	/**
	 * Poll open campaign shipments for KiriminAja AWB/delivery changes.
	 */
	public function poll_open_shipments(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$order_ids = wc_get_orders(
			array(
				'limit'      => 50,
				'return'     => 'ids',
				'status'     => array( 'certificate-sent', 'ready-to-ship', 'shipped' ),
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => array(
					array(
						'key'     => self::META_PACKAGE_TYPE,
						'value'   => array( 'B', 'MIXED' ),
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$this->sync_order_from_kiriminaja( $order );
			}
		}
	}

	/**
	 * Read KiriminAja's transaction table and update order meta/status.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function sync_order_from_kiriminaja( WC_Order $order ): void {
		if ( ! $this->order_needs_campaign_shipping( $order ) ) {
			return;
		}

		$transaction = $this->get_kiriminaja_transaction( $order->get_id() );
		if ( ! $transaction ) {
			return;
		}

		$awb = isset( $transaction->awb ) ? trim( (string) $transaction->awb ) : '';
		$courier = isset( $transaction->service_name ) ? trim( (string) $transaction->service_name ) : '';
		if ( '' === $courier && isset( $transaction->service ) ) {
			$courier = trim( (string) $transaction->service );
		}
		$kiriminaja_status = isset( $transaction->status ) ? sanitize_key( (string) $transaction->status ) : '';

		$changed_meta = false;
		$changed_meta = $this->update_meta_if_changed( $order, self::META_AWB_NUMBER, $awb ) || $changed_meta;
		$changed_meta = $this->update_meta_if_changed( $order, self::META_COURIER_NAME, $courier ) || $changed_meta;
		$changed_meta = $this->update_meta_if_changed( $order, self::META_KIRIMINAJA_ORDER_ID, (string) ( $transaction->order_id ?? '' ) ) || $changed_meta;
		$changed_meta = $this->update_meta_if_changed( $order, self::META_KIRIMINAJA_STATUS, $kiriminaja_status ) || $changed_meta;

		if ( $changed_meta ) {
			$order->save();
		}

		$target_status = $this->target_status_for_transaction( $kiriminaja_status, $awb );
		if ( ! $target_status || $target_status === $order->get_status() ) {
			return;
		}

		if ( ! $this->can_advance_to_status( $order->get_status(), $target_status ) ) {
			return;
		}

		$order->update_status(
			$target_status,
			sprintf(
				/* translators: 1: KiriminAja status, 2: AWB number. */
				__( 'Campaign shipping synchronized from KiriminAja. Status: %1$s. AWB: %2$s.', 'yiari-campaign-toolkit' ),
				$kiriminaja_status ?: '-',
				$awb ?: '-'
			)
		);
	}

	/**
	 * Fetch one KiriminAja transaction row for a WooCommerce order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	private function get_kiriminaja_transaction( int $order_id ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'kiriminaja_transactions';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists !== $table ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT order_id, awb, service, service_name, status FROM {$table} WHERE wp_wc_order_stat_order_id = %d ORDER BY id DESC LIMIT 1",
				$order_id
			)
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Map KiriminAja transaction data to campaign lifecycle status.
	 *
	 * @param string $kiriminaja_status KiriminAja transaction status.
	 * @param string $awb AWB/resi number.
	 */
	private function target_status_for_transaction( string $kiriminaja_status, string $awb ): string {
		if ( 'finished' === $kiriminaja_status ) {
			return 'delivered';
		}

		if ( 'shipped' === $kiriminaja_status || '' !== $awb ) {
			return 'shipped';
		}

		return '';
	}

	/**
	 * Prevent status regressions in the shipping branch.
	 *
	 * @param string $current Current status without wc-.
	 * @param string $target Target status without wc-.
	 */
	private function can_advance_to_status( string $current, string $target ): bool {
		$order = array(
			'certificate-sent' => 1,
			'ready-to-ship'    => 2,
			'shipped'          => 3,
			'delivered'        => 4,
			'impact-sent'      => 5,
		);

		return ( $order[ $target ] ?? 0 ) > ( $order[ $current ] ?? 0 );
	}

	/**
	 * Whether an order is a campaign Paket B/mixed order that needs shipping.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function order_needs_campaign_shipping( WC_Order $order ): bool {
		$package_type = strtoupper( (string) $order->get_meta( self::META_PACKAGE_TYPE, true ) );

		return in_array( $package_type, array( 'B', 'MIXED' ), true );
	}

	/**
	 * Update order meta only when the value changed.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $key Meta key.
	 * @param string   $value New value.
	 */
	private function update_meta_if_changed( WC_Order $order, string $key, string $value ): bool {
		if ( (string) $order->get_meta( $key, true ) === $value ) {
			return false;
		}

		$order->update_meta_data( $key, $value );
		return true;
	}
}
