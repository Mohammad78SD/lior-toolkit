<?php
/**
 * Module: Admin Order Email Link
 *
 * Adds a direct link to the WP-admin order edit screen inside the
 * admin-facing WooCommerce order emails (New order, Cancelled order,
 * Failed order). Customer-facing emails are left untouched.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_email_before_order_table', 'lior_admin_order_email_link', 5, 4 );

/**
 * Print a "view / edit this order" link into admin-facing WooCommerce emails only.
 *
 * Uses WC_Order::get_edit_order_url(), which resolves to the right admin URL
 * whether the store is on legacy post-based order storage or the newer HPOS
 * (High-Performance Order Storage) — no need to hardcode admin.php vs post.php.
 */
function lior_admin_order_email_link( $order, $sent_to_admin, $plain_text, $email ) {

	if ( ! $sent_to_admin || ! $order || ! is_a( $order, 'WC_Order' ) ) {
		return;
	}

	// Restrict to the admin-facing email types. Add to this array if the link
	// should also appear on other admin emails (e.g. 'customer_note' is NOT
	// admin-facing despite the name, so it's deliberately left out).
	$admin_email_ids = array( 'new_order', 'cancelled_order', 'failed_order' );
	if ( $email && ! in_array( $email->id, $admin_email_ids, true ) ) {
		return;
	}

	$edit_url = $order->get_edit_order_url();

	if ( ! $edit_url ) {
		return;
	}

	if ( $plain_text ) {
		echo "\n" . esc_html__( 'View / edit this order:', 'lior-toolkit' ) . ' ' . esc_url_raw( $edit_url ) . "\n\n";
	} else {
		echo '<p style="margin: 0 0 16px;">'
			. '<a href="' . esc_url( $edit_url ) . '" style="font-weight: bold;">'
			. esc_html__( '→ View / edit this order in the admin panel', 'lior-toolkit' )
			. '</a></p>';
	}
}
