<?php
/**
 * Public campaign progress counter.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides cached campaign progress data and the [campaign_progress] shortcode.
 */
class YKT_Progress_Counter {
	private const META_PACKAGE_TYPE = '_campaign_package_type';
	private const TRANSIENT_KEY = 'ykt_campaign_progress_stats';
	private const CACHE_TTL = 3 * MINUTE_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_shortcode( 'campaign_progress', array( $this, 'render_shortcode' ) );
		add_shortcode( 'ykt_book_counter', array( $this, 'render_book_counter_shortcode' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'invalidate_cache_on_status_change' ), 10, 4 );
		add_action( 'wp_ajax_ykt_campaign_progress', array( $this, 'ajax_progress' ) );
		add_action( 'wp_ajax_nopriv_ykt_campaign_progress', array( $this, 'ajax_progress' ) );
	}

	/**
	 * Clear cached stats whenever a campaign order changes status.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from Previous status.
	 * @param string   $to New status.
	 * @param WC_Order $order Order object.
	 */
	public function invalidate_cache_on_status_change( int $order_id, string $from, string $to, $order ): void {
		unset( $order_id, $from, $to );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( class_exists( 'YKT_Checkout' ) && YKT_Checkout::order_has_campaign_package( $order ) ) {
			delete_transient( self::TRANSIENT_KEY );
		}
	}

	/**
	 * AJAX endpoint for page-cache-friendly progress refreshes.
	 */
	public function ajax_progress(): void {
		$target = isset( $_GET['target'] ) ? absint( wp_unslash( $_GET['target'] ) ) : 0;
		wp_send_json_success( $this->progress_payload( $target ) );
	}

	/**
	 * Render the public shortcode.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 */
	public function render_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'target' => 0,
			),
			$atts,
			'campaign_progress'
		);

		$target = absint( $atts['target'] );
		$data   = $this->progress_payload( $target );

		$this->enqueue_progress_script();

		return sprintf(
			'<div class="ykt-progress" data-target="%1$d"><div class="ykt-progress__stats"><span><strong data-ykt-books>%2$s</strong> %3$s</span><span><strong data-ykt-donors>%4$s</strong> %5$s</span><span><strong data-ykt-percent>%6$s%%</strong></span></div><div class="ykt-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%7$d"><span data-ykt-bar style="width:%8$d%%"></span></div></div>',
			$target,
			esc_html( number_format_i18n( (int) $data['books_funded'] ) ),
			esc_html__( 'books funded', 'yiari-campaign-toolkit' ),
			esc_html( number_format_i18n( (int) $data['donor_count'] ) ),
			esc_html__( 'donors', 'yiari-campaign-toolkit' ),
			esc_html( (string) $data['percentage'] ),
			absint( $data['percentage'] ),
			absint( $data['percentage'] )
		);
	}

	/**
	 * Render a concise Indonesian book counter for Oxygen/page builder placement.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 */
	public function render_book_counter_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'target' => 1000,
			),
			$atts,
			'ykt_book_counter'
		);

		$target = max( 1, absint( $atts['target'] ) );
		$data   = $this->progress_payload( $target );

		$this->enqueue_progress_script();

		return sprintf(
			'<div class="ykt-book-counter" data-target="%1$d"><strong data-ykt-books>%2$s</strong> <span>%3$s</span> <strong data-ykt-target>%4$s</strong> <span>%5$s</span></div>',
			$target,
			esc_html( number_format_i18n( (int) $data['books_funded'] ) ),
			esc_html__( 'dari', 'yiari-campaign-toolkit' ),
			esc_html( number_format_i18n( $target ) ),
			esc_html__( 'buku telah terkumpul', 'yiari-campaign-toolkit' )
		);
	}

	/**
	 * Build the public progress payload.
	 *
	 * @param int $target Target books funded.
	 * @return array{books_funded:int,donor_count:int,target:int,percentage:int}
	 */
	private function progress_payload( int $target ): array {
		$stats = $this->get_stats();
		$percentage = $target > 0 ? min( 100, (int) floor( ( $stats['books_funded'] / $target ) * 100 ) ) : 0;

		return array(
			'books_funded' => (int) $stats['books_funded'],
			'donor_count'  => (int) $stats['donor_count'],
			'target'       => $target,
			'percentage'   => $percentage,
		);
	}

	/**
	 * Load progress refresh script once for progress/counter shortcodes.
	 */
	private function enqueue_progress_script(): void {
		wp_enqueue_script( 'ykt-progress', YKT_PLUGIN_URL . 'assets/progress.js', array(), YKT_VERSION, true );
		wp_localize_script(
			'ykt-progress',
			'yktProgress',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Get cached campaign stats.
	 *
	 * @return array{books_funded:int,donor_count:int}
	 */
	private function get_stats(): array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && isset( $cached['books_funded'], $cached['donor_count'] ) ) {
			return array(
				'books_funded' => (int) $cached['books_funded'],
				'donor_count'  => (int) $cached['donor_count'],
			);
		}

		$stats = $this->calculate_stats();
		set_transient( self::TRANSIENT_KEY, $stats, self::CACHE_TTL );

		return $stats;
	}

	/**
	 * Calculate campaign totals from paid-or-later campaign orders.
	 *
	 * @return array{books_funded:int,donor_count:int}
	 */
	private function calculate_stats(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'books_funded' => 0,
				'donor_count'  => 0,
			);
		}

		$order_ids = wc_get_orders(
			array(
				'limit'        => -1,
				'return'       => 'ids',
				'status'       => $this->counted_statuses(),
				'meta_query'   => array(
					array(
						'key'     => self::META_PACKAGE_TYPE,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$books_funded = 0;
		$donors = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$books_funded += $this->donated_books_for_order( $order );
			$donor_key = strtolower( trim( $order->get_billing_email() ) );
			if ( '' === $donor_key ) {
				$donor_key = 'order-' . $order->get_id();
			}
			$donors[ $donor_key ] = true;
		}

		return array(
			'books_funded' => $books_funded,
			'donor_count'  => count( $donors ),
		);
	}

	/**
	 * Count donated books for one order from immutable line-item package meta.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function donated_books_for_order( WC_Order $order ): int {
		$total = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$line_type = strtoupper( (string) $item->get_meta( self::META_PACKAGE_TYPE, true ) );
			if ( in_array( $line_type, array( 'A', 'B' ), true ) ) {
				$total += (int) $item->get_quantity();
			}
		}

		return $total;
	}

	/**
	 * Campaign statuses that count toward public totals.
	 *
	 * @return array<int, string>
	 */
	private function counted_statuses(): array {
		return array(
			'paid',
			'certificate-sent',
			'ready-to-ship',
			'shipped',
			'delivered',
			'impact-sent',
		);
	}
}
