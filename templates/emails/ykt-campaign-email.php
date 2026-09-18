<?php
/**
 * Campaign customer email template.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_paid_email       = $email instanceof WC_Email && 'ykt_campaign_paid' === $email->id;
$is_shipping_package = $order instanceof WC_Order && in_array( strtoupper( (string) $order->get_meta( '_campaign_package_type', true ) ), array( 'B', 'MIXED' ), true );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php if ( $order instanceof WC_Order ) : ?>
	<p>
		<?php
		printf(
			/* translators: %s: customer first name. */
			esc_html__( 'Hi %s,', 'yiari-campaign-toolkit' ),
			esc_html( $order->get_billing_first_name() ?: $order->get_formatted_billing_full_name() )
		);
		?>
	</p>
<?php endif; ?>

<?php foreach ( (array) $message_lines as $message_index => $message_line ) : ?>
	<p><?php echo esc_html( $message_line ); ?></p>
	<?php if ( $is_paid_email && 2 === $message_index && $order instanceof WC_Order ) : ?>
		<p>
			<strong><?php esc_html_e( 'Nomor Order:', 'yiari-campaign-toolkit' ); ?></strong>
			<?php echo esc_html( $order->get_order_number() ); ?>
			<?php if ( $is_shipping_package ) : ?>
				<br>
				<strong><?php esc_html_e( 'Pelacakan Pesanan:', 'yiari-campaign-toolkit' ); ?></strong>
				<a href="<?php echo esc_url( home_url( '/tracking/?order_id=' . rawurlencode( (string) $order->get_order_number() ) ) ); ?>"><?php echo esc_html( home_url( '/tracking/?order_id=' . rawurlencode( (string) $order->get_order_number() ) ) ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
<?php endforeach; ?>

<?php if ( ! $is_paid_email && $order instanceof WC_Order && $order->get_meta( '_shipping_awb_number', true ) ) : ?>
	<p>
		<strong><?php esc_html_e( 'Shipping details', 'yiari-campaign-toolkit' ); ?></strong><br>
		<?php esc_html_e( 'Courier:', 'yiari-campaign-toolkit' ); ?> <?php echo esc_html( (string) $order->get_meta( '_shipping_courier_name', true ) ?: '-' ); ?><br>
		<?php esc_html_e( 'AWB:', 'yiari-campaign-toolkit' ); ?> <?php echo esc_html( (string) $order->get_meta( '_shipping_awb_number', true ) ); ?>
	</p>
<?php endif; ?>

<?php if ( ! $is_paid_email && $is_shipping_package ) : ?>
	<p>
		<strong><?php esc_html_e( 'Link pesanan Paket B', 'yiari-campaign-toolkit' ); ?></strong><br>
		<a href="<?php echo esc_url( home_url( '/tracking/?order_id=' . rawurlencode( (string) $order->get_order_number() ) ) ); ?>"><?php esc_html_e( 'Tracking pesanan', 'yiari-campaign-toolkit' ); ?></a><br>
		<a href="<?php echo esc_url( $order->get_checkout_order_received_url() ); ?>"><?php esc_html_e( 'Cek status order', 'yiari-campaign-toolkit' ); ?></a>
	</p>
<?php endif; ?>

<?php if ( ! $is_paid_email && $order instanceof WC_Order ) : ?>
	<p>
		<?php
		printf(
			/* translators: %s: order number. */
			esc_html__( 'Nomor Order: %s', 'yiari-campaign-toolkit' ),
			esc_html( $order->get_order_number() )
		);
		?>
	</p>
<?php endif; ?>

<?php

do_action( 'woocommerce_email_footer', $email );
