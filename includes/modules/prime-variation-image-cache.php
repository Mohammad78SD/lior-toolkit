<?php
/**
 * Module: Prime Variation Image Cache
 *
 * On variable products, Woodmart's "Advanced Variation Images" feature
 * calls wp_get_attachment_metadata() once per variation while building the
 * available-variations data (get_available_variations() -> get_available_variation()
 * -> woodmart_avi_get_image_data()). Each of those is its own uncached
 * get_post_meta() lookup — a classic N+1 query pattern — and WooCommerce's
 * own "product_objects" cache group is deliberately excluded from the
 * Redis Object Cache plugin's persistent caching (compatibility default),
 * so this runs fully cold on every single page load. Caught live on the
 * "orbit" product (24 variations) via the PHP-FPM slowlog.
 *
 * Rather than touching the Redis plugin's ignored-groups config (that
 * exclusion likely exists for good reason — price/stock data that must
 * stay fresh), this primes WordPress's own meta cache in two batched
 * queries before WooCommerce's variation loop runs, so each individual
 * get_post_meta() call inside the loop hits an already-warm cache instead
 * of issuing its own query. Image metadata (dimensions/sizes) is static
 * once generated, so caching it is safe regardless of the ignored group.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_before_single_product', 'lior_prime_variation_image_cache' );

function lior_prime_variation_image_cache() {
	global $product;

	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
		return;
	}

	$variation_ids = $product->get_children();

	if ( empty( $variation_ids ) ) {
		return;
	}

	// Batch 1: warm postmeta for every variation (_thumbnail_id, _product_image_gallery, etc.) in one query.
	update_meta_cache( 'post', $variation_ids );

	// Collect the attachment IDs those variations actually reference.
	$attachment_ids = array();

	foreach ( $variation_ids as $variation_id ) {
		$thumbnail_id = get_post_meta( $variation_id, '_thumbnail_id', true );

		if ( $thumbnail_id ) {
			$attachment_ids[] = (int) $thumbnail_id;
		}

		$gallery = get_post_meta( $variation_id, '_product_image_gallery', true );

		if ( $gallery ) {
			foreach ( array_filter( explode( ',', $gallery ) ) as $gallery_id ) {
				$attachment_ids[] = (int) $gallery_id;
			}
		}
	}

	$attachment_ids = array_unique( array_filter( $attachment_ids ) );

	if ( empty( $attachment_ids ) ) {
		return;
	}

	// Batch 2: warm postmeta for every referenced attachment (_wp_attachment_metadata) in one query.
	update_meta_cache( 'post', $attachment_ids );
}
