<?php
/**
 * Plain text campaign customer email template.
 *
 * @package YIARI_Campaign_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo esc_html( $email_heading ) . "\n\n";

if ( $order instanceof WC_Order && $order->get_meta( '_shipping_awb_number', true ) ) {
	echo esc_html__( 'Shipping details', 'yiari-campaign-toolkit' ) . "\n";
	echo esc_html__( 'Courier:', 'yiari-campaign-toolkit' ) . " " . esc_html( (string) $order->get_meta( '_shipping_courier_name', true ) ?: '-' ) . "\n";
	echo esc_html__( 'AWB:', 'yiari-campaign-toolkit' ) . " " . esc_html( (string) $order->get_meta( '_shipping_awb_number', true ) ) . "\n\n";
}

if ( $order instanceof WC_Order && in_array( strtoupper( (string) $order->get_meta( '_campaign_package_type', true ) ), array( 'B', 'MIXED' ), true ) ) {
	echo esc_html__( 'Link pesanan Paket B', 'yiari-campaign-toolkit' ) . "\n";
	echo esc_html__( 'Tracking pesanan:', 'yiari-campaign-toolkit' ) . " " . esc_url( home_url( '/tracking/?order_id=' . rawurlencode( (string) $order->get_order_number() ) ) ) . "\n";
	echo esc_html__( 'Cek status order:', 'yiari-campaign-toolkit' ) . " " . esc_url( $order->get_checkout_order_received_url() ) . "\n\n";
}

if ( $order instanceof WC_Order ) {
	printf(
		/* translators: %s: customer first name. */
		esc_html__( 'Hi %s,', 'yiari-campaign-toolkit' ),
		esc_html( $order->get_billing_first_name() ?: $order->get_formatted_billing_full_name() )
	);
	echo "\n\n";
}

foreach ( (array) $message_lines as $message_line ) {
	echo esc_html( $message_line ) . "\n\n";
}

if ( $order instanceof WC_Order ) {
	printf(
		/* translators: %s: order number. */
		esc_html__( 'Order number: %s', 'yiari-campaign-toolkit' ),
		esc_html( $order->get_order_number() )
	);
	echo "\n";
}
