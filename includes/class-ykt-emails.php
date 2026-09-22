<?php
/**
 * Campaign WooCommerce email integration.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and triggers campaign lifecycle customer emails.
 */
class YKT_Emails {
	/**
	 * Built-in customer email currently being rendered for a campaign order.
	 *
	 * @var WC_Email|null
	 */
	private ?WC_Email $active_campaign_customer_email = null;

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_email_classes' ) );
		add_filter( 'woocommerce_email_attachments', array( $this, 'personalize_campaign_book_attachment' ), 20, 3 );
		add_filter( 'woocommerce_email_subject_customer_processing_order', array( $this, 'translate_customer_email_subject' ), 20, 3 );
		add_filter( 'woocommerce_email_subject_customer_on_hold_order', array( $this, 'translate_customer_email_subject' ), 20, 3 );
		add_filter( 'woocommerce_email_subject_customer_completed_order', array( $this, 'translate_customer_email_subject' ), 20, 3 );
		add_filter( 'woocommerce_email_heading_customer_processing_order', array( $this, 'translate_customer_email_heading' ), 20, 3 );
		add_filter( 'woocommerce_email_heading_customer_on_hold_order', array( $this, 'translate_customer_email_heading' ), 20, 3 );
		add_filter( 'woocommerce_email_heading_customer_completed_order', array( $this, 'translate_customer_email_heading' ), 20, 3 );
		add_filter( 'woocommerce_email_additional_content_customer_processing_order', array( $this, 'translate_customer_email_additional_content' ), 20, 3 );
		add_filter( 'woocommerce_email_additional_content_customer_on_hold_order', array( $this, 'translate_customer_email_additional_content' ), 20, 3 );
		add_filter( 'woocommerce_email_additional_content_customer_completed_order', array( $this, 'translate_customer_email_additional_content' ), 20, 3 );
		add_filter( 'gettext_woocommerce', array( $this, 'translate_active_customer_email_text' ), 20, 3 );
		add_action( 'woocommerce_email_sent', array( $this, 'clear_active_customer_email' ), 20, 3 );
		add_filter( 'woocommerce_email_from_address', array( $this, 'support_email_address' ) );
		add_filter( 'woocommerce_email_from_name', array( $this, 'support_email_name' ), 20, 2 );
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( $this, 'allow_campaign_customer_email' ), 20, 2 );
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order', array( $this, 'allow_campaign_customer_email' ), 20, 2 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( $this, 'allow_campaign_customer_email' ), 20, 2 );
		add_filter( 'woocommerce_email_footer_text', array( $this, 'replace_footer_contact_email' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'trigger_for_status' ), 50, 4 );
		$this->register_loaded_mailer();
	}

	/**
	 * Translate built-in WooCommerce customer email subjects for campaign orders.
	 *
	 * @param string       $subject Existing subject.
	 * @param WC_Order|bool $order Order object.
	 * @param WC_Email     $email Email object.
	 * @return string
	 */
	public function translate_customer_email_subject( string $subject, $order, $email ): string {
		if ( ! $this->is_campaign_customer_email( $order, $email ) ) {
			return $subject;
		}

		$this->active_campaign_customer_email = $email;
		$subjects = array(
			'customer_processing_order' => sprintf( 'Pesanan %s Anda telah diterima!', get_bloginfo( 'name' ) ),
			'customer_on_hold_order'    => sprintf( 'Pesanan Anda sedang menunggu pembayaran - %s', get_bloginfo( 'name' ) ),
			'customer_completed_order'  => sprintf( 'Pesanan %s Anda telah selesai', get_bloginfo( 'name' ) ),
		);

		return $subjects[ $email->id ] ?? $subject;
	}

	/**
	 * Translate built-in WooCommerce customer email headings for campaign orders.
	 *
	 * @param string       $heading Existing heading.
	 * @param WC_Order|bool $order Order object.
	 * @param WC_Email     $email Email object.
	 * @return string
	 */
	public function translate_customer_email_heading( string $heading, $order, $email ): string {
		if ( ! $this->is_campaign_customer_email( $order, $email ) ) {
			return $heading;
		}

		$this->active_campaign_customer_email = $email;
		$headings = array(
			'customer_processing_order' => 'Terima kasih atas pesanan Anda',
			'customer_on_hold_order'    => 'Pesanan Anda sedang menunggu pembayaran',
			'customer_completed_order'  => 'Pesanan Anda telah selesai diproses',
		);

		return $headings[ $email->id ] ?? $heading;
	}

	/**
	 * Replace configurable WooCommerce additional content with Indonesian copy.
	 *
	 * @param string       $content Existing additional content.
	 * @param WC_Order|bool $order Order object.
	 * @param WC_Email     $email Email object.
	 * @return string
	 */
	public function translate_customer_email_additional_content( string $content, $order, $email ): string {
		if ( ! $this->is_campaign_customer_email( $order, $email ) ) {
			return $content;
		}

		$this->active_campaign_customer_email = $email;
		$contents = array(
			'customer_processing_order' => sprintf( 'Terima kasih telah mendukung kampanye Petualangan Karmila & Gito. Jika membutuhkan bantuan terkait pesanan, silakan hubungi kami melalui email %s.', $this->support_email_address() ),
			'customer_on_hold_order'    => 'Kami akan mengirimkan pembaruan berikutnya setelah pembayaran Anda terkonfirmasi.',
			'customer_completed_order'  => 'Terima kasih telah mendukung kampanye Petualangan Karmila & Gito.',
		);

		return $contents[ $email->id ] ?? $content;
	}

	/**
	 * Translate common WooCommerce strings while a campaign customer email renders.
	 *
	 * @param string $translation Translated string.
	 * @param string $text Original string.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public function translate_active_customer_email_text( string $translation, string $text, string $domain ): string {
		$email = $this->active_campaign_customer_email;
		if ( 'woocommerce' !== $domain || ! $email instanceof WC_Email || ! $this->is_campaign_customer_email( $email->object, $email ) ) {
			return $translation;
		}

		$translations = array(
			'Hi %s,' => 'Halo %s,',
			'Hi,' => 'Halo,',
			'Just to let you know &mdash; we\'ve received your order #%s, and it is now being processed:' => 'Pesanan #%s Anda telah kami terima dan sedang diproses.',
			'Just to let you know &mdash; we’ve received your order, and it is now being processed.' => 'Pesanan Anda telah kami terima dan sedang diproses.',
			'Here’s a reminder of what you’ve ordered:' => 'Berikut ringkasan pesanan Anda:',
			'Thanks for your order. It’s on-hold until we confirm that payment has been received.' => 'Terima kasih atas pesanan Anda. Pesanan ini menunggu konfirmasi pembayaran.',
			'We’ve received your order and it’s currently on hold until we can confirm your payment has been processed.' => 'Pesanan Anda telah kami terima dan saat ini menunggu konfirmasi pembayaran.',
			'We have finished processing your order.' => 'Pesanan Anda telah selesai diproses.',
			'Product' => 'Produk',
			'Quantity' => 'Jumlah',
			'Price' => 'Harga',
			'Order summary' => 'Ringkasan pesanan',
			'[Order #%s]' => '[Pesanan #%s]',
			'Order #%s' => 'Pesanan #%s',
			'Order #%1$s' => 'Pesanan #%1$s',
			'[Order #%1$s]' => '[Pesanan #%1$s]',
			'Customer details' => 'Detail donatur',
			'Order details' => 'Detail pesanan',
			'Name' => 'Nama',
			'Email address' => 'Alamat email',
			'Phone' => 'Telepon',
			'Address' => 'Alamat',
			'City' => 'Kota',
			'State' => 'Provinsi',
			'Postcode' => 'Kode pos',
			'Country' => 'Negara',
			'Billing address' => 'Alamat penagihan',
			'Shipping address' => 'Alamat pengiriman',
			'Shipping' => 'Pengiriman',
			'Payment method' => 'Metode pembayaran',
			'Subtotal' => 'Subtotal',
			'Total' => 'Total',
			'Note:' => 'Catatan:',
			'Free!' => 'Gratis!',
			'Thanks for using {site_url}!' => 'Terima kasih telah menggunakan {site_url}!',
			'Thanks for shopping with us' => 'Terima kasih telah berbelanja bersama kami',
			'Thanks for shopping with us.' => 'Terima kasih telah berbelanja bersama kami.',
			'Thanks again! If you need any help with your order, please contact us at {store_email}.' => 'Terima kasih. Jika membutuhkan bantuan terkait pesanan, silakan hubungi kami melalui email {store_email}.',
		);

		return $translations[ $text ] ?? $translation;
	}

	/**
	 * Clear the translation context after a WooCommerce email is dispatched.
	 *
	 * @param bool     $sent Whether the email was sent.
	 * @param string   $email_id Email ID.
	 * @param WC_Email $email Email object.
	 */
	public function clear_active_customer_email( bool $sent, string $email_id, $email ): void {
		unset( $sent, $email_id );
		if ( $email === $this->active_campaign_customer_email ) {
			$this->active_campaign_customer_email = null;
		}
	}

	/**
	 * Determine whether a built-in customer email belongs to a campaign order.
	 *
	 * @param mixed    $order Order object.
	 * @param mixed    $email Email object.
	 */
	private function is_campaign_customer_email( $order, $email ): bool {
		return $email instanceof WC_Email
			&& in_array( $email->id, array( 'customer_processing_order', 'customer_on_hold_order', 'customer_completed_order' ), true )
			&& $order instanceof WC_Order
			&& class_exists( 'YKT_Checkout' )
			&& YKT_Checkout::order_has_campaign_package( $order );
	}

	/**
	 * Ensure campaign customer emails use the donor-personalized digital book.
	 *
	 * Payment gateways may trigger a built-in WooCommerce customer email instead
	 * of the campaign email, so normalize the attachment at the common filter.
	 *
	 * @param array<int, string> $attachments Existing attachment paths.
	 * @param string             $email_id WooCommerce email identifier.
	 * @param mixed              $object Email object, usually a WC_Order.
	 * @return array<int, string>
	 */
	/**
	 * Hold built-in campaign emails until the personalized book is ready.
	 */
	public function allow_campaign_customer_email( bool $enabled, $order ): bool {
		// The standard WooCommerce order-confirmation email never carries the
		// campaign book. The campaign email below is the only delivery channel
		// responsible for the certificate and personalized book.
		return $enabled;
	}

	public function personalize_campaign_book_attachment( array $attachments, string $email_id, $object ): array {
		$allowed_email_ids = array( 'ykt_campaign_paid' );
		if ( ! in_array( $email_id, $allowed_email_ids, true ) || ! $object instanceof WC_Order ) {
			return $attachments;
		}
		if ( ! class_exists( 'YKT_Checkout' ) || ! YKT_Checkout::order_has_campaign_package( $object ) ) {
			return $attachments;
		}

		$generic_book = wp_normalize_path( YKT_Book_Personalizer::fallback_book_path() );

		$has_personalized_book = false;
		foreach ( $attachments as $attachment ) {
			if ( 0 === strpos( basename( (string) $attachment ), 'ykt-' ) && file_exists( (string) $attachment ) ) {
				$has_personalized_book = true;
				break;
			}
		}

		if ( ! $has_personalized_book ) {
			$personalized_book = YKT_Book_Personalizer::create_for_order( $object );
			$attachments = array_values( array_filter( $attachments, static function ( $attachment ) use ( $generic_book ): bool {
				return wp_normalize_path( (string) $attachment ) !== $generic_book;
			} ) );
			if ( $personalized_book && file_exists( $personalized_book ) ) {
				$attachments[] = $personalized_book;
			} elseif ( YKT_Book_Personalizer::fallback_allowed( $object, $email_id ) && $generic_book && file_exists( $generic_book ) ) {
				$attachments[] = $generic_book;
			} else {
				YKT_Book_Personalizer::queue_email_retry( $object->get_id(), $email_id );
			}
		}

		return array_values( array_unique( $attachments ) );
	}

	/**
	 * Use YIARI's donation mailbox for campaign email sender and contact text.
	 */
	public function support_email_address(): string {
		return 'donasi@yiari.id';
	}

	/**
	 * Set a recognizable sender name for campaign emails only.
	 *
	 * @param string       $from_name Existing sender name.
	 * @param WC_Email|null $email Email object.
	 */
	public function support_email_name( string $from_name, $email = null ): string {
		$campaign_email_ids = array(
			'ykt_campaign_paid',
			'ykt_campaign_shipped',
			'ykt_campaign_delivered',
			'ykt_campaign_impact',
			'customer_processing_order',
			'customer_on_hold_order',
			'customer_completed_order',
			'customer_invoice',
		);

		if ( $email instanceof WC_Email && in_array( $email->id, $campaign_email_ids, true ) ) {
			return 'Donasi Buku YIARI';
		}

		return $from_name;
	}

	/**
	 * Replace legacy contact email references in WooCommerce email footer text.
	 *
	 * @param string $footer_text Existing footer text.
	 */
	public function replace_footer_contact_email( string $footer_text ): string {
		return str_replace( 'julian@mcimedia.net', $this->support_email_address(), $footer_text );
	}

	/**
	 * Add campaign emails to an already constructed WooCommerce mailer.
	 */
	private function register_loaded_mailer(): void {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return;
		}

		$mailer = WC()->mailer();
		if ( ! $mailer instanceof WC_Emails || ! is_array( $mailer->emails ) ) {
			return;
		}

		$mailer->emails = $this->register_email_classes( $mailer->emails );
	}

	/**
	 * Add campaign emails to WooCommerce mailer.
	 *
	 * @param array<string, WC_Email> $emails Registered WooCommerce emails.
	 * @return array<string, WC_Email>
	 */
	public function register_email_classes( array $emails ): array {
		$emails['YKT_Email_Campaign_Paid']      = new YKT_Email_Campaign_Paid();
		$emails['YKT_Email_Campaign_Shipped']   = new YKT_Email_Campaign_Shipped();
		$emails['YKT_Email_Campaign_Delivered'] = new YKT_Email_Campaign_Delivered();
		$emails['YKT_Email_Campaign_Impact']    = new YKT_Email_Campaign_Impact();

		return $emails;
	}

	/**
	 * Trigger the matching email when a campaign status is reached.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from Previous status without wc-.
	 * @param string   $to New status without wc-.
	 * @param WC_Order $order Order object.
	 */
	public function trigger_for_status( int $order_id, string $from, string $to, $order ): void {
		unset( $from );

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order || ! class_exists( 'YKT_Checkout' ) || ! YKT_Checkout::order_has_campaign_package( $order ) ) {
			return;
		}

		$email_key = $this->email_key_for_status( $to );
		if ( ! $email_key ) {
			return;
		}

		$mailer = WC()->mailer();
		$emails = $mailer ? $mailer->get_emails() : array();
		$email  = $emails[ $email_key ] ?? null;
		if ( ! $email instanceof WC_Email ) {
			$email = $this->email_instance_for_key( $email_key );
		}

		if ( $email && method_exists( $email, 'trigger' ) ) {
			$email->trigger( $order_id, $order );
		}
	}

	/**
	 * Build an email instance if the WooCommerce mailer was initialized before our filter.
	 *
	 * @param string $email_key Registered email key.
	 */
	private function email_instance_for_key( string $email_key ): ?WC_Email {
		$map = array(
			'YKT_Email_Campaign_Paid'      => YKT_Email_Campaign_Paid::class,
			'YKT_Email_Campaign_Shipped'   => YKT_Email_Campaign_Shipped::class,
			'YKT_Email_Campaign_Delivered' => YKT_Email_Campaign_Delivered::class,
			'YKT_Email_Campaign_Impact'    => YKT_Email_Campaign_Impact::class,
		);

		if ( empty( $map[ $email_key ] ) ) {
			return null;
		}

		$class_name = $map[ $email_key ];
		return new $class_name();
	}

	/**
	 * Map order status slugs to registered email keys.
	 *
	 * @param string $status WooCommerce status without wc-.
	 */
	private function email_key_for_status( string $status ): string {
		$map = array(
			'paid'        => 'YKT_Email_Campaign_Paid',
			'shipped'     => 'YKT_Email_Campaign_Shipped',
			'delivered'   => 'YKT_Email_Campaign_Delivered',
			'impact-sent' => 'YKT_Email_Campaign_Impact',
		);

		return $map[ $status ] ?? '';
	}
}

/**
 * Shared base class for campaign customer emails.
 */
abstract class YKT_Email_Campaign_Base extends WC_Email {
	/**
	 * Campaign message lines passed into the email templates.
	 *
	 * @var array<int, string>
	 */
	protected array $message_lines = array();

	/**
	 * Trigger the email for one order.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order Order object.
	 */
	public function trigger( int $order_id, $order = null ): void {
		$this->setup_locale();

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( $order instanceof WC_Order ) {
			$this->object    = $order;
			$this->recipient = $order->get_billing_email();
			if ( method_exists( $this, 'message_lines_for_order' ) ) {
				$this->message_lines = $this->message_lines_for_order( $order );
			}
		}

		if ( $order instanceof WC_Order && class_exists( 'YKT_Checkout' ) && YKT_Checkout::order_has_campaign_package( $order ) ) {
			$book = YKT_Book_Personalizer::create_for_order( $order );
			if ( ( ! $book || ! file_exists( $book ) ) && ! YKT_Book_Personalizer::fallback_allowed( $order, $this->id ) ) {
				YKT_Book_Personalizer::queue_email_retry( $order_id, $this->id );
				$this->restore_locale();
				return;
			}
		}

		$attachments = $this->get_attachments();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $attachments );
		}

		$this->restore_locale();
	}

	/**
	 * Render HTML content through WooCommerce's email wrapper hooks.
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'message_lines' => $this->message_lines,
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Render plain text content.
	 */
	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'message_lines' => $this->message_lines,
				'sent_to_admin' => false,
				'plain_text'    => true,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Configure common template paths.
	 */
	protected function configure_templates(): void {
		$this->customer_email = true;
		$this->template_base  = YKT_PLUGIN_DIR . 'templates/';
		$this->template_html  = 'emails/ykt-campaign-email.php';
		$this->template_plain = 'emails/plain/ykt-campaign-email.php';
	}
}

/**
 * Paid campaign email with certificate attachment.
 */
class YKT_Email_Campaign_Paid extends YKT_Email_Campaign_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'ykt_campaign_paid';
		$this->title          = __( 'YIARI Campaign - Payment Confirmed', 'yiari-campaign-toolkit' );
		$this->description    = __( 'Sent after a campaign order is paid. Includes the donor certificate when available.', 'yiari-campaign-toolkit' );
		$this->heading        = __( 'Mari Berpetualang bersama Karmila dan Gito!', 'yiari-campaign-toolkit' );
		$this->subject        = __( 'Mari Berpetualang bersama Karmila dan Gito!', 'yiari-campaign-toolkit' );
		$this->message_lines  = array(
			__( 'Terima kasih telah menjadi bagian dari perjalanan “Petualangan Karmila & Gito, Menyelamatkan Orangutan” melalui dukunganmu untuk proses pencetakan dan distribusi buku ini.', 'yiari-campaign-toolkit' ),
			__( 'Dukunganmu membantu kami membawa cerita tentang orangutan dan upaya pelestariannya lebih dekat kepada anak-anak dan keluarga, termasuk anak-anak yang tinggal di sekitar habitat orangutan.', 'yiari-campaign-toolkit' ),
			__( 'Berikut informasi pemesananmu:', 'yiari-campaign-toolkit' ),
			__( 'Saat ini, buku sedang dalam proses menuju tahap pencetakan. Kami akan mengabari kamu kembali melalui email ketika buku sudah selesai dicetak dan siap untuk didistribusikan.', 'yiari-campaign-toolkit' ),
			__( 'Sambil menunggu Karmila dan Gito sampai ke tanganmu, sebagai bentuk apresiasi atas dukunganmu, kami juga melampirkan versi digital buku “Petualangan Karmila & Gito, Menyelamatkan Orangutan” spesial untuk kamu.', 'yiari-campaign-toolkit' ),
			__( 'Selamat membaca dan berpetualang bersama Karmila dan Gito. Semoga cerita ini bisa ikut dibagikan kepada keluarga, teman, dan orang-orang terdekatmu, agar semakin banyak yang mengenal dan peduli terhadap orangutan serta rumah mereka di alam.', 'yiari-campaign-toolkit' ),
			__( 'Terima kasih sudah ikut menyebarkan kebaikan untuk alam dan satwa liar. 🌿', 'yiari-campaign-toolkit' ),
			__( 'Salam lestari,', 'yiari-campaign-toolkit' ),
			__( 'Tim Edukasi YIARI', 'yiari-campaign-toolkit' ),
			__( 'Yayasan Inisiasi Alam Rehabilitasi Indonesia', 'yiari-campaign-toolkit' ),
		);
		$this->configure_templates();
		parent::__construct();
	}

	/**
	 * Attach the generated certificate PDF to the paid email.
	 *
	 * @return array<int, string>
	 */
	public function get_attachments(): array {
		$attachments = parent::get_attachments();
		if ( $this->object instanceof WC_Order && class_exists( 'YKT_Certificate' ) ) {
			$path = YKT_Certificate::absolute_certificate_path( (string) $this->object->get_meta( '_certificate_path', true ) );
			if ( $path && file_exists( $path ) ) {
				$attachments[] = $path;
			}
		}

		// Keep the book attached even when another email integration bypasses or
		// replaces the common woocommerce_email_attachments filter.
		if ( $this->object instanceof WC_Order && class_exists( 'YKT_Book_Personalizer' ) ) {
			$has_personalized_book = false;
			foreach ( $attachments as $attachment ) {
				if ( 0 === strpos( basename( (string) $attachment ), 'ykt-' ) && file_exists( (string) $attachment ) ) {
					$has_personalized_book = true;
					break;
				}
			}

			if ( ! $has_personalized_book ) {
				$personalized_book = YKT_Book_Personalizer::create_for_order( $this->object );
				if ( $personalized_book && file_exists( $personalized_book ) && ! in_array( $personalized_book, $attachments, true ) ) {
					$attachments[] = $personalized_book;
				} elseif ( YKT_Book_Personalizer::fallback_allowed( $this->object, $this->id ) ) {
					$fallback_book = YKT_Book_Personalizer::fallback_book_path();
					if ( $fallback_book && ! in_array( $fallback_book, $attachments, true ) ) {
						$attachments[] = $fallback_book;
					}
				}
			}
		}

		if ( $this->object instanceof WC_Order ) {
			wc_get_logger()->info(
				'Campaign paid email attachments: ' . wp_json_encode( array_map( 'basename', $attachments ) ),
				array( 'source' => 'yiari-campaign-toolkit', 'order_id' => $this->object->get_id() )
			);
		}

		return $attachments;
	}
}

/**
 * Shipping campaign email.
 */
class YKT_Email_Campaign_Shipped extends YKT_Email_Campaign_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id            = 'ykt_campaign_shipped';
		$this->title         = __( 'YIARI Campaign - Pesanan Dikirim', 'yiari-campaign-toolkit' );
		$this->description   = __( 'Dikirim saat pesanan campaign mulai dikirim.', 'yiari-campaign-toolkit' );
		$this->heading       = __( 'Pesanan Anda sedang dikirim', 'yiari-campaign-toolkit' );
		$this->subject       = __( 'Pesanan Anda sedang dikirim - Yayasan IAR Indonesia', 'yiari-campaign-toolkit' );
		$this->message_lines = array( __( 'Pesanan Anda sudah dikirim. Anda dapat memantau status pengiriman melalui tautan pelacakan di bawah ini.', 'yiari-campaign-toolkit' ) );
		$this->configure_templates();
		parent::__construct();
	}
}

/**
 * Delivered campaign email.
 */
class YKT_Email_Campaign_Delivered extends YKT_Email_Campaign_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id            = 'ykt_campaign_delivered';
		$this->title         = __( 'YIARI Campaign - Pesanan Diterima', 'yiari-campaign-toolkit' );
		$this->description   = __( 'Dikirim saat pesanan campaign telah diterima.', 'yiari-campaign-toolkit' );
		$this->heading       = __( 'Pesanan Anda telah diterima', 'yiari-campaign-toolkit' );
		$this->subject       = __( 'Pesanan Anda telah diterima - Yayasan IAR Indonesia', 'yiari-campaign-toolkit' );
		$this->message_lines = array( __( 'Kami mengonfirmasi bahwa pesanan Anda telah diterima. Terima kasih telah mendukung kampanye Petualangan Karmila & Gito.', 'yiari-campaign-toolkit' ) );
		$this->configure_templates();
		parent::__construct();
	}
}

/**
 * Impact report campaign email.
 */
class YKT_Email_Campaign_Impact extends YKT_Email_Campaign_Base {
	/**
	 * Build impact email copy with optional broadcast content.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<int, string>
	 */
	protected function message_lines_for_order( WC_Order $order ): array {
		$lines = $this->message_lines;
		$message = trim( (string) $order->get_meta( '_impact_update_message', true ) );
		if ( '' !== $message ) {
			$lines[] = $message;
		}

		return $lines;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id            = 'ykt_campaign_impact';
		$this->title         = __( 'YIARI Campaign - Laporan Dampak', 'yiari-campaign-toolkit' );
		$this->description   = __( 'Dikirim saat laporan dampak campaign dibagikan.', 'yiari-campaign-toolkit' );
		$this->heading       = __( 'Kabar terbaru dari kampanye YIARI', 'yiari-campaign-toolkit' );
		$this->subject       = __( 'Kabar terbaru dari kampanye YIARI', 'yiari-campaign-toolkit' );
		$this->message_lines = array( __( 'Kami ingin berbagi kabar terbaru mengenai dampak dukungan Anda untuk kampanye Petualangan Karmila & Gito.', 'yiari-campaign-toolkit' ) );
		$this->configure_templates();
		parent::__construct();
	}
}
