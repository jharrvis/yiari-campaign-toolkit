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
	private const META_KIRIMINAJA_PICKUP_NUMBER = '_kiriminaja_pickup_number';
	private const META_AUTO_AWB_REQUESTED_AT = '_ykt_auto_awb_requested_at';
	private const META_AUTO_AWB_LAST_ERROR = '_ykt_auto_awb_last_error';
	private const META_AUTO_AWB_LAST_ATTEMPT_AT = '_ykt_auto_awb_last_attempt_at';
	private const CRON_HOOK = 'ykt_shipping_sync_poll';
	private const CRON_RECURRENCE = 'ykt_every_fifteen_minutes';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'wp_ajax_kiriof-tracking-ajax', array( $this, 'tracking_ajax_fallback' ), 1 );
		add_action( 'wp_ajax_nopriv_kiriof-tracking-ajax', array( $this, 'tracking_ajax_fallback' ), 1 );
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
			$this->maybe_request_pickup_for_order( $order );
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
				$this->maybe_request_pickup_for_order( $order );
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
		$changed_meta = $this->update_meta_if_changed( $order, self::META_KIRIMINAJA_PICKUP_NUMBER, (string) ( $transaction->pickup_number ?? '' ) ) || $changed_meta;

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
	 * Automatically ask KiriminAja to create a pickup/AWB for paid Paket B orders.
	 *
	 * The official plugin creates the transaction row at checkout, then waits for
	 * an admin request-pickup action. Campaign orders can skip that manual step
	 * once payment and certificate email are complete.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function maybe_request_pickup_for_order( WC_Order $order ): void {
		if ( 'ready-to-ship' !== $order->get_status() || ! $this->order_needs_campaign_shipping( $order ) ) {
			return;
		}

		$transaction = $this->get_kiriminaja_transaction( $order->get_id() );
		if ( ! $transaction ) {
			return;
		}

		$kiriminaja_order_id = trim( (string) ( $transaction->order_id ?? '' ) );
		$status             = sanitize_key( (string) ( $transaction->status ?? '' ) );
		$awb                = trim( (string) ( $transaction->awb ?? '' ) );
		$pickup_number      = trim( (string) ( $transaction->pickup_number ?? '' ) );

		if ( '' === $kiriminaja_order_id || '' !== $awb || '' !== $pickup_number || 'new' !== $status ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_AUTO_AWB_REQUESTED_AT, true ) ) {
			return;
		}

		if ( ! $this->auto_awb_retry_allowed( $order ) ) {
			return;
		}

		$this->normalize_transaction_to_active_courier( $order, $transaction );
		$transaction = $this->get_kiriminaja_transaction( $order->get_id() );
		if ( ! $transaction ) {
			return;
		}

		$active_couriers = $this->active_kiriminaja_couriers();
		if ( ! empty( $active_couriers ) && ! in_array( (string) ( $transaction->service ?? '' ), $active_couriers, true ) ) {
			$this->record_auto_awb_error( $order, __( 'KiriminAja transaction courier is not enabled and no active replacement rate could be applied.', 'yiari-campaign-toolkit' ) );
			return;
		}

		$kiriminaja_order_id = trim( (string) ( $transaction->order_id ?? '' ) );

		if (
			! class_exists( '\KiriminAjaOfficial\Services\TransactionProcessServices\GetRequestPickupScheduleService' )
			|| ! class_exists( '\KiriminAjaOfficial\Services\TransactionProcessServices\SendRequestPickupTransactionService' )
		) {
			$this->record_auto_awb_error( $order, __( 'KiriminAja pickup services are not available.', 'yiari-campaign-toolkit' ) );
			return;
		}

		try {
			$schedule_response = ( new \KiriminAjaOfficial\Services\TransactionProcessServices\GetRequestPickupScheduleService() )
				->orderIds( array( $kiriminaja_order_id ) )
				->call();

			if ( 200 !== (int) ( $schedule_response->status ?? 0 ) ) {
				$this->record_auto_awb_error( $order, (string) ( $schedule_response->message ?? __( 'Unable to get KiriminAja pickup schedule.', 'yiari-campaign-toolkit' ) ) );
				return;
			}

			$schedule = $this->first_pickup_schedule_clock( (array) ( $schedule_response->data['schedules'] ?? array() ) );
			if ( '' === $schedule ) {
				$this->record_auto_awb_error( $order, __( 'No KiriminAja pickup schedule is available.', 'yiari-campaign-toolkit' ) );
				return;
			}

			$pickup_response = $this->send_dropoff_request( $kiriminaja_order_id, $schedule );

			if ( 200 !== (int) ( $pickup_response->status ?? 0 ) ) {
				$this->record_auto_awb_error( $order, (string) ( $pickup_response->message ?? __( 'KiriminAja pickup request failed.', 'yiari-campaign-toolkit' ) ) );
				return;
			}

			$pickup_number = (string) ( $pickup_response->data['pickup_number'] ?? '' );
			$open_payment  = ! empty( $pickup_response->data['open_payment'] );
			$order->update_meta_data( self::META_AUTO_AWB_REQUESTED_AT, current_time( 'mysql' ) );
			$order->update_meta_data( self::META_AUTO_AWB_LAST_ERROR, '' );
			$order->update_meta_data( self::META_AUTO_AWB_LAST_ATTEMPT_AT, current_time( 'mysql' ) );
			$order->update_meta_data( self::META_KIRIMINAJA_PICKUP_NUMBER, $pickup_number );
			$order->add_order_note(
				sprintf(
					/* translators: 1: pickup number, 2: payment status. */
					__( 'KiriminAja pickup was requested automatically by YIARI Campaign Toolkit. Pickup number: %1$s. Payment status: %2$s.', 'yiari-campaign-toolkit' ),
					$pickup_number ?: '-',
					$open_payment ? 'open_payment' : (string) ( $pickup_response->data['payment_status'] ?? '-' )
				)
			);
			$order->save();
		} catch ( Throwable $throwable ) {
			$this->record_auto_awb_error( $order, $throwable->getMessage() );
		}
	}



	/**
	 * Limit repeated API attempts when KiriminAja keeps rejecting the package.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function auto_awb_retry_allowed( WC_Order $order ): bool {
		$last_attempt = (string) $order->get_meta( self::META_AUTO_AWB_LAST_ATTEMPT_AT, true );
		if ( '' === $last_attempt ) {
			return true;
		}

		$last_attempt_time = strtotime( $last_attempt );
		if ( false === $last_attempt_time ) {
			return true;
		}

		return ( time() - $last_attempt_time ) >= HOUR_IN_SECONDS;
	}

	/**
	 * Switch old pending KiriminAja transactions to the currently enabled courier.
	 *
	 * Existing paid orders keep their WooCommerce totals. This only updates the
	 * package data KiriminAja needs to create the drop-off AWB.
	 *
	 * @param WC_Order $order Order object.
	 * @param object   $transaction KiriminAja transaction row.
	 */
	private function normalize_transaction_to_active_courier( WC_Order $order, object $transaction ): void {
		$active_couriers = $this->active_kiriminaja_couriers();
		$current_service = trim( (string) ( $transaction->service ?? '' ) );
		if ( empty( $active_couriers ) || in_array( $current_service, $active_couriers, true ) ) {
			return;
		}

		$rate = $this->first_pricing_rate_for_transaction( $transaction, $active_couriers );
		if ( ! $rate ) {
			$this->record_auto_awb_error( $order, __( 'No active KiriminAja courier rate is available for this old transaction.', 'yiari-campaign-toolkit' ) );
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kiriminaja_transactions';
		$wpdb->update(
			$table,
			array(
				'service'             => sanitize_text_field( (string) ( $rate->service ?? '' ) ),
				'service_name'        => sanitize_text_field( (string) ( $rate->service_type ?? '' ) ),
				'shipping_cost'       => (float) ( $rate->cost ?? 0 ),
				'insurance_cost'      => (float) ( $rate->insurance ?? 0 ),
				'discount_amount'     => 0,
				'discount_percentage' => 0,
			),
			array( 'order_id' => (string) $transaction->order_id )
		);

		$order->add_order_note(
			sprintf(
				/* translators: 1: old courier, 2: new courier, 3: service type. */
				__( 'KiriminAja transaction courier was normalized for auto drop-off AWB. Old courier: %1$s. New courier: %2$s %3$s. WooCommerce paid total was not changed.', 'yiari-campaign-toolkit' ),
				$current_service ?: '-',
				(string) ( $rate->service ?? '-' ),
				(string) ( $rate->service_type ?? '' )
			)
		);
		$order->save();
	}

	/**
	 * Return currently enabled KiriminAja courier IDs.
	 *
	 * @return array<int, string>
	 */
	private function active_kiriminaja_couriers(): array {
		if ( ! class_exists( '\KiriminAjaOfficial\Repositories\SettingRepository' ) ) {
			return array();
		}

		try {
			return ( new \KiriminAjaOfficial\Repositories\SettingRepository() )->getWhitelistExpeditionIds();
		} catch ( Throwable $throwable ) {
			return array();
		}
	}

	/**
	 * Fetch the first active courier rate for an existing transaction.
	 *
	 * @param object             $transaction KiriminAja transaction row.
	 * @param array<int, string> $couriers Active courier IDs.
	 */
	private function first_pricing_rate_for_transaction( object $transaction, array $couriers ): ?object {
		if ( ! class_exists( '\KiriminAjaOfficial\Repositories\KiriminajaApiRepository' ) ) {
			return null;
		}

		$origin_id = $this->origin_sub_district_id();
		$destination_id = (int) ( $transaction->destination_sub_district_id ?? 0 );
		if ( $origin_id < 1 || $destination_id < 1 ) {
			return null;
		}

		$response = ( new \KiriminAjaOfficial\Repositories\KiriminajaApiRepository() )->getPricing(
			array(
				'subdistrict_origin'      => $origin_id,
				'subdistrict_destination' => $destination_id,
				'weight'                  => (int) max( 1, (int) ( $transaction->weight ?? 1 ) ),
				'length'                  => (int) max( 1, (int) ( $transaction->length ?? 1 ) ),
				'width'                   => (int) max( 1, (int) ( $transaction->width ?? 1 ) ),
				'height'                  => (int) max( 1, (int) ( $transaction->height ?? 1 ) ),
				'insurance'               => 1,
				'item_value'              => (int) max( 1, (int) ( $transaction->transaction_value ?? 1 ) ),
				'courier'                 => $couriers,
			)
		);

		foreach ( (array) ( $response['data']->results ?? array() ) as $rate ) {
			if ( ! empty( $rate->drop ) ) {
				return $rate;
			}
		}

		return null;
	}

	/**
	 * Current KiriminAja origin kelurahan ID.
	 */
	private function origin_sub_district_id(): int {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}kiriminaja_settings WHERE `key` = %s", 'origin_sub_district_id' ) );
		return absint( $value );
	}

	/**
	 * Send a KiriminAja request_pickup payload in drop-off mode.
	 *
	 * @param string $kiriminaja_order_id KiriminAja order ID.
	 * @param string $schedule Pickup schedule clock required by the API.
	 */
	private function send_dropoff_request( string $kiriminaja_order_id, string $schedule ) {
		$service = ( new \KiriminAjaOfficial\Services\TransactionProcessServices\SendRequestPickupTransactionService() )
			->orderIds( array( $kiriminaja_order_id ) )
			->schedule( $schedule );

		if ( $this->auto_awb_should_use_qris() ) {
			$service->paymentMethod( 'qris' );
		}

		try {
			$origin_method = new ReflectionMethod( $service, 'getOriginData' );
			$origin_method->setAccessible( true );
			$packages_method = new ReflectionMethod( $service, 'getPackagesData' );
			$packages_method->setAccessible( true );

			$origin_data = (array) $origin_method->invoke( $service );
			$packages = array_map(
				static function ( array $package ): array {
					unset(
						$package['destination_summary'],
						$package['is_cod'],
						$package['discount_amount'],
						$package['shipping_discount_amount'],
						$package['woocommerce_discount_amount'],
						$package['discount_percentage'],
						$package['woocommerce_discount_description']
					);
					$package['drop'] = true;
					return $package;
				},
				(array) $packages_method->invoke( $service )
			);

			$origin_address = (string) ( $origin_data['origin_address'] ?? '' );
			$payload = array(
				'address'       => $origin_address,
				'phone'         => $this->normalize_phone_for_kiriminaja( (string) ( $origin_data['origin_phone'] ?? '' ) ),
				'kelurahan_id'  => (int) ( $origin_data['origin_sub_district_id'] ?? 0 ),
				'packages'      => $packages,
				'name'          => preg_replace( '/[^a-zA-Z\d\s]/', '', html_entity_decode( (string) ( $origin_data['origin_name'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				'zipcode'       => $this->origin_zipcode_for_dropoff( $origin_address, (string) ( $origin_data['origin_zip_code'] ?? '' ) ),
				'schedule'      => $schedule,
				'platform_name' => 'wordpress',
				'dropoff'       => true,
			);

			foreach ( $payload['packages'] as &$package ) {
				if ( isset( $package['destination_phone'] ) ) {
					$package['destination_phone'] = $this->normalize_phone_for_kiriminaja( (string) $package['destination_phone'] );
				}
			}
			unset( $package );

			if ( $this->auto_awb_should_use_qris() ) {
				$payload['payment_method'] = 'qris';
			}

			$api_response = ( new \KiriminAjaOfficial\Repositories\KiriminajaApiRepository() )->sendPickupRequestV2( $payload );
			if ( empty( $api_response['status'] ) || empty( $api_response['data']->status ) ) {
				$api_data = $api_response['data'] ?? null;
				return new \KiriminAjaOfficial\Utils\ServiceResponse( array(), is_object( $api_data ) ? (string) ( $api_data->text ?? 'KiriminAja drop-off request failed.' ) : (string) $api_data, 400 );
			}

			return $this->store_successful_dropoff_request( $kiriminaja_order_id, $schedule, $api_response['data'], $packages );
		} catch ( Throwable $throwable ) {
			return new \KiriminAjaOfficial\Utils\ServiceResponse( array(), $throwable->getMessage(), 400 );
		}
	}


	/**
	 * Normalize Indonesian phone numbers for KiriminAja package creation.
	 *
	 * @param string $phone Raw phone number.
	 */
	private function normalize_phone_for_kiriminaja( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( ! is_string( $digits ) ) {
			return $phone;
		}

		if ( 0 === strpos( $digits, '62' ) ) {
			return $digits;
		}

		if ( 0 === strpos( $digits, '0' ) ) {
			return '62' . substr( $digits, 1 );
		}

		return $digits;
	}

	/**
	 * Prefer the explicit postal code embedded in YIARI's origin address.
	 *
	 * @param string $origin_address Origin address text.
	 * @param string $stored_zipcode Stored KiriminAja zipcode.
	 */
	private function origin_zipcode_for_dropoff( string $origin_address, string $stored_zipcode ): string {
		if ( preg_match( '/\b(\d{5})\b(?!.*\b\d{5}\b)/', $origin_address, $matches ) ) {
			return $matches[1];
		}

		return $stored_zipcode;
	}

	/**
	 * Mirror the official plugin's local state after a successful drop-off request.
	 *
	 * @param string             $kiriminaja_order_id KiriminAja order ID.
	 * @param string             $schedule Schedule clock.
	 * @param object             $api_data KiriminAja API response data.
	 * @param array<int, array>  $packages Request packages.
	 */
	private function store_successful_dropoff_request( string $kiriminaja_order_id, string $schedule, object $api_data, array $packages ) {
		global $wpdb;

		$pickup_number = (string) ( $api_data->pickup_number ?? '' );
		$current_time  = gmdate( 'Y-m-d H:i:s' );
		$table = $wpdb->prefix . 'kiriminaja_transactions';
		$wpdb->update(
			$table,
			array(
				'status'            => 'request_pickup',
				'pickup_number'     => $pickup_number,
				'request_pickup_at' => $current_time,
			),
			array( 'order_id' => $kiriminaja_order_id )
		);

		$payment_method = $this->auto_awb_should_use_qris() ? 'qris' : 'TOP';
		$api_payment_status = strtolower( (string) ( $api_data->payment_status ?? '' ) );
		$payment_status = 'qris' === $payment_method && 'paid' !== $api_payment_status ? 'unpaid' : 'paid';

		if ( class_exists( '\KiriminAjaOfficial\Repositories\PaymentRepository' ) && '' !== $pickup_number ) {
			( new \KiriminAjaOfficial\Repositories\PaymentRepository() )->createPayment(
				array(
					'pickup_number'   => $pickup_number,
					'status'          => $payment_status,
					'method'          => $payment_method,
					'order_amt'       => count( $packages ),
					'pickup_schedule' => $schedule,
					'created_at'      => $current_time,
				)
			);
		}

		return new \KiriminAjaOfficial\Utils\ServiceResponse(
			array(
				'pickup_number'  => $pickup_number,
				'open_payment'   => 'unpaid' === $payment_status,
				'payment_method' => $payment_method,
				'payment_status' => $payment_status,
			),
			'success',
			200
		);
	}

	/**
	 * Whether the connected KiriminAja merchant needs QRIS for auto pickup.
	 */
	private function auto_awb_should_use_qris(): bool {
		if ( ! class_exists( '\KiriminAjaOfficial\Services\SettingService' ) ) {
			return true;
		}

		try {
			return ! ( new \KiriminAjaOfficial\Services\SettingService() )->isTopPaymentMethod();
		} catch ( Throwable $throwable ) {
			return true;
		}
	}

	/**
	 * Pick the first schedule clock returned by KiriminAja.
	 *
	 * @param array<int, object|array<string, mixed>> $schedules Schedule options.
	 */
	private function first_pickup_schedule_clock( array $schedules ): string {
		foreach ( $schedules as $schedule ) {
			$clock = is_object( $schedule ) ? ( $schedule->clock ?? '' ) : ( $schedule['clock'] ?? '' );
			$clock = trim( (string) $clock );
			if ( '' !== $clock ) {
				return $clock;
			}
		}

		return '';
	}

	/**
	 * Store the latest auto AWB error in order meta and notes.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $message Error message.
	 */
	private function record_auto_awb_error( WC_Order $order, string $message ): void {
		$message = trim( wp_strip_all_tags( $message ) );
		if ( '' === $message ) {
			$message = __( 'Unknown KiriminAja auto AWB error.', 'yiari-campaign-toolkit' );
		}

		$order->update_meta_data( self::META_AUTO_AWB_LAST_ERROR, $message );
		$order->update_meta_data( self::META_AUTO_AWB_LAST_ATTEMPT_AT, current_time( 'mysql' ) );
		$order->add_order_note(
			sprintf(
				/* translators: %s: error message. */
				__( 'Automatic KiriminAja pickup/AWB request failed: %s', 'yiari-campaign-toolkit' ),
				$message
			)
		);
		$order->save();
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
				"SELECT * FROM {$table} WHERE wp_wc_order_stat_order_id = %d ORDER BY id DESC LIMIT 1",
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
	 * Serve campaign tracking rows when KiriminAja's default lookup misses wc_order_stats.
	 */
	public function tracking_ajax_fallback(): void {
		// Public read-only tracking endpoint; follows the KiriminAja shortcode contract.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order_number = isset( $_POST['order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['order_number'] ) ) : '';
		$transaction  = $this->get_tracking_transaction( $order_number );
		if ( ! $transaction ) {
			return;
		}

		wp_send_json_success(
			array(
				'status'  => 200,
				'message' => 'success',
				'data'    => $this->build_tracking_response( $transaction ),
			)
		);
	}

	/**
	 * Fetch a transaction by order ID, KiriminAja order ID, pickup number, or AWB.
	 *
	 * @param string $tracking_number Public tracking search value.
	 */
	private function get_tracking_transaction( string $tracking_number ): ?object {
		global $wpdb;

		$tracking_number = trim( $tracking_number );
		if ( '' === $tracking_number ) {
			return null;
		}

		$table = $wpdb->prefix . 'kiriminaja_transactions';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists !== $table ) {
			return null;
		}

		$normalized = preg_replace( '/[^A-Za-z0-9]/', '', $tracking_number );
		if ( '' === $normalized ) {
			$normalized = $tracking_number;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE wp_wc_order_stat_order_id = %d
					OR order_id = %s
					OR REPLACE(REPLACE(order_id, '-', ''), ' ', '') = %s
					OR pickup_number = %s
					OR REPLACE(REPLACE(pickup_number, '-', ''), ' ', '') = %s
					OR awb = %s
					OR REPLACE(REPLACE(awb, '-', ''), ' ', '') = %s
				ORDER BY id DESC
				LIMIT 1",
				absint( $tracking_number ),
				$tracking_number,
				$normalized,
				$tracking_number,
				$normalized,
				$tracking_number,
				$normalized
			)
		);

		if ( ! is_object( $row ) ) {
			return null;
		}

		$order = wc_get_order( (int) $row->wp_wc_order_stat_order_id );
		if ( ! $order instanceof WC_Order || ! $this->order_needs_campaign_shipping( $order ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Build the JSON shape expected by KiriminAja's public tracking script.
	 *
	 * @param object $transaction KiriminAja transaction row.
	 * @return array<string, mixed>
	 */
	private function build_tracking_response( object $transaction ): array {
		$order_id = (int) ( $transaction->wp_wc_order_stat_order_id ?? 0 );
		$order    = wc_get_order( $order_id );
		$details  = $this->fallback_tracking_details( $transaction, $order instanceof WC_Order ? $order : null );
		$histories = array();

		if ( '' !== trim( (string) ( $transaction->pickup_number ?? '' ) ) ) {
			$histories[] = (object) array(
				'status'      => __( 'Pickup KiriminAja sudah diminta', 'yiari-campaign-toolkit' ),
				'status_code' => 100,
				'created_at'  => $this->format_tracking_time( (string) ( $transaction->request_pickup_at ?? current_time( 'mysql' ) ) ),
				'driver'      => '',
				'receiver'    => '',
			);
		}

		if ( $order instanceof WC_Order && $order->is_paid() ) {
			$histories[] = (object) array(
				'status'      => __( 'Transaksi dikonfirmasi dan diproses', 'yiari-campaign-toolkit' ),
				'status_code' => 100,
				'created_at'  => $this->format_tracking_time( (string) $order->get_date_paid() ),
				'driver'      => '',
				'receiver'    => '',
			);
		}

		$histories[] = (object) array(
			'status'      => __( 'Transaksi berhasil check out dengan metode pembayaran NON COD', 'yiari-campaign-toolkit' ),
			'status_code' => 100,
			'created_at'  => $this->format_tracking_time( (string) ( $transaction->created_at ?? current_time( 'mysql' ) ) ),
			'driver'      => '',
			'receiver'    => '',
		);

		return array(
			'number_order' => $order_id,
			'details'      => $details,
			'histories'    => $histories,
		);
	}

	/**
	 * Fallback tracking details from the local KiriminAja row and Woo order.
	 *
	 * @param object        $transaction KiriminAja transaction row.
	 * @param WC_Order|null $order WooCommerce order.
	 */
	private function fallback_tracking_details( object $transaction, ?WC_Order $order ): object {
		$destination_name = $order instanceof WC_Order ? trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) : '';
		if ( '' === $destination_name && $order instanceof WC_Order ) {
			$destination_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		return (object) array(
			'awb'         => trim( (string) ( $transaction->awb ?? '' ) ) ?: '-',
			'service'     => trim( (string) ( $transaction->service_name ?? '' ) ) ?: trim( (string) ( $transaction->service ?? '-' ) ),
			'destination' => array(
				'name'     => $destination_name ?: '-',
				'city'     => trim( (string) ( $transaction->destination_sub_district ?? '' ) ) ?: '-',
				'province' => trim( (string) ( $transaction->destination_address ?? '' ) ) ?: '-',
			),
		);
	}

	/**
	 * Format tracking timestamps consistently with the KiriminAja shortcode.
	 *
	 * @param string $timestamp Timestamp string.
	 */
	private function format_tracking_time( string $timestamp ): string {
		$time = strtotime( $timestamp );
		if ( false === $time ) {
			$time = time();
		}

		return gmdate( 'd F Y H:i', $time ) . ' WIB';
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
