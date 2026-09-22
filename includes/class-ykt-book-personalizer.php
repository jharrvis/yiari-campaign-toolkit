<?php
/**
 * Creates a donor-specific copy of the campaign book PDF.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use setasign\Fpdi\Tfpdf\Fpdi;

/**
 * Replaces the page-three donor placeholder without modifying the source PDF.
 */
class YKT_Book_Personalizer {
	private const BOOK_SUBDIR           = 'ykt-books';
	private const BOOK_PATH_META        = '_ykt_personalized_book_path_v2';
	private const BOOK_CREATED_META     = '_ykt_personalized_book_created_at_v2';
	private const CLEANUP_CRON_HOOK     = 'ykt_cleanup_personalized_books';
	private const CLEANUP_CRON_SCHEDULE = 'ykt_every_hour';
	private const BOOK_RETENTION        = DAY_IN_SECONDS;

	/**
	 * Source book filename uploaded by the campaign team.
	 */
	private const SOURCE_FILE        = 'FIXED PDF Buku Karmila Gito + Page Greetings.pdf';
	private const PARSER_SOURCE_FILE = 'Petualangan Karmila Gito - Normalized.pdf';

	/**
	 * Register the cleanup cron independently from WooCommerce.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
		add_action( self::CLEANUP_CRON_HOOK, array( __CLASS__, 'cleanup_expired_books' ) );

		if ( ! wp_next_scheduled( self::CLEANUP_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::CLEANUP_CRON_SCHEDULE, self::CLEANUP_CRON_HOOK );
		}
	}

	/**
	 * Schedule cleanup when the plugin is activated.
	 */
	public static function activate(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
		if ( ! wp_next_scheduled( self::CLEANUP_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::CLEANUP_CRON_SCHEDULE, self::CLEANUP_CRON_HOOK );
		}
	}

	/**
	 * Remove cleanup events when the plugin is deactivated.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_CRON_HOOK );
	}

	/**
	 * Add the hourly cleanup schedule.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_cron_schedule( array $schedules ): array {
		$schedules[ self::CLEANUP_CRON_SCHEDULE ] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'Every hour', 'yiari-campaign-toolkit' ),
		);

		return $schedules;
	}

	/**
	 * Create a personalized PDF for an order without recompressing the source.
	 *
	 * @param WC_Order $order Paid campaign order.
	 * @return string Empty string when personalization is unavailable.
	 */
	public static function create_for_order( $order ): string {
		if ( ! $order instanceof WC_Order || ! class_exists( Fpdi::class ) ) {
			return self::fallback_book_path();
		}

		$existing = self::existing_book_path( $order );
		if ( $existing ) {
			return $existing;
		}

		$source = YKT_PLUGIN_DIR . 'assets/book/' . self::SOURCE_FILE;
		if ( ! is_readable( $source ) ) {
			return self::fallback_book_path();
		}

		$parser_source           = YKT_PLUGIN_DIR . 'assets/book/' . self::PARSER_SOURCE_FILE;
		$normalized_source       = is_readable( $parser_source ) ? $parser_source : self::normalized_source( $source );
		$temporary_parser_source = $normalized_source && $normalized_source !== $parser_source;
		if ( ! $normalized_source ) {
			return self::fallback_book_path();
		}

		$donor_name = trim( wp_strip_all_tags( $order->get_formatted_billing_full_name() ) );
		if ( '' === $donor_name ) {
			$donor_name = trim( wp_strip_all_tags( implode( " ", array_filter( array( (string) $order->get_billing_first_name(), (string) $order->get_billing_last_name() ) ) ) ) );
		}
		if ( '' === $donor_name ) {
			return self::fallback_book_path();
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return self::fallback_book_path();
		}

		$book_dir = trailingslashit( $upload_dir['basedir'] ) . self::BOOK_SUBDIR;
		if ( ! wp_mkdir_p( $book_dir ) ) {
			return self::fallback_book_path();
		}
		self::protect_book_directory( $book_dir );

		$safe_name = sanitize_file_name( 'Petualangan Karmila Gito - ' . $donor_name . '.pdf' );
		$file_name = 'ykt-' . $order->get_id() . '-' . $safe_name;
		$output = trailingslashit( $book_dir ) . $file_name;
		$raw_output    = tempnam( sys_get_temp_dir(), 'ykt-book-' . absint( $order->get_id() ) . '-' );
		$created_output = false;
		if ( ! $raw_output ) {
			return self::fallback_book_path();
		}
		try {
			if ( ! self::write_personalized_pdf( $normalized_source, $raw_output, $donor_name ) ) {
				throw new RuntimeException( 'Could not create personalized campaign book.' );
			}

			if ( rename( $raw_output, $output ) ) {
				$created_output = true;
			} elseif ( ! file_exists( $output ) || filesize( $output ) < 1000 ) {
				throw new RuntimeException( 'Could not finalize campaign book.' );
			}

			if ( ! file_exists( $output ) || filesize( $output ) < 1000 ) {
				throw new RuntimeException( 'Personalized campaign book is empty.' );
			}
			if ( ! chmod( $output, 0644 ) ) {
				throw new RuntimeException( 'Personalized campaign book is not readable by the web server.' );
			}

			$relative_path = trailingslashit( self::BOOK_SUBDIR ) . $file_name;
			$order->update_meta_data( self::BOOK_PATH_META, $relative_path );
			$order->update_meta_data( self::BOOK_CREATED_META, current_time( 'mysql', true ) );
			$order->save();
			self::cleanup( $temporary_parser_source ? $normalized_source : '', $raw_output );
			wc_get_logger()->info( 'Personalized campaign book created: ' . $relative_path, array( 'source' => 'yiari-campaign-toolkit' ) );
			return $output;
		} catch ( Throwable $exception ) {
			self::cleanup( $temporary_parser_source ? $normalized_source : '', $created_output ? $output : '', $raw_output );
			wc_get_logger()->error(
				'Unable to personalize campaign book PDF: ' . $exception->getMessage(),
				array( 'source' => 'ykt-book-personalizer', 'order_id' => $order->get_id() )
			);
			return self::fallback_book_path();
		}
	}

	/**
	 * Return the existing personalized book for an order, if available.
	 */
	private static function existing_book_path( WC_Order $order ): string {
		$relative_path = (string) $order->get_meta( self::BOOK_PATH_META, true );
		if ( '' === $relative_path ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$path = trailingslashit( $upload_dir['basedir'] ) . ltrim( $relative_path, '/\\' );
		return file_exists( $path ) && filesize( $path ) > 1000 ? $path : '';
	}

	/**
	 * Keep generated books out of directory listings and direct web access.
	 */
	private static function protect_book_directory( string $book_dir ): void {
		$index = trailingslashit( $book_dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $book_dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
	}

	/**
	 * Import all source pages and add the donor name on page 3.
	 */
	private static function write_personalized_pdf( string $source, string $output, string $donor_name ): bool {
		$pdf = new Fpdi();
		$pdf->SetAutoPageBreak( false );
		$page_count = $pdf->setSourceFile( $source );

		for ( $page_number = 1; $page_number <= $page_count; $page_number++ ) {
			$template_id = $pdf->importPage( $page_number );
			$size        = $pdf->getTemplateSize( $template_id );
			$orientation = $size['width'] > $size['height'] ? 'L' : 'P';
			$pdf->AddPage( $orientation, array( $size['width'], $size['height'] ) );
			$pdf->useTemplate( $template_id );

			if ( 3 === $page_number ) {
				self::overlay_donor_name( $pdf, $donor_name );
			}
		}

		$pdf->Output( 'F', $output );
		return file_exists( $output );
	}

	/**
	 * Write the donor name in the same opening line without covering the artwork.
	 */
	private static function overlay_donor_name( Fpdi $pdf, string $donor_name ): void {
		$font_file = YKT_PLUGIN_DIR . 'vendor/setasign/tfpdf/font/unifont/DejaVuSans-Bold.ttf';
		if ( ! is_readable( $font_file ) ) {
			throw new RuntimeException( 'Bundled DejaVuSans-Bold font is missing.' );
		}
		$pdf->AddFont( 'DejaVu', 'B', 'DejaVuSans-Bold.ttf', true );
		$pdf->SetFont( 'DejaVu', 'B', 14 );
		$pdf->SetTextColor( 22, 78, 54 );
		$pdf->SetXY( 14, 25 );
		$pdf->Cell( 50, 7, self::fit_name( $pdf, 'Hai, ' . $donor_name, 50 ), 0, 0, 'L' );
	}

	/**
	 * Reduce long names to the available placeholder width.
	 */
	private static function fit_name( Fpdi $pdf, string $name, float $width ): string {
		while ( $pdf->GetStringWidth( $name ) > $width && strlen( $name ) > 4 ) {
			$name = preg_replace( '/\s+\S+$/u', '', $name ) ?: substr( $name, 0, -1 );
		}

		return $name;
	}

	/**
	 * Create a temporary parser-compatible copy of the source PDF.
	 *
	 * The source remains unchanged and the generated donor PDF is not optimized.
	 */
	private static function normalized_source( string $source ): string {
		if ( ! function_exists( 'proc_open' ) || ! is_executable( '/usr/bin/gs' ) ) {
			return '';
		}

		$normalized = tempnam( sys_get_temp_dir(), 'ykt-book-source-' );
		if ( ! $normalized ) {
			return '';
		}

		$command = '/usr/bin/gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/prepress -dNOPAUSE -dQUIET -dBATCH -sOutputFile=' . escapeshellarg( $normalized ) . ' ' . escapeshellarg( $source );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) {
			self::cleanup( $normalized );
			return '';
		}

		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}

		if ( 0 !== proc_close( $process ) || ! file_exists( $normalized ) || filesize( $normalized ) < 1000 ) {
			self::cleanup( $normalized );
			return '';
		}

		return $normalized;
	}

	/**
	 * Return no attachment when generation cannot run.
	 */
	private static function fallback_book_path(): string {
		$path = YKT_PLUGIN_DIR . 'assets/book/' . self::SOURCE_FILE;
		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Delete generated donor PDFs after the one-day retention period.
	 */
	public static function cleanup_expired_books(): void {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return;
		}

		$book_dir = trailingslashit( $upload_dir['basedir'] ) . self::BOOK_SUBDIR;
		$files    = glob( trailingslashit( $book_dir ) . 'ykt-*.pdf' );
		if ( ! is_array( $files ) ) {
			return;
		}

		$cutoff        = time() - self::BOOK_RETENTION;
		$deleted       = 0;
		$deleted_bytes = 0;
		foreach ( $files as $file ) {
			if ( ! is_file( $file ) || filemtime( $file ) >= $cutoff ) {
				continue;
			}

			$size = (int) filesize( $file );
			if ( unlink( $file ) ) {
				++$deleted;
				$deleted_bytes += $size;
			}
		}

		if ( $deleted > 0 && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				sprintf( 'Cleaned %d personalized campaign book(s), %d bytes.', $deleted, $deleted_bytes ),
				array( 'source' => 'ykt-book-personalizer' )
			);
		}
	}

	/**
	 * Remove temporary files created for one email.
	 */
	private static function cleanup( string ...$paths ): void {
		foreach ( $paths as $path ) {
			if ( $path && file_exists( $path ) ) {
				unlink( $path );
			}
		}
	}
}
