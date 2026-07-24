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
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_email_classes' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'trigger_for_status' ), 50, 4 );
		$this->register_loaded_mailer();
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
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
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
		$this->heading        = __( 'Thank you for supporting Karmila & Gito', 'yiari-campaign-toolkit' );
		$this->subject        = __( 'Your YIARI campaign support is confirmed', 'yiari-campaign-toolkit' );
		$this->message_lines  = array(
			__( 'We have received your payment and recorded your support for the Karmila & Gito book campaign.', 'yiari-campaign-toolkit' ),
			__( 'Your campaign certificate is attached to this email when generation succeeds.', 'yiari-campaign-toolkit' ),
			__( 'If your package includes shipping, we will send another update when the book is on its way.', 'yiari-campaign-toolkit' ),
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
		$this->title         = __( 'YIARI Campaign - Shipped', 'yiari-campaign-toolkit' );
		$this->description   = __( 'Sent when a campaign order reaches shipped status.', 'yiari-campaign-toolkit' );
		$this->heading       = __( 'Your YIARI package is on its way', 'yiari-campaign-toolkit' );
		$this->subject       = __( 'Your YIARI campaign package has shipped', 'yiari-campaign-toolkit' );
		$this->message_lines = array(
			__( 'Your campaign package has been handed to the courier.', 'yiari-campaign-toolkit' ),
			__( 'Tracking details are shown in your order notes when they are available from the shipping integration.', 'yiari-campaign-toolkit' ),
		);
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
		$this->title         = __( 'YIARI Campaign - Delivered', 'yiari-campaign-toolkit' );
		$this->description   = __( 'Sent when a campaign order reaches delivered status.', 'yiari-campaign-toolkit' );
		$this->heading       = __( 'Thank you, your package has arrived', 'yiari-campaign-toolkit' );
		$this->subject       = __( 'Your YIARI campaign package has arrived', 'yiari-campaign-toolkit' );
		$this->message_lines = array(
			__( 'Thank you for taking part in this campaign and helping books reach more readers.', 'yiari-campaign-toolkit' ),
			__( 'We appreciate your support and will keep you updated about the campaign impact when the report is ready.', 'yiari-campaign-toolkit' ),
		);
		$this->configure_templates();
		parent::__construct();
	}
}

/**
 * Impact report campaign email.
 */
class YKT_Email_Campaign_Impact extends YKT_Email_Campaign_Base {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id            = 'ykt_campaign_impact';
		$this->title         = __( 'YIARI Campaign - Impact Report', 'yiari-campaign-toolkit' );
		$this->description   = __( 'Sent when the campaign impact report has been sent.', 'yiari-campaign-toolkit' );
		$this->heading       = __( 'Your campaign impact update', 'yiari-campaign-toolkit' );
		$this->subject       = __( 'YIARI campaign impact update', 'yiari-campaign-toolkit' );
		$this->message_lines = array(
			__( 'The campaign impact report is now available.', 'yiari-campaign-toolkit' ),
			__( 'Thank you for helping YIARI create meaningful conservation education resources.', 'yiari-campaign-toolkit' ),
		);
		$this->configure_templates();
		parent::__construct();
	}
}
