<?php
/**
 * Public campaign landing page and cart shortcuts.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides Oxygen-friendly shortcodes for the campaign frontend.
 */
class YKT_Campaign_Frontend {
	private const SKU_PACKAGE_A = 'YKT-KG-PAKET-A';
	private const SKU_PACKAGE_B = 'YKT-KG-PAKET-B';

	/**
	 * Register public hooks and shortcodes.
	 */
	public function init(): void {
		add_shortcode( 'ykt_campaign_landing', array( $this, 'render_landing_shortcode' ) );
		add_shortcode( 'ykt_campaign_products', array( $this, 'render_products_shortcode' ) );
		add_shortcode( 'ykt_cart_icon', array( $this, 'render_cart_icon_shortcode' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_fragments' ) );
		add_action( 'wp_ajax_ykt_cart_count', array( $this, 'ajax_cart_count' ) );
		add_action( 'wp_ajax_nopriv_ykt_cart_count', array( $this, 'ajax_cart_count' ) );
	}

	/**
	 * Render the full campaign landing section.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 */
	public function render_landing_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'target'       => 1000,
				'package_a_id' => 0,
				'package_b_id' => 0,
			),
			$atts,
			'ykt_campaign_landing'
		);

		$this->enqueue_assets();

		$target = absint( $atts['target'] );
		$products = $this->campaign_products( absint( $atts['package_a_id'] ), absint( $atts['package_b_id'] ) );
		$product_b = $products['B'];
		$hero_image = $product_b instanceof WC_Product ? wp_get_attachment_image_url( $product_b->get_image_id(), 'large' ) : '';

		ob_start();
		?>
		<section class="ykt-campaign" aria-label="Campaign Buku Karmila dan Gito">
			<div class="ykt-campaign__hero">
				<div class="ykt-campaign__hero-copy">
					<p class="ykt-campaign__eyebrow"><?php echo esc_html__( 'Campaign buku anak', 'yiari-campaign-toolkit' ); ?></p>
					<h1><?php echo esc_html__( 'Petualangan Karmila & Gito, Menyelamatkan Orangutan', 'yiari-campaign-toolkit' ); ?></h1>
					<p><?php echo esc_html__( 'Bantu buku edukasi orangutan sampai ke anak-anak di sekitar habitat orangutan, sambil memilih paket dukungan yang sesuai untuk Anda.', 'yiari-campaign-toolkit' ); ?></p>
					<div class="ykt-campaign__actions">
						<a class="ykt-button ykt-button--primary" href="#ykt-campaign-products"><?php echo esc_html__( 'Pilih Paket', 'yiari-campaign-toolkit' ); ?></a>
						<a class="ykt-button ykt-button--secondary" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php echo esc_html__( 'Lihat Keranjang', 'yiari-campaign-toolkit' ); ?></a>
					</div>
				</div>
				<?php if ( $hero_image ) : ?>
					<div class="ykt-campaign__hero-media">
						<img src="<?php echo esc_url( $hero_image ); ?>" alt="<?php echo esc_attr__( 'Buku Karmila dan Gito', 'yiari-campaign-toolkit' ); ?>" loading="lazy">
					</div>
				<?php endif; ?>
			</div>

			<div class="ykt-campaign__progress">
				<?php echo do_shortcode( '[campaign_progress target="' . $target . '"]' ); ?>
			</div>

			<div class="ykt-campaign__impact" aria-label="Dampak campaign">
				<div>
					<strong><?php echo esc_html__( 'Paket A', 'yiari-campaign-toolkit' ); ?></strong>
					<span><?php echo esc_html__( 'Traktir buku untuk anak-anak. Tanpa pengiriman ke donor.', 'yiari-campaign-toolkit' ); ?></span>
				</div>
				<div>
					<strong><?php echo esc_html__( 'Paket B', 'yiari-campaign-toolkit' ); ?></strong>
					<span><?php echo esc_html__( 'Beli satu buku untuk Anda, traktir satu buku untuk anak-anak.', 'yiari-campaign-toolkit' ); ?></span>
				</div>
				<div>
					<strong><?php echo esc_html__( 'Sertifikat', 'yiari-campaign-toolkit' ); ?></strong>
					<span><?php echo esc_html__( 'Setiap dukungan campaign mendapat sertifikat digital otomatis.', 'yiari-campaign-toolkit' ); ?></span>
				</div>
			</div>

			<?php echo $this->render_products_shortcode( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render the two campaign product cards.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 */
	public function render_products_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'package_a_id' => 0,
				'package_b_id' => 0,
			),
			$atts,
			'ykt_campaign_products'
		);

		$this->enqueue_assets();

		$products = $this->campaign_products( absint( $atts['package_a_id'] ), absint( $atts['package_b_id'] ) );

		ob_start();
		?>
		<div id="ykt-campaign-products" class="ykt-products" aria-label="Pilihan paket campaign">
			<?php echo $this->render_product_card( 'A', $products['A'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $this->render_product_card( 'B', $products['B'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render a menu/header friendly cart icon.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 */
	public function render_cart_icon_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'label' => __( 'Keranjang', 'yiari-campaign-toolkit' ),
				'url'   => '',
			),
			$atts,
			'ykt_cart_icon'
		);

		$this->enqueue_assets();

		$url = '' !== $atts['url'] ? esc_url( $atts['url'] ) : wc_get_cart_url();

		return sprintf(
			'<a class="ykt-cart-icon" href="%1$s" aria-label="%2$s"><span class="ykt-cart-icon__svg" aria-hidden="true">%3$s</span><span class="ykt-cart-icon__label">%4$s</span><span class="ykt-cart-icon__count">%5$d</span></a>',
			esc_url( $url ),
			esc_attr( $atts['label'] ),
			$this->cart_svg(),
			esc_html( $atts['label'] ),
			$this->cart_count()
		);
	}

	/**
	 * Update the cart icon count after WooCommerce AJAX add-to-cart events.
	 *
	 * @param array<string, string> $fragments Existing fragments.
	 * @return array<string, string>
	 */
	public function cart_fragments( array $fragments ): array {
		$fragments['.ykt-cart-icon__count'] = '<span class="ykt-cart-icon__count">' . absint( $this->cart_count() ) . '</span>';
		return $fragments;
	}

	/**
	 * Return cart count for page-cache-friendly refreshes.
	 */
	public function ajax_cart_count(): void {
		wp_send_json_success(
			array(
				'count' => $this->cart_count(),
			)
		);
	}

	/**
	 * Load frontend styles/scripts only when a shortcode renders.
	 */
	private function enqueue_assets(): void {
		wp_enqueue_style( 'ykt-campaign-frontend', YKT_PLUGIN_URL . 'assets/campaign-frontend.css', array(), YKT_VERSION );
		wp_enqueue_script( 'ykt-campaign-frontend', YKT_PLUGIN_URL . 'assets/campaign-frontend.js', array(), YKT_VERSION, true );
		wp_localize_script(
			'ykt-campaign-frontend',
			'yktCampaignFrontend',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Resolve package products from explicit IDs or campaign SKUs.
	 *
	 * @return array{A:?WC_Product,B:?WC_Product}
	 */
	private function campaign_products( int $package_a_id = 0, int $package_b_id = 0 ): array {
		return array(
			'A' => $this->product_by_id_or_sku( $package_a_id, self::SKU_PACKAGE_A ),
			'B' => $this->product_by_id_or_sku( $package_b_id, self::SKU_PACKAGE_B ),
		);
	}

	/**
	 * Get product by explicit ID with SKU fallback.
	 */
	private function product_by_id_or_sku( int $product_id, string $sku ): ?WC_Product {
		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof WC_Product ) {
				return $product;
			}
		}

		$sku_product_id = wc_get_product_id_by_sku( $sku );
		if ( $sku_product_id ) {
			$product = wc_get_product( $sku_product_id );
			if ( $product instanceof WC_Product ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Render one product card.
	 */
	private function render_product_card( string $package, ?WC_Product $product ): string {
		if ( ! $product instanceof WC_Product ) {
			return '<article class="ykt-product-card ykt-product-card--missing"><h2>' . esc_html__( 'Produk belum tersedia', 'yiari-campaign-toolkit' ) . '</h2></article>';
		}

		$product_id = $product->get_id();
		$image = wp_get_attachment_image_url( $product->get_image_id(), 'medium_large' );
		$checkout_url = add_query_arg(
			array(
				'add-to-cart' => $product_id,
				'quantity'    => 1,
			),
			wc_get_checkout_url()
		);
		$description = 'A' === $package
			? __( 'Traktir buku untuk anak-anak di sekitar habitat orangutan. Tidak membutuhkan alamat pengiriman.', 'yiari-campaign-toolkit' )
			: __( 'Beli satu buku untuk Anda dan traktir satu buku untuk anak-anak. Membutuhkan alamat pengiriman.', 'yiari-campaign-toolkit' );
		$badge = 'A' === $package ? __( 'Tanpa pengiriman', 'yiari-campaign-toolkit' ) : __( 'Termasuk pengiriman', 'yiari-campaign-toolkit' );

		ob_start();
		?>
		<article class="ykt-product-card ykt-product-card--<?php echo esc_attr( strtolower( $package ) ); ?>">
			<?php if ( $image ) : ?>
				<img class="ykt-product-card__image" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy">
			<?php endif; ?>
			<div class="ykt-product-card__body">
				<span class="ykt-product-card__badge"><?php echo esc_html( $badge ); ?></span>
				<h2><?php echo esc_html( $product->get_name() ); ?></h2>
				<p><?php echo esc_html( $description ); ?></p>
				<div class="ykt-product-card__footer">
					<strong class="ykt-product-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></strong>
					<a class="ykt-button ykt-button--primary" href="<?php echo esc_url( $checkout_url ); ?>"><?php echo esc_html__( 'Pilih Paket Ini', 'yiari-campaign-toolkit' ); ?></a>
				</div>
			</div>
		</article>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Current WooCommerce cart item count.
	 */
	private function cart_count(): int {
		if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
			return (int) WC()->cart->get_cart_contents_count();
		}

		return 0;
	}

	/**
	 * Inline cart SVG for header/menu usage.
	 */
	private function cart_svg(): string {
		return '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2Zm10 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2ZM6.2 6l.9 5.4c.2 1 1 1.6 2 1.6h7.5c.9 0 1.7-.6 1.9-1.5L20 6H6.2ZM5.8 4H21c.3 0 .6.1.8.4.2.2.2.5.2.8l-1.6 6.8c-.4 1.8-1.9 3-3.8 3H9.1c-1.9 0-3.4-1.3-3.8-3.1L3.9 3.8C3.8 3.3 3.4 3 2.9 3H2V1h.9c1.5 0 2.7 1.1 2.9 2.5L5.8 4Z"/></svg>';
	}
}
