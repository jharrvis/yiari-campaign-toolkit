<?php
/**
 * Bridge the shared Midtrans notification endpoint to WooCommerce orders.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets the donation Midtrans AJAX endpoint also update WooCommerce Midtrans orders.
 */
class YKT_Midtrans_Bridge {
	private const RECONCILIATION_CRON_HOOK = 'ykt_reconcile_midtrans_pending_orders';
	private const RECONCILIATION_LIMIT = 20;

	/**
	 * Register bridge hooks before the donation plugin handles the same AJAX action.
	 */
	public function init(): void {
		add_action( 'wp_ajax_midtrans_notification', array( $this, 'maybe_handle_woocommerce_notification' ), 1 );
		add_action( 'wp_ajax_nopriv_midtrans_notification', array( $this, 'maybe_handle_woocommerce_notification' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'isolate_woocommerce_midtrans_checkout_scripts' ), 100 );
		add_filter( 'script_loader_tag', array( $this, 'suppress_donation_midtrans_checkout_script_tag' ), 100, 3 );
		add_filter( 'woocommerce_coming_soon_exclude', array( $this, 'allow_woocommerce_payment_pages_during_store_coming_soon' ) );
		add_action( 'template_redirect', array( $this, 'reconcile_current_payment_page' ), 5 );
		add_filter( 'cron_schedules', array( $this, 'add_reconciliation_cron_schedule' ) );
		add_action( self::RECONCILIATION_CRON_HOOK, array( $this, 'reconcile_recent_pending_midtrans_orders' ) );

		$event = wp_get_scheduled_event( self::RECONCILIATION_CRON_HOOK );
		if ( $event && 'ykt_five_minutes' !== $event->schedule ) {
			wp_clear_scheduled_hook( self::RECONCILIATION_CRON_HOOK );
			$event = false;
		}

		if ( ! $event ) {
			wp_schedule_event( time() + 300, 'ykt_five_minutes', self::RECONCILIATION_CRON_HOOK );
		}
	}

	/**
	 * Keep donation Midtrans scripts away from WooCommerce checkout/payment pages.
	 *
	 * The donation plugin and WooCommerce Midtrans gateway can both load Snap JS, but they
	 * may use different Midtrans environments and client keys. On WooCommerce checkout and
	 * order-pay pages the WooCommerce gateway must own the Snap script so its sandbox or
	 * production key matches the order token. Donation pages keep their own scripts.
	 */
	public function isolate_woocommerce_midtrans_checkout_scripts(): void {
		if ( ! $this->is_woocommerce_checkout_payment_context() ) {
			return;
		}

		foreach ( $this->donation_midtrans_script_handles() as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
	}

	/**
	 * Allow public WooCommerce payment pages while the rest of the store remains Coming Soon.
	 */
	public function allow_woocommerce_payment_pages_during_store_coming_soon( bool $exclude ): bool {
		if ( $exclude ) {
			return true;
		}

		return $this->is_woocommerce_checkout_payment_context();
	}

	/**
	 * Suppress donation Snap script tags if another plugin enqueues them late.
	 */
	public function suppress_donation_midtrans_checkout_script_tag( string $tag, string $handle, string $src ): string {
		if ( ! $this->is_woocommerce_checkout_payment_context() ) {
			return $tag;
		}

		if ( ! in_array( $handle, $this->donation_midtrans_script_handles(), true ) ) {
			return $tag;
		}

		return '';
	}

	/**
	 * Add a short reconciliation interval for missed Midtrans webhooks.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Cron schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function add_reconciliation_cron_schedule( array $schedules ): array {
		$schedules['ykt_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'yiari-campaign-toolkit' ),
		);

		return $schedules;
	}

	/**
	 * Reconcile a Midtrans order when the donor returns to a WooCommerce payment page.
	 */
	public function reconcile_current_payment_page(): void {
		if ( ! $this->is_woocommerce_checkout_payment_context() ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-pay', 0 ) );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->is_woocommerce_midtrans_order( $order ) ) {
			return;
		}

		$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( $order_key && ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return;
		}

		$this->reconcile_order_payment( $order, 'payment page return' );
	}

	/**
	 * Periodically reconcile recent pending WooCommerce Midtrans campaign orders.
	 */
	public function reconcile_recent_pending_midtrans_orders(): void {
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'YKT_Checkout' ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'          => self::RECONCILIATION_LIMIT,
				'status'         => array( 'pending', 'pending-payment' ),
				'payment_method' => 'midtrans',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order && YKT_Checkout::order_has_campaign_package( $order ) ) {
				$this->reconcile_order_payment( $order, 'scheduled reconciliation' );
			}
		}
	}

	/**
	 * Process WooCommerce Midtrans notifications that arrive at the legacy donation endpoint.
	 *
	 * The Midtrans dashboard still points to admin-ajax.php?action=midtrans_notification for
	 * the donation plugin. When the payload belongs to a WooCommerce order, this bridge uses
	 * the official WooCommerce Midtrans plugin status lookup and notification handler, then
	 * exits before the donation plugin attempts to process the same order id. Non-WooCommerce
	 * notifications are left untouched so the donation plugin can continue handling them.
	 */
	public function maybe_handle_woocommerce_notification(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}

		$payload = $this->notification_payload();
		if ( empty( $payload['order_id'] ) ) {
			return;
		}

		$order_id = $this->restore_midtrans_order_id( (string) $payload['order_id'] );
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! $this->is_woocommerce_midtrans_order( $order ) ) {
			return;
		}

		if ( ! class_exists( 'WC_Midtrans_API' ) || ! class_exists( 'WC_Gateway_Midtrans_Notif_Handler' ) ) {
			$order->add_order_note( __( 'Midtrans bridge received notification but the WooCommerce Midtrans handler is unavailable.', 'yiari-campaign-toolkit' ) );
			wp_send_json_error( array( 'message' => 'WooCommerce Midtrans handler unavailable.' ), 500 );
		}

		$plugin_id = $this->midtrans_plugin_id_for_order( $order );

		try {
			// Query Midtrans by WooCommerce order id so the shared AJAX endpoint can accept
			// both full Midtrans JSON notifications and minimal retry payloads.
			$notification = WC_Midtrans_API::getMidtransStatus( $order->get_id(), $plugin_id );
		} catch ( Exception $exception ) {
			$order->add_order_note( sprintf( __( 'Midtrans bridge failed to verify notification: %s', 'yiari-campaign-toolkit' ), $exception->getMessage() ) );
			wp_send_json_error( array( 'message' => 'Midtrans notification verification failed.' ), 400 );
		}

		if ( empty( $notification->status_code ) || ! in_array( (int) $notification->status_code, array( 200, 201, 202, 407 ), true ) ) {
			$order->add_order_note( __( 'Midtrans bridge ignored notification because Midtrans returned an invalid status code.', 'yiari-campaign-toolkit' ) );
			wp_send_json_error( array( 'message' => 'Invalid Midtrans status code.' ), 400 );
		}

		if ( $this->is_successful_midtrans_status( $notification ) && $this->is_already_paid_order( $order ) ) {
			wp_send_json_success(
				array(
					'message'            => 'WooCommerce Midtrans notification already processed.',
					'order_id'           => $order->get_id(),
					'transaction_status' => $notification->transaction_status ?? '',
				)
			);
		}

		$handler = new WC_Gateway_Midtrans_Notif_Handler();
		$handler->handleMidtransValidNotificationRequest( $notification, $plugin_id );

		wp_send_json_success(
			array(
				'message'            => 'WooCommerce Midtrans notification processed.',
				'order_id'           => $order->get_id(),
				'transaction_status' => $notification->transaction_status ?? '',
			)
		);
	}

	/**
	 * Verify and apply a successful Midtrans status to a pending WooCommerce order.
	 */
	private function reconcile_order_payment( WC_Order $order, string $source ): bool {
		if ( ! $this->is_woocommerce_midtrans_order( $order ) || ! $order->needs_payment() ) {
			return false;
		}

		if ( ! class_exists( 'WC_Midtrans_API' ) || ! class_exists( 'WC_Gateway_Midtrans_Notif_Handler' ) ) {
			$order->add_order_note( __( 'YKT Midtrans reconciliation skipped because the WooCommerce Midtrans handler is unavailable.', 'yiari-campaign-toolkit' ) );
			return false;
		}

		$plugin_id = $this->midtrans_plugin_id_for_order( $order );

		try {
			$notification = WC_Midtrans_API::getMidtransStatus( $order->get_id(), $plugin_id );
		} catch ( Exception $exception ) {
			$this->log( sprintf( 'Midtrans reconciliation failed for order %d: %s', $order->get_id(), $exception->getMessage() ), 'warning' );
			return false;
		}

		if ( empty( $notification->status_code ) || ! in_array( (int) $notification->status_code, array( 200, 201, 202, 407 ), true ) ) {
			return false;
		}

		if ( ! $this->is_successful_midtrans_status( $notification ) ) {
			return false;
		}

		$order->add_order_note( sprintf( __( 'YKT Midtrans reconciliation confirmed payment via %s.', 'yiari-campaign-toolkit' ), $source ) );
		$handler = new WC_Gateway_Midtrans_Notif_Handler();
		$handler->handleMidtransValidNotificationRequest( $notification, $plugin_id );

		return true;
	}

	/**
	 * Read the JSON or form-encoded Midtrans notification payload.
	 *
	 * @return array<string, mixed>
	 */
	private function notification_payload(): array {
		$raw_payload = (string) file_get_contents( 'php://input' );
		$payload = json_decode( $raw_payload, true );

		if ( is_array( $payload ) ) {
			return $payload;
		}

		return wp_unslash( $_POST );
	}

	/**
	 * Check whether the current request is a WooCommerce checkout or order payment page.
	 */
	private function is_woocommerce_checkout_payment_context(): bool {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return true;
		}

		if ( '' !== (string) get_query_var( 'order-pay', '' ) ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		return str_contains( $request_uri, '/checkout/' ) || str_contains( $request_uri, '/order-pay/' );
	}

	/**
	 * Script handles used by the installed donation plugins for Midtrans Snap.
	 *
	 * @return string[]
	 */
	private function donation_midtrans_script_handles(): array {
		return array(
			'donasi-unified-midtrans-snap',
			'midtrans-snap',
		);
	}

	/**
	 * Get the WooCommerce Midtrans plugin id expected by the official handler.
	 */
	private function midtrans_plugin_id_for_order( WC_Order $order ): string {
		$plugin_id = $order->get_payment_method();

		if ( str_contains( $plugin_id, 'midtrans_sub' ) ) {
			return 'midtrans';
		}

		return $plugin_id ?: 'midtrans';
	}

	/**
	 * Restore a WooCommerce order id from a Midtrans suffixed order id when supported.
	 */
	private function restore_midtrans_order_id( string $order_id ): string {
		if ( class_exists( 'WC_Midtrans_Utils' ) ) {
			return (string) WC_Midtrans_Utils::check_and_restore_original_order_id( $order_id );
		}

		return $order_id;
	}

	/**
	 * Log bridge events without interrupting checkout/payment flows.
	 */
	private function log( string $message, string $level = 'info' ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'yiari-campaign-toolkit' ) );
		}
	}

	/**
	 * Check whether Midtrans reports the payment as successful.
	 */
	private function is_successful_midtrans_status( object $notification ): bool {
		$transaction_status = (string) ( $notification->transaction_status ?? '' );
		$fraud_status = (string) ( $notification->fraud_status ?? '' );

		return 'settlement' === $transaction_status || ( 'capture' === $transaction_status && 'accept' === $fraud_status );
	}

	/**
	 * Check whether a WooCommerce order has already passed payment confirmation.
	 */
	private function is_already_paid_order( WC_Order $order ): bool {
		if ( ! $order->needs_payment() ) {
			return true;
		}

		return in_array(
			$order->get_status(),
			array( 'paid', 'certificate-sent', 'ready-to-ship', 'shipped', 'delivered', 'impact-sent' ),
			true
		);
	}

	/**
	 * Check whether the notification belongs to a WooCommerce Midtrans gateway order.
	 */
	private function is_woocommerce_midtrans_order( WC_Order $order ): bool {
		$payment_method = $order->get_payment_method();

		return 'midtrans' === $payment_method || str_starts_with( $payment_method, 'midtrans_' );
	}
}
