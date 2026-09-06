<?php
/**
 * Module: Coming Soon Variations
 *
 * Some variable products carry a future material that is intentionally
 * listed but not yet purchasable (e.g. Cycle Necklace / Cycle Bracelet's
 * 18 KT Yellow Gold). Those variations are marked out of stock with no
 * price set — this module detects exactly that combination and shows a
 * "Coming soon" badge instead of the default "Out of stock" text, with no
 * per-product configuration needed.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_get_availability', 'lior_coming_soon_availability', 10, 2 );

function lior_coming_soon_availability( $availability, $product ) {

	if ( ! $product instanceof WC_Product || $product->is_in_stock() || $product->get_price( 'edit' ) !== '' ) {
		return $availability;
	}

	$availability['availability'] = esc_html__( 'Coming soon', 'lior-toolkit' );
	$availability['class']        = 'lior-coming-soon';

	return $availability;
}

add_action( 'wp_head', 'lior_coming_soon_badge_style' );

function lior_coming_soon_badge_style() {
	if ( ! is_product() ) {
		return;
	}
	?>
	<style>
		.stock.lior-coming-soon {
			display: inline-block;
			background: #222;
			color: #fff;
			padding: 4px 12px;
			border-radius: 3px;
			font-size: 12px;
			letter-spacing: .05em;
			text-transform: uppercase;
		}
	</style>
	<?php
}
