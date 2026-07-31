<?php
/**
 * Checkout differentiation for Paket A and Paket B.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles product package metadata and checkout behavior.
 */
class YKT_Checkout {
	private const META_PACKAGE_TYPE = '_campaign_package_type';
	private const META_DONOR_REASON = '_donor_reason';
	private const META_CONSENT_UPDATES = '_consent_updates';
	private const META_CONSENT_YIARI_INFO = '_consent_yiari_info';
	private const META_CONSENT_TESTIMONIAL = '_consent_testimonial';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_package_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_package_field' ) );
		add_filter( 'woocommerce_cart_needs_shipping', array( $this, 'maybe_disable_shipping_for_package_a' ), 20 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'enqueue_checkout_assets' ) );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'adjust_checkout_fields' ), 99 );
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_campaign_fields' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_meta' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_line_item_package_meta' ), 20, 4 );
	}

	/**
	 * Get the package type for a product.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function get_product_package_type( int $product_id ): string {
		$type = get_post_meta( $product_id, self::META_PACKAGE_TYPE, true );
		$type = strtoupper( sanitize_text_field( (string) $type ) );

		return in_array( $type, array( 'A', 'B' ), true ) ? $type : '';
	}

	/**
	 * Check whether an order contains a campaign package.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function order_has_campaign_package( WC_Order $order ): bool {
		$type = strtoupper( (string) $order->get_meta( self::META_PACKAGE_TYPE, true ) );
		if ( in_array( $type, array( 'A', 'B', 'MIXED' ), true ) ) {
			return true;
		}

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_variation_id() ?: $item->get_product_id();
			if ( self::get_product_package_type( (int) $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the Paket A/B selector in the product data panel.
	 */
	public function render_product_package_field(): void {
		woocommerce_wp_select(
			array(
				'id'          => self::META_PACKAGE_TYPE,
				'label'       => __( 'Campaign Package', 'yiari-campaign-toolkit' ),
				'description' => __( 'Use Paket A for donation-only and Paket B for buy-1-donate-1 shipping orders.', 'yiari-campaign-toolkit' ),
				'desc_tip'    => true,
				'options'     => array(
					''  => __( 'Not a campaign package', 'yiari-campaign-toolkit' ),
					'A' => __( 'Paket A - Traktir Buku', 'yiari-campaign-toolkit' ),
					'B' => __( 'Paket B - Beli 1, Traktir 1', 'yiari-campaign-toolkit' ),
				),
			)
		);
	}

	/**
	 * Save the product package selector.
	 *
	 * @param WC_Product $product Product object.
	 */
	public function save_product_package_field( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$type = isset( $_POST[ self::META_PACKAGE_TYPE ] )
			? strtoupper( sanitize_text_field( wp_unslash( $_POST[ self::META_PACKAGE_TYPE ] ) ) )
			: '';

		if ( ! in_array( $type, array( 'A', 'B' ), true ) ) {
			$type = '';
		}

		$product->update_meta_data( self::META_PACKAGE_TYPE, $type );
	}

	/**
	 * Disable shipping only when the cart contains Paket A and no Paket B.
	 *
	 * @param bool $needs_shipping Existing WooCommerce shipping decision.
	 */
	public function maybe_disable_shipping_for_package_a( bool $needs_shipping ): bool {
		$cart_type = $this->get_cart_package_type();
		if ( 'A' === $cart_type ) {
			return false;
		}

		return $needs_shipping;
	}

	/**
	 * Enqueue checkout UX helpers.
	 */
	public function enqueue_checkout_assets(): void {
		if ( 'A' !== $this->get_cart_package_type() ) {
			return;
		}

		wp_enqueue_script(
			'ykt-checkout',
			YKT_PLUGIN_URL . 'assets/checkout.js',
			array(),
			YKT_VERSION,
			true
		);
	}

	/**
	 * Adjust checkout address requirements for package type.
	 *
	 * @param array<string, mixed> $fields Checkout fields.
	 * @return array<string, mixed>
	 */
	public function adjust_checkout_fields( array $fields ): array {
		$cart_type = $this->get_cart_package_type();
		$address_fields = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

		if ( 'A' === $cart_type ) {
			foreach ( array( 'billing', 'shipping' ) as $group ) {
				foreach ( $address_fields as $field ) {
					$key = $group . '_' . $field;
					if ( isset( $fields[ $group ][ $key ] ) ) {
						$fields[ $group ][ $key ]['required'] = false;
						$fields[ $group ][ $key ]['class'][]  = 'ykt-package-a-hidden-address';
					}
				}
			}

			return $fields;
		}

		if ( in_array( $cart_type, array( 'B', 'MIXED' ), true ) ) {
			$required_billing_fields = array( 'first_name', 'phone', 'address_1', 'city', 'postcode', 'country' );
			foreach ( $required_billing_fields as $field ) {
				$key = 'billing_' . $field;
				if ( isset( $fields['billing'][ $key ] ) ) {
					$fields['billing'][ $key ]['required'] = true;
				}
			}

			if ( isset( $fields['billing']['kiriof_destination_area'] ) ) {
				$fields['billing']['kiriof_destination_area']['required'] = true;
			}
		}

		return $fields;
	}

	/**
	 * Render campaign-specific donor fields.
	 *
	 * @param WC_Checkout $checkout Checkout object.
	 */
	public function render_campaign_fields( $checkout ): void {
		if ( ! $this->cart_has_campaign_package() ) {
			return;
		}

		echo '<div id="ykt-campaign-fields">';
		echo '<h3>' . esc_html__( 'Informasi Kampanye', 'yiari-campaign-toolkit' ) . '</h3>';

		$reason_choices = $this->donor_reason_choices();
		$selected_reason = $checkout->get_value( 'donor_reason_choice' );
		if ( ! $selected_reason ) {
			$selected_reason = $this->matching_donor_reason_choice( (string) $checkout->get_value( 'donor_reason' ) );
		}

		woocommerce_form_field(
			'donor_reason_choice',
			array(
				'type'     => 'radio',
				'class'    => array( 'form-row-wide', 'ykt-donor-reason-choice' ),
				'label'    => __( 'Apa alasan Anda ingin mendukung buku ini?', 'yiari-campaign-toolkit' ),
				'options'  => $reason_choices,
				'required' => false,
			),
			$selected_reason
		);

		woocommerce_form_field(
			'donor_reason_other',
			array(
				'type'        => 'text',
				'class'       => array( 'form-row-wide', 'ykt-donor-reason-other' ),
				'label'       => __( 'Alasan lainnya', 'yiari-campaign-toolkit' ),
				'placeholder' => __( 'Tulis alasan Anda', 'yiari-campaign-toolkit' ),
				'required'    => false,
			),
			$checkout->get_value( 'donor_reason_other' )
		);

		$this->render_donor_reason_toggle_script();

		$this->render_checkbox( 'consent_updates', __( 'Saya bersedia menerima kabar terbaru tentang kampanye ini.', 'yiari-campaign-toolkit' ), $checkout );
		$this->render_checkbox( 'consent_yiari_info', __( 'Saya bersedia menerima informasi tentang program YIARI.', 'yiari-campaign-toolkit' ), $checkout );
		$this->render_checkbox( 'consent_testimonial', __( 'YIARI dapat menghubungi saya untuk testimoni tentang kampanye ini.', 'yiari-campaign-toolkit' ), $checkout );

		echo '</div>';
	}

	/**
	 * Validate Paket B address requirements server-side.
	 */
	public function validate_checkout(): void {
		$cart_type = $this->get_cart_package_type();
		if ( ! in_array( $cart_type, array( 'B', 'MIXED' ), true ) ) {
			return;
		}

		$checkout_fields = WC()->checkout()->get_checkout_fields();
		$required_fields = array(
			'billing_first_name' => __( 'First name', 'yiari-campaign-toolkit' ),
			'billing_phone'      => __( 'Phone', 'yiari-campaign-toolkit' ),
			'billing_address_1'  => __( 'Address', 'yiari-campaign-toolkit' ),
		);

		// KiriminAja replaces city/postcode with its district selector on Indonesian checkout.
		foreach ( array( 'billing_city' => __( 'City', 'yiari-campaign-toolkit' ), 'billing_postcode' => __( 'Postcode', 'yiari-campaign-toolkit' ) ) as $key => $label ) {
			if ( isset( $checkout_fields['billing'][ $key ] ) ) {
				$required_fields[ $key ] = $label;
			}
		}

		if ( isset( $checkout_fields['billing']['kiriof_destination_area'] ) ) {
			$required_fields['kiriof_destination_area'] = __( 'District', 'yiari-campaign-toolkit' );
		}

		foreach ( $required_fields as $key => $label ) {
			$value = isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
			if ( '' === $value ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: field label. */
						__( '%s is required for Paket B shipping.', 'yiari-campaign-toolkit' ),
						$label
					),
					'error'
				);
			}
		}
	}

	/**
	 * Save package and donor campaign meta to the order.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data Checkout data.
	 */
	public function save_order_meta( $order, array $data ): void {
		unset( $data );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$cart_type = $this->get_cart_package_type();
		if ( '' === $cart_type ) {
			return;
		}

		$order->update_meta_data( self::META_PACKAGE_TYPE, $cart_type );
		$order->update_meta_data( self::META_DONOR_REASON, $this->posted_donor_reason() );
		$order->update_meta_data( self::META_CONSENT_UPDATES, $this->posted_bool( 'consent_updates' ) );
		$order->update_meta_data( self::META_CONSENT_YIARI_INFO, $this->posted_bool( 'consent_yiari_info' ) );
		$order->update_meta_data( self::META_CONSENT_TESTIMONIAL, $this->posted_bool( 'consent_testimonial' ) );

	}

	/**
	 * Copy package type onto the immutable order line item.
	 *
	 *  WC_Order_Item_Product $item Line item.
	 *  string                $cart_item_key Cart item key.
	 *  array<string, mixed>  $values Cart item values.
	 *  WC_Order              $order Order object.
	 */
	public function save_line_item_package_meta( $item, string $cart_item_key, array $values, $order ): void {
		unset( $cart_item_key, $order );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$product_id   = (int) ( $values['variation_id'] ?: $values['product_id'] );
		$package_type = self::get_product_package_type( $product_id );
		if ( $package_type ) {
			$item->update_meta_data( self::META_PACKAGE_TYPE, $package_type );
		}
	}


	/**
	 * Available donor reason choices shown on checkout.
	 *
	 * @return array<string, string>
	 */
	private function donor_reason_choices(): array {
		return array(
			'literasi_anak'    => __( 'Dukung peningkatan literasi anak', 'yiari-campaign-toolkit' ),
			'orangutan_habitat' => __( 'Peduli orangutan dan habitatnya', 'yiari-campaign-toolkit' ),
			'edukasi_konservasi' => __( 'Tertarik pada edukasi konservasi', 'yiari-campaign-toolkit' ),
			'other'            => __( 'Lainnya ...', 'yiari-campaign-toolkit' ),
		);
	}

	/**
	 * Match a saved free-text reason back to a radio option when possible.
	 *
	 * @param string $reason Saved reason text.
	 */
	private function matching_donor_reason_choice( string $reason ): string {
		$reason = trim( $reason );
		foreach ( $this->donor_reason_choices() as $key => $label ) {
			if ( 'other' !== $key && $reason === $label ) {
				return $key;
			}
		}

		return '' !== $reason ? 'other' : '';
	}

	/**
	 * Render a tiny checkout script that shows the free-text field only for Lainnya.
	 */
	private function render_donor_reason_toggle_script(): void {
		?>
		<script>
			(function() {
				function toggleYktDonorReasonOther() {
					var selected = document.querySelector('input[name="donor_reason_choice"]:checked');
					var row = document.querySelector('.ykt-donor-reason-other');
					if (!row) {
						return;
					}
					row.style.display = selected && selected.value === 'other' ? '' : 'none';
				}

				document.addEventListener('change', function(event) {
					if (event.target && event.target.name === 'donor_reason_choice') {
						toggleYktDonorReasonOther();
					}
				});

				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', toggleYktDonorReasonOther);
				} else {
					toggleYktDonorReasonOther();
				}
			})();
		</script>
		<?php
	}

	/**
	 * Render one consent checkbox.
	 *
	 * @param string      $key Field key.
	 * @param string      $label Field label.
	 * @param WC_Checkout $checkout Checkout object.
	 */
	private function render_checkbox( string $key, string $label, $checkout ): void {
		woocommerce_form_field(
			$key,
			array(
				'type'     => 'checkbox',
				'class'    => array( 'form-row-wide' ),
				'label'    => $label,
				'required' => false,
			),
			$checkout->get_value( $key )
		);
	}

	/**
	 * Determine the campaign package type in the current cart.
	 */
	private function get_cart_package_type(): string {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->cart ) || ! WC()->cart ) {
			return '';
		}

		$has_a = false;
		$has_b = false;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = (int) ( $cart_item['variation_id'] ?: $cart_item['product_id'] );
			$type       = self::get_product_package_type( $product_id );

			if ( 'A' === $type ) {
				$has_a = true;
			}
			if ( 'B' === $type ) {
				$has_b = true;
			}
		}

		if ( $has_a && $has_b ) {
			return 'MIXED';
		}
		if ( $has_b ) {
			return 'B';
		}
		if ( $has_a ) {
			return 'A';
		}

		return '';
	}

	/**
	 * Whether the current cart contains any campaign package.
	 */
	private function cart_has_campaign_package(): bool {
		return '' !== $this->get_cart_package_type();
	}

	/**
	 * Sanitize posted textarea.
	 *
	 * @param string $key Input key.
	 */
	private function posted_textarea( string $key ): string {
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}


	/**
	 * Build the donor reason text from the selected radio option.
	 */
	private function posted_donor_reason(): string {
		$choice = isset( $_POST['donor_reason_choice'] ) ? sanitize_key( wp_unslash( $_POST['donor_reason_choice'] ) ) : '';
		$choices = $this->donor_reason_choices();

		if ( 'other' === $choice ) {
			$other = $this->posted_textarea( 'donor_reason_other' );
			return '' !== $other ? $other : ( $choices['other'] ?? '' );
		}

		return $choices[ $choice ] ?? '';
	}

	/**
	 * Normalize posted checkbox to 1/0.
	 *
	 * @param string $key Input key.
	 */
	private function posted_bool( string $key ): int {
		return isset( $_POST[ $key ] ) ? 1 : 0;
	}
}
