<?php
/**
 * Donor segmentation and impact broadcast tools.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes donor segments and provides an impact broadcast screen.
 */
class YKT_Segmentation {
	private const META_PACKAGE_TYPE = '_campaign_package_type';
	private const META_DONOR_SEGMENT = '_donor_segment';
	private const META_IMPACT_MESSAGE = '_impact_update_message';
	private const META_MITRA_MANUAL = '_donor_segment_mitra';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'tag_paid_order' ), 35, 4 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_segment_controls' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_segment_controls' ), 30, 1 );
		add_action( 'admin_menu', array( $this, 'register_impact_page' ) );
		add_action( 'admin_post_ykt_send_impact_update', array( $this, 'handle_impact_broadcast' ) );
		add_action( 'admin_notices', array( $this, 'render_broadcast_notice' ) );
	}

	/**
	 * Default big donor threshold, filterable until YIARI confirms the final rule.
	 */
	public static function big_donor_threshold(): float {
		/**
		 * Filters the order-total threshold for the donatur_besar segment.
		 *
		 * Default is intentionally conservative and editable without code changes.
		 *
		 * @param float $threshold Threshold in store currency.
		 */
		return (float) apply_filters( 'ykt_big_donor_threshold', 1000000.0 );
	}

	/**
	 * Default big donor package quantity threshold, filterable until confirmed.
	 */
	public static function big_donor_quantity_threshold(): int {
		/**
		 * Filters the campaign package quantity threshold for the donatur_besar segment.
		 *
		 * @param int $threshold Package quantity threshold.
		 */
		return (int) apply_filters( 'ykt_big_donor_quantity_threshold', 10 );
	}

	/**
	 * Compute segments when campaign payment is confirmed.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from Previous status.
	 * @param string   $to New status.
	 * @param WC_Order $order Order object.
	 */
	public function tag_paid_order( int $order_id, string $from, string $to, $order ): void {
		unset( $from );

		if ( 'paid' !== $to ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( $order instanceof WC_Order ) {
			$this->save_segments_for_order( $order );
		}
	}

	/**
	 * Render manual segment controls on order edit screen.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function render_segment_controls( $order ): void {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$segments = $this->segments_for_order( $order );
		echo '<p class="form-field form-field-wide"><strong>' . esc_html__( 'Campaign Segments', 'yiari-campaign-toolkit' ) . '</strong><br>' . esc_html( $segments ? implode( ', ', $segments ) : '-' ) . '</p>';

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_MITRA_MANUAL,
				'label'       => __( 'Mark as Mitra', 'yiari-campaign-toolkit' ),
				'value'       => $order->get_meta( self::META_MITRA_MANUAL, true ) ? 'yes' : 'no',
				'description' => __( 'Manual partner segment tag for this campaign order.', 'yiari-campaign-toolkit' ),
			)
		);
	}

	/**
	 * Save manual segment controls.
	 *
	 * @param int $order_id Order ID.
	 */
	public function save_segment_controls( int $order_id ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data( self::META_MITRA_MANUAL, isset( $_POST[ self::META_MITRA_MANUAL ] ) ? 1 : 0 );
		$this->save_segments_for_order( $order );
	}

	/**
	 * Register Impact Broadcast submenu under WooCommerce.
	 */
	public function register_impact_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Campaign Impact Broadcast', 'yiari-campaign-toolkit' ),
			__( 'Campaign Impact', 'yiari-campaign-toolkit' ),
			'manage_woocommerce',
			'ykt-impact-broadcast',
			array( $this, 'render_impact_page' )
		);
	}

	/**
	 * Render the broadcast form.
	 */
	public function render_impact_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'yiari-campaign-toolkit' ) );
		}

		$counts = $this->segment_counts();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Campaign Impact Broadcast', 'yiari-campaign-toolkit' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ykt_send_impact_update">
				<?php wp_nonce_field( 'ykt_send_impact_update' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Segments', 'yiari-campaign-toolkit' ); ?></th>
						<td>
							<?php foreach ( $this->available_segments() as $segment => $label ) : ?>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="segments[]" value="<?php echo esc_attr( $segment ); ?>">
									<?php echo esc_html( $label ); ?> <span class="description">(<?php echo esc_html( number_format_i18n( $counts[ $segment ] ?? 0 ) ); ?>)</span>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="impact_message"><?php esc_html_e( 'Impact update message', 'yiari-campaign-toolkit' ); ?></label></th>
						<td><textarea id="impact_message" name="impact_message" class="large-text" rows="8" required></textarea><p class="description"><?php esc_html_e( 'This message is appended to the impact email for each selected order.', 'yiari-campaign-toolkit' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Send Impact Update', 'yiari-campaign-toolkit' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Send impact update to selected segments.
	 */
	public function handle_impact_broadcast(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'yiari-campaign-toolkit' ) );
		}

		check_admin_referer( 'ykt_send_impact_update' );

		$segments = isset( $_POST['segments'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['segments'] ) ) : array();
		$segments = array_values( array_intersect( $segments, array_keys( $this->available_segments() ) ) );
		$message = isset( $_POST['impact_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['impact_message'] ) ) : '';

		if ( empty( $segments ) || '' === $message ) {
			wp_safe_redirect( add_query_arg( 'ykt_impact_error', 1, wp_get_referer() ?: admin_url( 'admin.php?page=ykt-impact-broadcast' ) ) );
			exit;
		}

		$orders = $this->orders_for_segments( $segments );
		$sent = 0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || 'impact-sent' === $order->get_status() ) {
				continue;
			}

			$order->update_meta_data( self::META_IMPACT_MESSAGE, $message );
			$order->save();
			$order->update_status( 'impact-sent', __( 'Campaign impact update sent by admin broadcast.', 'yiari-campaign-toolkit' ) );
			++$sent;
		}

		wp_safe_redirect( add_query_arg( 'ykt_impact_sent', $sent, admin_url( 'admin.php?page=ykt-impact-broadcast' ) ) );
		exit;
	}

	/**
	 * Render broadcast result notices.
	 */
	public function render_broadcast_notice(): void {
		if ( isset( $_GET['ykt_impact_sent'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %d: sent count. */
				esc_html__( 'Impact update sent for %d campaign order(s).', 'yiari-campaign-toolkit' ),
				absint( $_GET['ykt_impact_sent'] )
			);
			echo '</p></div>';
		}

		if ( isset( $_GET['ykt_impact_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Select at least one segment and enter an impact update message.', 'yiari-campaign-toolkit' ) . '</p></div>';
		}
	}

	/**
	 * Compute and save donor segments for an order.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function save_segments_for_order( WC_Order $order ): void {
		$segments = array();
		$package = strtoupper( (string) $order->get_meta( self::META_PACKAGE_TYPE, true ) );
		if ( 'A' === $package ) {
			$segments[] = 'paket_a';
		} elseif ( 'B' === $package ) {
			$segments[] = 'paket_b';
		} elseif ( 'MIXED' === $package ) {
			$segments[] = 'paket_a';
			$segments[] = 'paket_b';
		}

		if ( (float) $order->get_total() >= self::big_donor_threshold() || $this->campaign_quantity( $order ) >= self::big_donor_quantity_threshold() ) {
			$segments[] = 'donatur_besar';
		}

		if ( (int) $order->get_meta( self::META_MITRA_MANUAL, true ) ) {
			$segments[] = 'mitra';
		}

		$segments = array_values( array_unique( array_filter( $segments ) ) );
		$order->update_meta_data( self::META_DONOR_SEGMENT, implode( ',', $segments ) );
		$order->save();
	}

	/**
	 * Available segment labels.
	 *
	 * @return array<string, string>
	 */
	private function available_segments(): array {
		return array(
			'paket_a'       => __( 'Paket A', 'yiari-campaign-toolkit' ),
			'paket_b'       => __( 'Paket B', 'yiari-campaign-toolkit' ),
			'donatur_besar' => __( 'Donatur Besar', 'yiari-campaign-toolkit' ),
			'mitra'         => __( 'Mitra', 'yiari-campaign-toolkit' ),
		);
	}

	/**
	 * Count orders per segment for the broadcast UI.
	 *
	 * @return array<string, int>
	 */
	private function segment_counts(): array {
		$counts = array_fill_keys( array_keys( $this->available_segments() ), 0 );
		foreach ( $this->orders_for_segments( array_keys( $counts ) ) as $order ) {
			foreach ( $this->segments_for_order( $order ) as $segment ) {
				if ( isset( $counts[ $segment ] ) ) {
					++$counts[ $segment ];
				}
			}
		}

		return $counts;
	}

	/**
	 * Find paid-or-later orders belonging to any selected segment.
	 *
	 * @param array<int, string> $segments Segments.
	 * @return array<int, WC_Order>
	 */
	private function orders_for_segments( array $segments ): array {
		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'objects',
				'status'     => array( 'paid', 'certificate-sent', 'ready-to-ship', 'shipped', 'delivered' ),
				'meta_query' => array(
					array(
						'key'     => self::META_DONOR_SEGMENT,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return array_values(
			array_filter(
				$orders,
				function ( $order ) use ( $segments ) {
					return $order instanceof WC_Order && array_intersect( $segments, $this->segments_for_order( $order ) );
				}
			)
		);
	}

	/**
	 * Read stored segments.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<int, string>
	 */
	private function segments_for_order( WC_Order $order ): array {
		$segments = $order->get_meta( self::META_DONOR_SEGMENT, true );
		return is_array( $segments ) ? array_map( 'sanitize_key', $segments ) : array_filter( array_map( 'sanitize_key', explode( ',', (string) $segments ) ) );
	}

	/**
	 * Count campaign package quantity for an order.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function campaign_quantity( WC_Order $order ): int {
		$total = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product && $item->get_meta( self::META_PACKAGE_TYPE, true ) ) {
				$total += (int) $item->get_quantity();
			}
		}

		return $total;
	}
}
