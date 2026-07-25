<?php
/**
 * WooCommerce admin order extensions for campaign operations.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends WooCommerce order admin screens with campaign data and actions.
 */
class YKT_Admin {
	private const META_PACKAGE_TYPE = '_campaign_package_type';
	private const META_CERTIFICATE_NUMBER = '_certificate_number';
	private const META_AWB_NUMBER = '_shipping_awb_number';
	private const META_KIRIMINAJA_STATUS = '_kiriminaja_status';
	private const META_INTERNAL_NOTE = '_internal_note';
	private const META_DONOR_SEGMENT = '_donor_segment';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_legacy_columns' ), 30 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_column' ), 30, 2 );
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_hpos_columns' ), 30 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_hpos_column' ), 30, 2 );

		add_action( 'restrict_manage_posts', array( $this, 'render_legacy_package_filter' ) );
		add_filter( 'parse_query', array( $this, 'filter_legacy_orders_by_package' ) );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_hpos_package_filter' ), 10, 2 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_hpos_orders_by_package' ) );

		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'add_order_search_fields' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_legacy_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_legacy_bulk_actions' ), 10, 3 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'register_hpos_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_hpos_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'render_bulk_action_notice' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_status_history_meta_box' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_internal_note_field' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_internal_note_from_order_screen' ), 20, 1 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_ykt_save_internal_note', array( $this, 'ajax_save_internal_note' ) );
		add_action( 'admin_post_ykt_export_campaign_orders', array( $this, 'export_campaign_orders' ) );
	}

	/**
	 * Add campaign columns to legacy orders list.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_legacy_columns( array $columns ): array {
		return $this->insert_campaign_columns( $columns );
	}

	/**
	 * Add campaign columns to HPOS orders list.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_hpos_columns( array $columns ): array {
		return $this->insert_campaign_columns( $columns );
	}

	/**
	 * Render one legacy order column.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Order ID.
	 */
	public function render_legacy_column( string $column, int $post_id ): void {
		$order = wc_get_order( $post_id );
		if ( $order instanceof WC_Order ) {
			$this->render_column( $column, $order );
		}
	}

	/**
	 * Render one HPOS order column.
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order Order object.
	 */
	public function render_hpos_column( string $column, $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->render_column( $column, $order );
		}
	}

	/**
	 * Render Paket filter for legacy orders.
	 */
	public function render_legacy_package_filter(): void {
		global $typenow;

		if ( 'shop_order' !== $typenow || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->render_package_filter_select();
		$this->render_export_button();
	}

	/**
	 * Render Paket filter for HPOS orders.
	 *
	 * @param string $order_type Order type.
	 * @param string $which Position.
	 */
	public function render_hpos_package_filter( string $order_type, string $which ): void {
		unset( $which );

		if ( 'shop_order' !== $order_type || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->render_package_filter_select();
		$this->render_export_button();
	}

	/**
	 * Filter legacy order query by Paket type.
	 *
	 * @param WP_Query $query Query object.
	 */
	public function filter_legacy_orders_by_package( $query ) {
		global $pagenow;

		if ( ! is_admin() || 'edit.php' !== $pagenow || 'shop_order' !== ( $query->query['post_type'] ?? '' ) ) {
			return $query;
		}

		$package = $this->requested_package_filter();
		if ( '' === $package ) {
			return $query;
		}

		$query->set( 'meta_key', self::META_PACKAGE_TYPE );
		$query->set( 'meta_value', $package );

		return $query;
	}

	/**
	 * Filter HPOS order query by Paket type.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public function filter_hpos_orders_by_package( array $args ): array {
		$package = $this->requested_package_filter();
		if ( '' === $package ) {
			return $args;
		}

		if ( empty( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = array();
		}

		$args['meta_query'][] = array(
			'key'   => self::META_PACKAGE_TYPE,
			'value' => $package,
		);

		return $args;
	}

	/**
	 * Search by certificate, AWB, internal note, and donor segment meta.
	 *
	 * @param array<int, string> $fields Existing search fields.
	 * @return array<int, string>
	 */
	public function add_order_search_fields( array $fields ): array {
		$fields[] = self::META_CERTIFICATE_NUMBER;
		$fields[] = self::META_AWB_NUMBER;
		$fields[] = self::META_INTERNAL_NOTE;
		$fields[] = self::META_DONOR_SEGMENT;

		return array_values( array_unique( $fields ) );
	}

	/**
	 * Register legacy bulk actions.
	 *
	 * @param array<string, string> $actions Existing actions.
	 * @return array<string, string>
	 */
	public function register_legacy_bulk_actions( array $actions ): array {
		$actions['ykt_resend_current_email'] = __( 'Kirim Ulang Email Campaign', 'yiari-campaign-toolkit' );
		$actions['ykt_export_campaign_csv']  = __( 'Export Campaign CSV', 'yiari-campaign-toolkit' );

		return $actions;
	}

	/**
	 * Register HPOS bulk actions.
	 *
	 * @param array<string, string> $actions Existing actions.
	 * @return array<string, string>
	 */
	public function register_hpos_bulk_actions( array $actions ): array {
		return $this->register_legacy_bulk_actions( $actions );
	}

	/**
	 * Handle legacy bulk actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action Action key.
	 * @param array<int, int> $post_ids Selected order IDs.
	 */
	public function handle_legacy_bulk_actions( string $redirect_to, string $action, array $post_ids ): string {
		return $this->handle_bulk_actions( $redirect_to, $action, $post_ids );
	}

	/**
	 * Handle HPOS bulk actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action Action key.
	 * @param array<int, int> $order_ids Selected order IDs.
	 */
	public function handle_hpos_bulk_actions( string $redirect_to, string $action, array $order_ids ): string {
		return $this->handle_bulk_actions( $redirect_to, $action, $order_ids );
	}

	/**
	 * Render bulk action result notices.
	 */
	public function render_bulk_action_notice(): void {
		if ( empty( $_GET['ykt_resent'] ) ) {
			return;
		}

		$count = absint( $_GET['ykt_resent'] );
		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: %d: email count. */
			esc_html__( 'Campaign emails resent for %d order(s).', 'yiari-campaign-toolkit' ),
			$count
		);
		echo '</p></div>';
	}

	/**
	 * Add status history side meta box to order screens.
	 */
	public function add_status_history_meta_box(): void {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box(
				'ykt_status_history',
				__( 'Campaign Status History', 'yiari-campaign-toolkit' ),
				array( $this, 'render_status_history_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render status history side panel.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post/order object.
	 */
	public function render_status_history_meta_box( $post_or_order ): void {
		$order_id = $post_or_order instanceof WC_Order ? $post_or_order->get_id() : absint( $post_or_order->ID ?? 0 );
		$rows = $this->status_history_rows( $order_id );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No campaign status history yet.', 'yiari-campaign-toolkit' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row->changed_at ) . '<br><strong>' . esc_html( $row->status_from ?: '-' ) . ' &rarr; ' . esc_html( $row->status_to ) . '</strong><br><small>' . esc_html( $row->actor ) . '</small></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render admin-only internal note field on order edit screen.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function render_internal_note_field( $order ): void {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		woocommerce_wp_textarea_input(
			array(
				'id'          => self::META_INTERNAL_NOTE,
				'label'       => __( 'Campaign Internal Note', 'yiari-campaign-toolkit' ),
				'value'       => (string) $order->get_meta( self::META_INTERNAL_NOTE, true ),
				'description' => __( 'Admin-only campaign note. This is separate from WooCommerce order notes.', 'yiari-campaign-toolkit' ),
			)
		);
	}

	/**
	 * Save internal note from the order edit screen.
	 *
	 * @param int $order_id Order ID.
	 */
	public function save_internal_note_from_order_screen( int $order_id ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! isset( $_POST[ self::META_INTERNAL_NOTE ] ) ) {
			return;
		}

		$order->update_meta_data( self::META_INTERNAL_NOTE, sanitize_textarea_field( wp_unslash( $_POST[ self::META_INTERNAL_NOTE ] ) ) );
		$order->save();
	}

	/**
	 * Enqueue small admin JS for inline internal note saves.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'edit.php' ) && false === strpos( $hook_suffix, 'wc-orders' ) ) {
			return;
		}

		wp_enqueue_script( 'ykt-admin', YKT_PLUGIN_URL . 'assets/admin.js', array(), YKT_VERSION, true );
		wp_localize_script(
			'ykt-admin',
			'yktAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ykt_admin' ),
			)
		);
	}

	/**
	 * Save an inline internal note from the orders list.
	 */
	public function ajax_save_internal_note(): void {
		check_ajax_referer( 'ykt_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => 'Order not found' ), 404 );
		}

		$order->update_meta_data( self::META_INTERNAL_NOTE, $note );
		$order->save();

		wp_send_json_success();
	}

	/**
	 * Export filtered campaign orders as CSV.
	 */
	public function export_campaign_orders(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'yiari-campaign-toolkit' ) );
		}

		check_admin_referer( 'ykt_export_campaign_orders' );

		$package = $this->requested_package_filter();
		$order_ids = $this->requested_export_order_ids();
		$args = array(
			'limit'        => -1,
			'return'       => 'objects',
			'status'       => array_keys( wc_get_order_statuses() ),
			'meta_query'   => array(
				array(
					'key'     => self::META_PACKAGE_TYPE,
					'compare' => 'EXISTS',
				),
			),
		);

		if ( $package ) {
			$args['meta_query'][] = array(
				'key'   => self::META_PACKAGE_TYPE,
				'value' => $package,
			);
		}

		if ( ! empty( $order_ids ) ) {
			$args['include'] = $order_ids;
		}

		$orders = wc_get_orders( $args );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=yiari-campaign-orders-' . gmdate( 'Ymd-His' ) . '.csv' );
		echo "\xEF\xBB\xBF";

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'Order ID', 'Date', 'Name', 'Contact', 'Package', 'Quantity', 'Payment Status', 'Certificate', 'Shipping Status', 'AWB', 'Impact Status', 'Segments', 'Internal Note' ) );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			fputcsv(
				$output,
				array(
					$order->get_id(),
					$order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i:s' ) : '',
					$order->get_formatted_billing_full_name(),
					trim( $order->get_billing_email() . ' ' . $order->get_billing_phone() ),
					$order->get_meta( self::META_PACKAGE_TYPE, true ),
					$this->campaign_quantity( $order ),
					$order->get_status(),
					$order->get_meta( self::META_CERTIFICATE_NUMBER, true ) ? 'generated' : 'missing',
					$order->get_meta( self::META_KIRIMINAJA_STATUS, true ),
					$order->get_meta( self::META_AWB_NUMBER, true ),
					'impact-sent' === $order->get_status() ? 'sent' : 'pending',
					implode( ',', $this->segments_for_order( $order ) ),
					$order->get_meta( self::META_INTERNAL_NOTE, true ),
				)
			);
		}

		exit;
	}

	/**
	 * Insert campaign columns near order status data.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	private function insert_campaign_columns( array $columns ): array {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new_columns['ykt_package'] = __( 'Paket', 'yiari-campaign-toolkit' );
				$new_columns['ykt_awb'] = __( 'AWB/Resi', 'yiari-campaign-toolkit' );
				$new_columns['ykt_certificate'] = __( 'Certificate', 'yiari-campaign-toolkit' );
				$new_columns['ykt_impact'] = __( 'Impact', 'yiari-campaign-toolkit' );
				$new_columns['ykt_internal_note'] = __( 'Internal Note', 'yiari-campaign-toolkit' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render a campaign column for an order.
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order Order object.
	 */
	private function render_column( string $column, WC_Order $order ): void {
		switch ( $column ) {
			case 'ykt_package':
				echo esc_html( $order->get_meta( self::META_PACKAGE_TYPE, true ) ?: '-' );
				break;
			case 'ykt_awb':
				echo esc_html( $order->get_meta( self::META_AWB_NUMBER, true ) ?: '-' );
				break;
			case 'ykt_certificate':
				$certificate = (string) $order->get_meta( self::META_CERTIFICATE_NUMBER, true );
				echo $certificate ? esc_html( $certificate ) : '&mdash;';
				break;
			case 'ykt_impact':
				echo 'impact-sent' === $order->get_status() ? esc_html__( 'Sent', 'yiari-campaign-toolkit' ) : esc_html__( 'Pending', 'yiari-campaign-toolkit' );
				break;
			case 'ykt_internal_note':
				$this->render_inline_note_control( $order );
				break;
		}
	}

	/**
	 * Render inline note textarea in orders list.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function render_inline_note_control( WC_Order $order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			echo esc_html( $order->get_meta( self::META_INTERNAL_NOTE, true ) ?: '-' );
			return;
		}

		printf(
			'<textarea class="ykt-internal-note" data-order-id="%1$d" rows="2">%2$s</textarea><button type="button" class="button ykt-save-note">%3$s</button>',
			absint( $order->get_id() ),
			esc_textarea( (string) $order->get_meta( self::META_INTERNAL_NOTE, true ) ),
			esc_html__( 'Save', 'yiari-campaign-toolkit' )
		);
	}

	/**
	 * Render Paket dropdown.
	 */
	private function render_package_filter_select(): void {
		$current = $this->requested_package_filter();
		echo '<select name="ykt_package_filter" id="ykt_package_filter">';
		echo '<option value="">' . esc_html__( 'All campaign packages', 'yiari-campaign-toolkit' ) . '</option>';
		foreach ( array( 'A', 'B', 'MIXED' ) as $package ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $package ), selected( $current, $package, false ), esc_html( 'Paket ' . $package ) );
		}
		echo '</select>';
	}

	/**
	 * Render CSV export button beside order filters.
	 */
	private function render_export_button(): void {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'             => 'ykt_export_campaign_orders',
					'ykt_package_filter' => $this->requested_package_filter(),
				),
				admin_url( 'admin-post.php' )
			),
			'ykt_export_campaign_orders'
		);

		echo ' <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export Campaign CSV', 'yiari-campaign-toolkit' ) . '</a>';
	}

	/**
	 * Return requested package filter.
	 */
	private function requested_package_filter(): string {
		$package = isset( $_GET['ykt_package_filter'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['ykt_package_filter'] ) ) ) : '';

		return in_array( $package, array( 'A', 'B', 'MIXED' ), true ) ? $package : '';
	}

	/**
	 * Handle shared bulk actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action Action key.
	 * @param array<int, int> $order_ids Selected order IDs.
	 */
	private function handle_bulk_actions( string $redirect_to, string $action, array $order_ids ): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		if ( 'ykt_export_campaign_csv' === $action ) {
			$order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
			$args = array( 'action' => 'ykt_export_campaign_orders' );
			if ( ! empty( $order_ids ) ) {
				$args['ykt_order_ids'] = implode( ',', $order_ids );
			}

			return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'ykt_export_campaign_orders' );
		}

		if ( 'ykt_resend_current_email' !== $action ) {
			return $redirect_to;
		}

		$count = 0;
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && $this->resend_current_campaign_email( $order ) ) {
				++$count;
			}
		}

		return add_query_arg( 'ykt_resent', $count, $redirect_to );
	}

	/**
	 * Resend the email associated with the current campaign status.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function resend_current_campaign_email( WC_Order $order ): bool {
		if ( ! class_exists( 'YKT_Emails' ) ) {
			require_once YKT_PLUGIN_DIR . 'includes/class-ykt-emails.php';
		}

		$mailer = WC()->mailer();
		$emails = $mailer ? $mailer->get_emails() : array();
		$map = array(
			'paid'        => 'YKT_Email_Campaign_Paid',
			'shipped'     => 'YKT_Email_Campaign_Shipped',
			'delivered'   => 'YKT_Email_Campaign_Delivered',
			'impact-sent' => 'YKT_Email_Campaign_Impact',
		);

		$email_key = $map[ $order->get_status() ] ?? '';
		if ( ! $email_key || empty( $emails[ $email_key ] ) || ! method_exists( $emails[ $email_key ], 'trigger' ) ) {
			return false;
		}

		$emails[ $email_key ]->trigger( $order->get_id(), $order );
		$order->add_order_note( __( 'Campaign email resent by admin bulk action.', 'yiari-campaign-toolkit' ) );
		return true;
	}

	/**
	 * Read campaign status history rows.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, object>
	 */
	private function status_history_rows( int $order_id ): array {
		global $wpdb;

		$table = YKT_Order_Status::log_table_name();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status_from, status_to, changed_at, actor FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 12",
				$order_id
			)
		) ?: array();
	}

	/**
	 * Count campaign package quantity.
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

	/**
	 * Read stored donor segments.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<int, string>
	 */
	private function segments_for_order( WC_Order $order ): array {
		$segments = $order->get_meta( self::META_DONOR_SEGMENT, true );
		return is_array( $segments ) ? array_map( 'sanitize_key', $segments ) : array_filter( array_map( 'sanitize_key', explode( ',', (string) $segments ) ) );
	}

	/**
	 * Return selected order IDs requested by CSV export.
	 *
	 * @return array<int, int>
	 */
	private function requested_export_order_ids(): array {
		if ( empty( $_GET['ykt_order_ids'] ) ) {
			return array();
		}

		$raw_ids = explode( ',', sanitize_text_field( wp_unslash( $_GET['ykt_order_ids'] ) ) );
		return array_values( array_filter( array_map( 'absint', $raw_ids ) ) );
	}
}
