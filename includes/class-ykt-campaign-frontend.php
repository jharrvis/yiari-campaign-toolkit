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
		add_shortcode( 'ykt_single_product_campaign', array( $this, 'render_single_product_shortcode' ) );
		add_shortcode( 'ykt_cart_icon', array( $this, 'render_cart_icon_shortcode' ) );
		add_action( 'wp_loaded', array( $this, 'handle_campaign_add_to_cart' ), 15 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_cart_assets' ) );
		add_filter( 'wp_kses_allowed_html', array( $this, 'allow_cart_quantity_input_html' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_fragments' ) );
		add_action( 'template_redirect', array( $this, 'redirect_shop_to_campaign' ) );
		add_filter( 'redirect_canonical', array( $this, 'preserve_oxygen_builder_url' ), 10, 2 );
		add_action( 'wp_ajax_ykt_cart_count', array( $this, 'ajax_cart_count' ) );
		add_action( 'wp_ajax_nopriv_ykt_cart_count', array( $this, 'ajax_cart_count' ) );
		add_action( 'wp_ajax_ykt_cart_panel', array( $this, 'ajax_cart_panel' ) );
		add_action( 'wp_ajax_nopriv_ykt_cart_panel', array( $this, 'ajax_cart_panel' ) );
	}

	/**
	 * Add a campaign product with the selected quantity as the final cart quantity.
	 */
	public function handle_campaign_add_to_cart(): void {
		if ( empty( $_GET['ykt_campaign_add_to_cart'] ) || ! function_exists( 'WC' ) ) {
			return;
		}

		$product_id = absint( wp_unslash( $_GET['ykt_campaign_add_to_cart'] ) );
		$quantity = isset( $_GET['quantity'] ) ? max( 1, absint( wp_unslash( $_GET['quantity'] ) ) ) : 1;
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product || ! YKT_Checkout::get_product_package_type( $product_id ) ) {
			return;
		}

		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$cart_product_id = (int) ( $cart_item['variation_id'] ?: $cart_item['product_id'] );
			if ( $cart_product_id === $product_id ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			}
		}

		WC()->cart->add_to_cart( $product_id, $quantity );
		WC()->cart->calculate_totals();

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
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
		<section id="ykt-campaign-products" class="paket-buku-section" aria-labelledby="paket-buku-heading">
			<div class="paket-buku-section__intro">
				<p class="paket-buku-section__eyebrow"><?php echo esc_html__( 'Batch 1', 'yiari-campaign-toolkit' ); ?></p>
				<h2 id="paket-buku-heading"><?php echo esc_html__( 'Pilih paket bukumu', 'yiari-campaign-toolkit' ); ?></h2>
				<p><?php echo esc_html__( 'Setiap pembelian membantu menghadirkan buku dan cerita konservasi bagi anak-anak di Kalimantan Barat.', 'yiari-campaign-toolkit' ); ?></p>
			</div>

			<div class="paket-buku-grid">
				<?php echo $this->render_product_card( 'A', $products['A'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->render_product_card( 'B', $products['B'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render an Oxygen-friendly single campaign product detail section.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 */
	public function render_single_product_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'product_id' => 0,
			),
			$atts,
			'ykt_single_product_campaign'
		);

		$this->enqueue_assets();

		$product_id = absint( $atts['product_id'] );
		if ( ! $product_id && is_singular( 'product' ) ) {
			$product_id = get_the_ID();
		}

		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return '<section class="ykt-single-product"><p>' . esc_html__( 'Produk campaign belum tersedia.', 'yiari-campaign-toolkit' ) . '</p></section>';
		}

		$package = class_exists( 'YKT_Checkout' ) ? YKT_Checkout::get_product_package_type( $product->get_id() ) : '';
		$is_package_a = 'A' === $package;
		$image = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
		if ( ! $image && $is_package_a ) {
			$fallback_product_id = wc_get_product_id_by_sku( self::SKU_PACKAGE_B );
			$fallback_product = $fallback_product_id ? wc_get_product( $fallback_product_id ) : null;
			$image = $fallback_product instanceof WC_Product ? wp_get_attachment_image_url( $fallback_product->get_image_id(), 'large' ) : '';
		}

		$eyebrow = $package ? sprintf( 'Paket %s Campaign Buku', $package ) : __( 'Campaign Buku', 'yiari-campaign-toolkit' );
		$summary = $this->single_product_summary( $package );
		$benefits = $this->single_product_benefits( $package );
		$note = $is_package_a
			? __( 'Paket A sudah termasuk biaya distribusi ke sekolah atau taman baca di Kalimantan Barat, sehingga Anda tidak perlu mengisi alamat pengiriman.', 'yiari-campaign-toolkit' )
			: __( 'Paket B membutuhkan alamat donor karena satu buku akan dikirim ke alamat Anda. Ongkos kirim dihitung saat checkout.', 'yiari-campaign-toolkit' );
		$button_label = $is_package_a ? __( 'Traktir Buku Sekarang', 'yiari-campaign-toolkit' ) : __( 'Beli & Traktir Sekarang', 'yiari-campaign-toolkit' );
		$description = wpautop( wp_kses_post( $product->get_description() ) );

		ob_start();
		?>
		<section class="ykt-single-product" aria-labelledby="ykt-single-product-title">
			<div class="ykt-single-product__media">
				<?php if ( $image ) : ?>
					<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="eager">
				<?php endif; ?>
				<?php if ( $package ) : ?>
					<span class="ykt-single-product__badge"><?php echo esc_html( 'Paket ' . $package ); ?></span>
				<?php endif; ?>
			</div>

			<div class="ykt-single-product__summary">
				<p class="ykt-single-product__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
				<h1 id="ykt-single-product-title"><?php echo esc_html( $product->get_name() ); ?></h1>
				<p class="ykt-single-product__lead"><?php echo esc_html( $summary ); ?></p>
				<div class="ykt-single-product__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>

				<ul class="ykt-single-product__benefits">
					<?php foreach ( $benefits as $benefit ) : ?>
						<li><?php echo esc_html( $benefit ); ?></li>
					<?php endforeach; ?>
				</ul>

				<p class="ykt-single-product__note"><?php echo esc_html( $note ); ?></p>

				<form class="ykt-single-product__purchase" method="get" action="<?php echo esc_url( wc_get_checkout_url() ); ?>">
					<input type="hidden" name="ykt_campaign_add_to_cart" value="<?php echo esc_attr( (string) $product->get_id() ); ?>">
					<label>
						<span><?php echo esc_html__( 'Jumlah', 'yiari-campaign-toolkit' ); ?></span>
						<input type="number" name="quantity" value="1" min="1" step="1" inputmode="numeric">
					</label>
					<button type="submit"><?php echo esc_html( $button_label ); ?></button>
				</form>

				<div class="ykt-single-product__links">
					<a href="<?php echo esc_url( home_url( '/campaign/' ) ); ?>"><?php echo esc_html__( 'Kembali ke halaman campaign', 'yiari-campaign-toolkit' ); ?></a>
					<a href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php echo esc_html__( 'Lihat keranjang', 'yiari-campaign-toolkit' ); ?></a>
				</div>
			</div>

			<?php if ( $description ) : ?>
				<div class="ykt-single-product__description">
					<h2><?php echo esc_html__( 'Detail Paket', 'yiari-campaign-toolkit' ); ?></h2>
					<?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
		</section>
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

		$count = $this->cart_count();
		$count_class = $count > 0 ? 'ykt-cart-icon__count' : 'ykt-cart-icon__count ykt-cart-icon__count--empty';

		return sprintf(
			'<a class="ykt-cart-icon" href="%1$s" role="button" aria-label="%2$s" aria-haspopup="dialog" aria-expanded="false"><span class="ykt-cart-icon__svg" aria-hidden="true">%3$s</span><span class="%4$s">%5$d</span></a>%6$s',
			esc_url( $url ),
			esc_attr( $atts['label'] ),
			$this->cart_svg(),
			esc_attr( $count_class ),
			$count,
			$this->render_cart_panel()
		);
	}

	/**
	 * Redirect the default WooCommerce shop archive to the campaign landing page.
	 */
	public function redirect_shop_to_campaign(): void {
		if ( is_admin() || wp_doing_ajax() || $this->is_oxygen_builder_request() || ! function_exists( 'is_shop' ) || ! is_shop() ) {
			return;
		}

		$campaign_page = get_page_by_path( 'campaign' );
		if ( ! $campaign_page instanceof WP_Post || 'publish' !== $campaign_page->post_status ) {
			return;
		}

		wp_safe_redirect( get_permalink( $campaign_page ), 301 );
		exit;
	}


	/**
	 * Prevent WordPress canonical redirects from stripping Oxygen Builder query args.
	 *
	 * @param string|false $redirect_url  Canonical redirect URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public function preserve_oxygen_builder_url( $redirect_url, string $requested_url ) {
		unset( $requested_url );

		return $this->is_oxygen_builder_request() ? false : $redirect_url;
	}

	/**
	 * Detect Oxygen Builder edit/iframe requests that must keep their query string.
	 */
	private function is_oxygen_builder_request(): bool {
		foreach ( array( 'ct_builder', 'ct_inner', 'oxygen_iframe' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Update the cart icon count after WooCommerce AJAX add-to-cart events.
	 *
	 * @param array<string, string> $fragments Existing fragments.
	 * @return array<string, string>
	 */
	public function cart_fragments( array $fragments ): array {
		$count = $this->cart_count();
		$count_class = $count > 0 ? 'ykt-cart-icon__count' : 'ykt-cart-icon__count ykt-cart-icon__count--empty';
		$fragments['.ykt-cart-icon__count'] = '<span class="' . esc_attr( $count_class ) . '">' . absint( $count ) . '</span>';
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
	 * Return cart panel HTML for AJAX refreshes.
	 */
	public function ajax_cart_panel(): void {
		wp_send_json_success(
			array(
				'count' => $this->cart_count(),
				'html'  => $this->cart_panel_body_html(),
			)
		);
	}

	/**
	 * Render the off-canvas cart panel shell once per shortcode instance.
	 */
	private function render_cart_panel(): string {
		static $rendered = false;
		if ( $rendered ) {
			return '';
		}
		$rendered = true;

		ob_start();
		?>
		<div class="ykt-cart-drawer" aria-hidden="true">
			<div class="ykt-cart-drawer__overlay" data-ykt-cart-close></div>
			<aside class="ykt-cart-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__( 'Keranjang belanja', 'yiari-campaign-toolkit' ); ?>">
				<header class="ykt-cart-drawer__header">
					<strong><?php echo esc_html__( 'Keranjang', 'yiari-campaign-toolkit' ); ?></strong>
					<button type="button" class="ykt-cart-drawer__close" data-ykt-cart-close aria-label="<?php echo esc_attr__( 'Tutup keranjang', 'yiari-campaign-toolkit' ); ?>">&times;</button>
				</header>
				<div class="ykt-cart-drawer__body">
					<?php echo $this->cart_panel_body_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</aside>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render current cart items for the drawer body.
	 */
	private function cart_panel_body_html(): string {
		if ( function_exists( 'WC' ) && null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '<div class="ykt-cart-drawer__empty"><p>' . esc_html__( 'Keranjang masih kosong.', 'yiari-campaign-toolkit' ) . '</p><a class="ykt-cart-drawer__button" href="' . esc_url( home_url( '/campaign/' ) ) . '">' . esc_html__( 'Pilih Paket', 'yiari-campaign-toolkit' ) . '</a></div>';
		}

		ob_start();
		?>
		<ul class="ykt-cart-drawer__items">
			<?php foreach ( WC()->cart->get_cart() as $cart_item ) : ?>
				<?php
				$product = $cart_item['data'] ?? null;
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$thumbnail = $product->get_image( 'woocommerce_thumbnail' );
				?>
				<li class="ykt-cart-drawer__item">
					<div class="ykt-cart-drawer__thumb"><?php echo wp_kses_post( $thumbnail ); ?></div>
					<div class="ykt-cart-drawer__item-content">
						<strong><?php echo esc_html( $product->get_name() ); ?></strong>
						<span><?php echo esc_html( sprintf( '%d x', absint( $cart_item['quantity'] ?? 0 ) ) ); ?> <?php echo wp_kses_post( WC()->cart->get_product_price( $product ) ); ?></span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="ykt-cart-drawer__summary">
			<div><span><?php echo esc_html__( 'Subtotal', 'yiari-campaign-toolkit' ); ?></span><strong><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></strong></div>
			<a class="ykt-cart-drawer__button" href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php echo esc_html__( 'Checkout', 'yiari-campaign-toolkit' ); ?></a>
			<a class="ykt-cart-drawer__link" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php echo esc_html__( 'Lihat Keranjang', 'yiari-campaign-toolkit' ); ?></a>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Load campaign frontend assets on WooCommerce cart, checkout, and account pages.
	 */
	public function enqueue_cart_assets(): void {
		$is_cart = function_exists( 'is_cart' ) && is_cart();
		$is_checkout = function_exists( 'is_checkout' ) && is_checkout();
		$is_account = function_exists( 'is_account_page' ) && is_account_page();
		$is_product = function_exists( 'is_product' ) && is_product();

		if ( $is_cart || $is_checkout || $is_account || $is_product ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Keep WooCommerce cart quantity fields editable when KiriminAja sanitizes the cart row.
	 *
	 * KiriminAja's cart template passes the WooCommerce quantity HTML through wp_kses_post().
	 * WordPress' post allowlist strips form inputs by default, leaving an empty quantity column.
	 * This keeps the allowance scoped to WooCommerce cart/checkout rendering and only permits
	 * the attributes used by WooCommerce quantity inputs.
	 *
	 * @param array<string, mixed> $allowed_html Allowed HTML tags and attributes.
	 * @param string|array         $context      KSES context.
	 * @return array<string, mixed>
	 */
	public function allow_cart_quantity_input_html( array $allowed_html, $context ): array {
		if ( 'post' !== $context || ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) ) {
			return $allowed_html;
		}

		$allowed_html['input'] = array(
			'aria-label'   => true,
			'autocomplete' => true,
			'class'        => true,
			'id'           => true,
			'inputmode'    => true,
			'max'          => true,
			'min'          => true,
			'name'         => true,
			'pattern'      => true,
			'placeholder'  => true,
			'readonly'     => true,
			'size'         => true,
			'step'         => true,
			'type'         => true,
			'value'        => true,
		);

		return $allowed_html;
	}

	/**
	 * Load frontend styles/scripts only when a shortcode renders.
	 */
	private function enqueue_assets(): void {
		wp_enqueue_style( 'ykt-campaign-fonts', 'https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;600;700&family=Nunito+Sans:wght@400;600;700;800&display=swap', array(), null );
		wp_enqueue_style( 'ykt-campaign-frontend', YKT_PLUGIN_URL . 'assets/campaign-frontend.css', array( 'ykt-campaign-fonts' ), YKT_VERSION );
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
	 * Return concise package-specific copy for the single product template.
	 */
	private function single_product_summary( string $package ): string {
		if ( 'A' === $package ) {
			return __( 'Dukung pengiriman buku edukasi konservasi untuk anak-anak di sekitar habitat orangutan.', 'yiari-campaign-toolkit' );
		}

		if ( 'B' === $package ) {
			return __( 'Dapatkan satu buku untuk Anda dan bantu satu buku lainnya sampai ke anak-anak di Kalimantan Barat.', 'yiari-campaign-toolkit' );
		}

		return __( 'Dukung campaign buku Karmila & Gito bersama YIARI.', 'yiari-campaign-toolkit' );
	}

	/**
	 * Return package-specific benefit bullets for the single product template.
	 *
	 * @return array<int, string>
	 */
	private function single_product_benefits( string $package ): array {
		if ( 'A' === $package ) {
			return array(
				__( 'Buku didistribusikan untuk anak di Kalimantan Barat', 'yiari-campaign-toolkit' ),
				__( 'Biaya distribusi ke sekolah sudah termasuk', 'yiari-campaign-toolkit' ),
				__( 'Sertifikat digital otomatis setelah pembayaran berhasil', 'yiari-campaign-toolkit' ),
				__( 'Update perkembangan campaign dari YIARI', 'yiari-campaign-toolkit' ),
			);
		}

		if ( 'B' === $package ) {
			return array(
				__( 'Satu buku dikirim ke alamat donor', 'yiari-campaign-toolkit' ),
				__( 'Satu buku didistribusikan untuk anak di Kalimantan Barat', 'yiari-campaign-toolkit' ),
				__( 'Ongkos kirim dihitung otomatis di checkout', 'yiari-campaign-toolkit' ),
				__( 'Sertifikat digital dan link tracking pesanan', 'yiari-campaign-toolkit' ),
			);
		}

		return array(
			__( 'Bagian dari campaign buku Karmila & Gito', 'yiari-campaign-toolkit' ),
			__( 'Pembayaran diproses melalui WooCommerce dan Midtrans', 'yiari-campaign-toolkit' ),
			__( 'Konfirmasi email dikirim otomatis setelah pembayaran berhasil', 'yiari-campaign-toolkit' ),
		);
	}

	/**
	 * Render one product card.
	 */
	private function render_product_card( string $package, ?WC_Product $product ): string {
		if ( ! $product instanceof WC_Product ) {
			return '<article class="paket-buku-card"><div class="paket-buku-card__content"><h3>' . esc_html__( 'Produk belum tersedia', 'yiari-campaign-toolkit' ) . '</h3></div></article>';
		}

		$product_id = $product->get_id();
		$image = wp_get_attachment_image_url( $product->get_image_id(), 'medium_large' );
		if ( ! $image && 'A' === $package ) {
			$fallback_product_id = wc_get_product_id_by_sku( self::SKU_PACKAGE_B );
			$fallback_product = $fallback_product_id ? wc_get_product( $fallback_product_id ) : null;
			$image = $fallback_product instanceof WC_Product ? wp_get_attachment_image_url( $fallback_product->get_image_id(), 'medium_large' ) : '';
		}

		$is_package_a = 'A' === $package;
		$article_class = $is_package_a ? 'paket-buku-card' : 'paket-buku-card paket-buku-card--featured';
		$visual_class = $is_package_a ? 'paket-buku-card__visual paket-buku-card__visual--single' : 'paket-buku-card__visual paket-buku-card__visual--double';
		$title = $is_package_a ? __( 'Traktir Buku untuk Anak', 'yiari-campaign-toolkit' ) : __( 'Beli Buku, Traktir Buku', 'yiari-campaign-toolkit' );
		$description = $is_package_a
			? __( 'Danai buku untuk anak-anak di sekolah atau taman baca di Kalimantan Barat. Biaya distribusi ditangani YIARI, sehingga donor tidak perlu mengisi alamat pengiriman.', 'yiari-campaign-toolkit' )
			: __( 'Dapatkan buku untukmu sekaligus mendanai buku untuk anak di sekitar habitat orangutan. Ongkos kirim dihitung sesuai alamat donor.', 'yiari-campaign-toolkit' );
		$button_label = $is_package_a ? __( 'Traktir mulai', 'yiari-campaign-toolkit' ) : __( 'Beli & traktir', 'yiari-campaign-toolkit' );
		$benefits = $is_package_a
			? array(
				__( 'Buku untuk anak di Kalimantan', 'yiari-campaign-toolkit' ),
				__( 'Biaya distribusi ke sekolah sudah termasuk', 'yiari-campaign-toolkit' ),
				__( 'Sertifikat digital apresiasi', 'yiari-campaign-toolkit' ),
				__( 'Laporan perkembangan program', 'yiari-campaign-toolkit' ),
			)
			: array(
				__( 'Buku fisik dan freebies untuk kamu', 'yiari-campaign-toolkit' ),
				__( 'Buku untuk anak di Kalimantan', 'yiari-campaign-toolkit' ),
				__( 'Ongkir ke alamat donor dihitung di checkout', 'yiari-campaign-toolkit' ),
				__( 'Sertifikat digital apresiasi', 'yiari-campaign-toolkit' ),
			);

		ob_start();
		?>
		<article class="<?php echo esc_attr( $article_class ); ?>" aria-labelledby="paket-<?php echo esc_attr( strtolower( $package ) ); ?>-title">
			<?php if ( ! $is_package_a ) : ?>
				<div class="paket-buku-card__badge"><?php echo esc_html__( 'Pilihan Terbaik', 'yiari-campaign-toolkit' ); ?></div>
			<?php endif; ?>
			<figure class="<?php echo esc_attr( $visual_class ); ?>">
				<?php if ( $image ) : ?>
					<?php if ( $is_package_a ) : ?>
						<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy">
					<?php else : ?>
						<img class="book book--back" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy">
						<img class="book book--front" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy">
					<?php endif; ?>
				<?php endif; ?>
				<figcaption><?php echo esc_html( 'Paket ' . $package ); ?></figcaption>
			</figure>
			<div class="paket-buku-card__content">
				<h3 id="paket-<?php echo esc_attr( strtolower( $package ) ); ?>-title"><?php echo esc_html( $title ); ?></h3>
				<p class="paket-buku-card__description"><?php echo esc_html( $description ); ?></p>
				<ul class="paket-buku-card__benefits">
					<?php foreach ( $benefits as $benefit ) : ?>
						<li><?php echo esc_html( $benefit ); ?></li>
					<?php endforeach; ?>
				</ul>
				<form class="paket-buku-card__purchase" method="get" action="<?php echo esc_url( wc_get_checkout_url() ); ?>">
					<input type="hidden" name="ykt_campaign_add_to_cart" value="<?php echo esc_attr( (string) $product_id ); ?>">
					<label class="paket-buku-card__qty-label">
						<span><?php echo esc_html__( 'Jumlah', 'yiari-campaign-toolkit' ); ?></span>
						<input class="paket-buku-card__qty" type="number" name="quantity" value="1" min="1" step="1" inputmode="numeric">
					</label>
					<button class="paket-buku-card__cta" type="submit">
						<?php echo esc_html( $button_label ); ?> <?php echo wp_kses_post( $product->get_price_html() ); ?> <span aria-hidden="true">&rarr;</span>
					</button>
				</form>
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
