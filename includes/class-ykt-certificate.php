<?php
/**
 * Campaign certificate generation.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Generates one immutable donor certificate when a campaign order is paid.
 */
class YKT_Certificate {
	private const META_CERTIFICATE_NUMBER = '_certificate_number';
	private const META_CERTIFICATE_PATH = '_certificate_path';
	private const META_CERTIFICATE_GENERATED_AT = '_certificate_generated_at';
	private const META_PACKAGE_TYPE = '_campaign_package_type';
	private const SEQUENCE_OPTION = 'ykt_certificate_sequence';
	private const UPLOAD_SUBDIR = 'ykt-certificates';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_generate_on_paid' ), 30, 4 );
	}

	/**
	 * Generate a certificate as soon as Midtrans/WooCommerce confirms payment.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from Previous status without wc-.
	 * @param string   $to New status without wc-.
	 * @param WC_Order $order Order object.
	 */
	public function maybe_generate_on_paid( int $order_id, string $from, string $to, $order ): void {
		unset( $from );

		if ( 'paid' !== $to ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order || ! class_exists( 'YKT_Checkout' ) || ! YKT_Checkout::order_has_campaign_package( $order ) ) {
			return;
		}

		$result = $this->generate_for_order( $order );
		if ( is_wp_error( $result ) ) {
			$this->log( $result->get_error_message(), 'error' );
			$order->add_order_note( sprintf( 'YKT certificate was not generated: %s', $result->get_error_message() ) );
			return;
		}

		if ( 'certificate-sent' !== $order->get_status() ) {
			$order->update_status( 'certificate-sent', __( 'Campaign certificate generated and queued for donor email.', 'yiari-campaign-toolkit' ) );
		}
	}

	/**
	 * Generate or return the existing certificate for an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array{number:string,path:string}|WP_Error
	 */
	public function generate_for_order( WC_Order $order ) {
		$existing_number = (string) $order->get_meta( self::META_CERTIFICATE_NUMBER, true );
		$existing_path   = (string) $order->get_meta( self::META_CERTIFICATE_PATH, true );
		$absolute_path   = self::absolute_certificate_path( $existing_path );

		if ( $existing_number && $absolute_path && file_exists( $absolute_path ) ) {
			return array(
				'number' => $existing_number,
				'path'   => $absolute_path,
			);
		}

		if ( ! class_exists( Dompdf::class ) ) {
			return new WP_Error( 'ykt_missing_dompdf', 'Dompdf dependency is missing. Run composer install in the plugin directory.' );
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'ykt_upload_dir_error', (string) $upload_dir['error'] );
		}

		$target_dir = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'ykt_certificate_dir_error', 'Unable to create certificate upload directory.' );
		}

		$this->protect_certificate_directory( $target_dir );

		$certificate_number = $existing_number ?: $this->next_certificate_number();
		$relative_path      = $this->build_relative_path( $order, $certificate_number );
		$absolute_path      = self::absolute_certificate_path( $relative_path );

		if ( ! $absolute_path ) {
			return new WP_Error( 'ykt_certificate_path_error', 'Unable to resolve certificate path.' );
		}

		$html    = $this->render_certificate_html( $order, $certificate_number );
		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'landscape' );
		$dompdf->render();

		$bytes_written = file_put_contents( $absolute_path, $dompdf->output() );
		if ( false === $bytes_written ) {
			return new WP_Error( 'ykt_certificate_write_error', 'Unable to write certificate PDF.' );
		}

		$order->update_meta_data( self::META_CERTIFICATE_NUMBER, $certificate_number );
		$order->update_meta_data( self::META_CERTIFICATE_PATH, $relative_path );
		$order->update_meta_data( self::META_CERTIFICATE_GENERATED_AT, current_time( 'mysql', true ) );
		$order->save();

		$order->add_order_note( sprintf( 'YKT certificate generated: %s', $certificate_number ) );

		return array(
			'number' => $certificate_number,
			'path'   => $absolute_path,
		);
	}

	/**
	 * Resolve a stored relative certificate path to an absolute filesystem path.
	 *
	 * @param string $relative_path Relative upload path.
	 */
	public static function absolute_certificate_path( string $relative_path ): string {
		$relative_path = ltrim( $relative_path, '/\\' );
		if ( '' === $relative_path ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $upload_dir['basedir'] ) . $relative_path;
	}

	/**
	 * Render certificate HTML through a template so copy/design can evolve safely.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $certificate_number Certificate number.
	 */
	private function render_certificate_html( WC_Order $order, string $certificate_number ): string {
		$package_type     = strtoupper( (string) $order->get_meta( self::META_PACKAGE_TYPE, true ) );
		$package_label    = $this->package_label( $package_type );
		$book_quantity    = $this->book_quantity( $order );
		$donor_name       = trim( $order->get_formatted_billing_full_name() ) ?: __( 'YIARI Supporter', 'yiari-campaign-toolkit' );
		$certificate_date = wp_date( 'j F Y', $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time() );
		$program_name     = __( 'Karmila & Gito Book Campaign', 'yiari-campaign-toolkit' );

		ob_start();
		include YKT_PLUGIN_DIR . 'templates/certificate.php';
		return (string) ob_get_clean();
	}

	/**
	 * Return a human-readable package label.
	 *
	 * @param string $package_type Package type.
	 */
	private function package_label( string $package_type ): string {
		if ( 'A' === $package_type ) {
			return __( 'Paket A - Traktir Buku', 'yiari-campaign-toolkit' );
		}

		if ( 'B' === $package_type ) {
			return __( 'Paket B - Beli 1, Traktir 1', 'yiari-campaign-toolkit' );
		}

		return __( 'Paket Kampanye YIARI', 'yiari-campaign-toolkit' );
	}

	/**
	 * Count campaign books from order quantities.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function book_quantity( WC_Order $order ): int {
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

		return max( 1, $total );
	}

	/**
	 * Generate the next certificate number from a dedicated option counter.
	 */
	private function next_certificate_number(): string {
		global $wpdb;

		add_option( self::SEQUENCE_OPTION, 0, '', false );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
				self::SEQUENCE_OPTION
			)
		);
		wp_cache_delete( self::SEQUENCE_OPTION, 'options' );

		$sequence = max( 1, (int) get_option( self::SEQUENCE_OPTION, 1 ) );

		return sprintf( 'YIARI-KG-%s-%05d', wp_date( 'Y' ), $sequence );
	}

	/**
	 * Build a non-guessable relative upload path for the certificate.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $certificate_number Certificate number.
	 */
	private function build_relative_path( WC_Order $order, string $certificate_number ): string {
		$filename = sanitize_file_name(
			sprintf(
				'%d-%s-%s.pdf',
				$order->get_id(),
				$certificate_number,
				wp_generate_password( 16, false, false )
			)
		);

		return self::UPLOAD_SUBDIR . '/' . $filename;
	}

	/**
	 * Add lightweight web-server deny files for generated certificate storage.
	 *
	 * @param string $target_dir Absolute upload directory.
	 */
	private function protect_certificate_directory( string $target_dir ): void {
		$index_file = trailingslashit( $target_dir ) . 'index.html';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, '' );
		}

		$htaccess_file = trailingslashit( $target_dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "Deny from all\n" );
		}
	}

	/**
	 * Log certificate events without interrupting checkout/payment flows.
	 *
	 * @param string $message Log message.
	 * @param string $level Log level.
	 */
	private function log( string $message, string $level = 'info' ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'yiari-campaign-toolkit' ) );
		}
	}
}
