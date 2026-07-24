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
