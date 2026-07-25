<?php
/**
 * Campaign customer email template.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

<?php foreach ( (array) $message_lines as $message_line ) : ?>
	<p><?php echo esc_html( $message_line ); ?></p>
<?php endforeach; ?>

<?php if ( $order instanceof WC_Order && $order->get_meta( '_shipping_awb_number', true ) ) : ?>
	<p>
		<strong><?php esc_html_e( 'Shipping details', 'yiari-campaign-toolkit' ); ?></strong><br>
		<?php esc_html_e( 'Courier:', 'yiari-campaign-toolkit' ); ?> <?php echo esc_html( (string) $order->get_meta( '_shipping_courier_name', true ) ?: '-' ); ?><br>
		<?php esc_html_e( 'AWB:', 'yiari-campaign-toolkit' ); ?> <?php echo esc_html( (string) $order->get_meta( '_shipping_awb_number', true ) ); ?>
	</p>
<?php endif; ?>

<?php if ( $order instanceof WC_Order ) : ?>
	<p>
		<?php
		printf(
			/* translators: %s: order number. */
			esc_html__( 'Order number: %s', 'yiari-campaign-toolkit' ),
			esc_html( $order->get_order_number() )
		);
		?>
	</p>
<?php endif; ?>

<?php

do_action( 'woocommerce_email_footer', $email );
