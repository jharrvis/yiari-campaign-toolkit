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
	private const EMAIL_RETRY_HOOK     = 'ykt_retry_campaign_email';
	private const MAX_EMAIL_RETRIES    = 5;
	private const FALLBACK_META_PREFIX = '_ykt_book_email_fallback_';

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
		add_action( self::EMAIL_RETRY_HOOK, array( __CLASS__, 'retry_campaign_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'prepare_book_on_paid' ), 40, 4 );

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
		wp_clear_scheduled_hook( self::EMAIL_RETRY_HOOK );
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
			return '';
		}

		$existing = self::existing_book_path( $order );
		if ( $existing ) {
			return $existing;
		}

		$source = YKT_PLUGIN_DIR . 'assets/book/' . self::SOURCE_FILE;
		if ( ! is_readable( $source ) ) {
			self::log_failure( 'Personalized book source is missing.', $order );
			return '';
		}

		$donor_name = trim( wp_strip_all_tags( $order->get_formatted_billing_full_name() ) );
		if ( '' === $donor_name ) {
			$donor_name = trim( wp_strip_all_tags( implode( ' ', array_filter( array( (string) $order->get_billing_first_name(), (string) $order->get_billing_last_name() ) ) ) ) );
		}
		if ( '' === $donor_name ) {
			self::log_failure( 'Donor name is missing.', $order );
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			self::log_failure( 'Upload directory is unavailable.', $order );
			return '';
		}

		$book_dir = trailingslashit( $upload_dir['basedir'] ) . self::BOOK_SUBDIR;
		if ( ! wp_mkdir_p( $book_dir ) ) {
			self::log_failure( 'Personalized book directory cannot be created.', $order );
			return '';
		}
		self::protect_book_directory( $book_dir );

		$lock_path = trailingslashit( $book_dir ) . '.ykt-order-' . absint( $order->get_id() ) . '.lock';
		$lock      = fopen( $lock_path, 'c' );
		if ( ! is_resource( $lock ) || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			self::log_failure( 'Could not acquire the personalized book lock.', $order );
			return '';
		}

		try {
			$existing = self::existing_book_path( $order );
			if ( $existing ) {
				return $existing;
			}

			$parser_source           = YKT_PLUGIN_DIR . 'assets/book/' . self::PARSER_SOURCE_FILE;
			$normalized_source       = is_readable( $parser_source ) ? $parser_source : self::normalized_source( $source );
			$temporary_parser_source = $normalized_source && $normalized_source !== $parser_source;
			if ( ! $normalized_source ) {
				self::log_failure( 'Parser-compatible PDF source is unavailable.', $order );
				return '';
			}

			$safe_name   = sanitize_file_name( 'Petualangan Karmila Gito - ' . $donor_name . '.pdf' );
			$file_name   = 'ykt-' . $order->get_id() . '-' . $safe_name;
			$output      = trailingslashit( $book_dir ) . $file_name;
			$raw_output  = tempnam( sys_get_temp_dir(), 'ykt-book-' . absint( $order->get_id() ) . '-' );
			$made_output = false;
			if ( ! $raw_output ) {
				self::log_failure( 'Could not create temporary personalized book output.', $order );
				return '';
			}

			try {
				if ( ! self::write_personalized_pdf( $normalized_source, $raw_output, $donor_name ) ) {
					throw new RuntimeException( 'Could not create personalized campaign book.' );
				}

				if ( rename( $raw_output, $output ) ) {
					$made_output = true;
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
				wc_get_logger()->info( 'Personalized campaign book created: ' . $relative_path, array( 'source' => 'yiari-campaign-toolkit', 'order_id' => $order->get_id() ) );
				return $output;
			} catch ( Throwable $exception ) {
				self::cleanup( $temporary_parser_source ? $normalized_source : '', $made_output ? $output : '', $raw_output );
				self::log_failure( 'Unable to personalize campaign book PDF: ' . $exception->getMessage(), $order );
				return '';
			} finally {
				self::cleanup( $temporary_parser_source ? $normalized_source : '', $raw_output );
			}
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * Prepare the personalized book before campaign emails are triggered.
	 */
	public static function prepare_book_on_paid( int $order_id, string $from, string $to, $order ): void {
		unset( $from );
		if ( 'paid' !== $to ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof WC_Order && class_exists( 'YKT_Checkout' ) && YKT_Checkout::order_has_campaign_package( $order ) ) {
			self::create_for_order( $order );
		}
	}

	/**
	 * Queue an email until its required personalized book exists.
	 */
	public static function queue_email_retry( int $order_id, string $email_id ): void {
		if ( $order_id < 1 || '' === $email_id ) {
			return;
		}
		$args = array( $order_id, $email_id );
		if ( ! wp_next_scheduled( self::EMAIL_RETRY_HOOK, $args ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::EMAIL_RETRY_HOOK, $args );
		}
	}

	/**
	 * Retry a blocked campaign email after book generation succeeds.
	 */
	public static function retry_campaign_email( int $order_id, string $email_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! class_exists( 'YKT_Checkout' ) || ! YKT_Checkout::order_has_campaign_package( $order ) ) {
			return;
		}

		$map = array(
			'ykt_campaign_paid'    => 'YKT_Email_Campaign_Paid',
			'ykt_campaign_shipped' => 'YKT_Email_Campaign_Shipped',
			'ykt_campaign_delivered' => 'YKT_Email_Campaign_Delivered',
			'ykt_campaign_impact'  => 'YKT_Email_Campaign_Impact',
		);

		$book = self::create_for_order( $order );
		if ( '' === $book || ! file_exists( $book ) ) {
			$key   = '_ykt_book_email_retry_' . sanitize_key( $email_id );
			$count = absint( $order->get_meta( $key, true ) ) + 1;
			$order->update_meta_data( $key, $count );
			$order->save();
			if ( $count < self::MAX_EMAIL_RETRIES ) {
				self::queue_email_retry( $order_id, $email_id );
			} else {
				$order->update_meta_data( self::FALLBACK_META_PREFIX . sanitize_key( $email_id ), current_time( 'mysql', true ) );
				$order->save();
				wc_get_logger()->error( 'Personalized book retries exhausted; sending the campaign email with the original book as last-resort fallback.', array( 'source' => 'ykt-book-personalizer', 'order_id' => $order_id, 'email_id' => $email_id ) );
				self::trigger_email( $order_id, $order, $email_id, $map[ $email_id ] ?? $email_id );
			}
			return;
		}

		$key = '_ykt_book_email_retry_' . sanitize_key( $email_id );
		$order->delete_meta_data( $key );
		$order->save();
		self::trigger_email( $order_id, $order, $email_id, $map[ $email_id ] ?? $email_id );
	}

	/**
	 * Determine whether an email may use the original book after retries fail.
	 */
	public static function fallback_allowed( WC_Order $order, string $email_id ): bool {
		return '' !== (string) $order->get_meta( self::FALLBACK_META_PREFIX . sanitize_key( $email_id ), true );
	}

	/**
	 * Return the original book for the final delivery fallback.
	 */
	public static function fallback_book_path(): string {
		$path = YKT_PLUGIN_DIR . 'assets/book/' . self::SOURCE_FILE;
		return is_readable( $path ) ? $path : '';
	}

	private static function trigger_email( int $order_id, WC_Order $order, string $email_id, ?string $email_key = null ): void {
		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ $email_key ?? $email_id ] ?? null;
		if ( $email instanceof WC_Email && method_exists( $email, 'trigger' ) ) {
			$email->trigger( $order_id, $order );
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
	 * Log a generation failure without exposing the source PDF as an attachment.
	 */
	private static function log_failure( string $message, WC_Order $order ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'ykt-book-personalizer', 'order_id' => $order->get_id() ) );
		}
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
