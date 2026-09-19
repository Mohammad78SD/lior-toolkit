<?php
/**
 * Module: Force Product Image Ratio (1120x1380)
 *
 * Product photography now ships at a fixed 1120x1380 (~56:69) canvas, but
 * existing WooCommerce/Woodmart markup renders images at their native size,
 * so old uploads and any future upload that isn't exactly this ratio would
 * look inconsistent side by side in the shop grid, single-product gallery
 * and related-product blocks.
 *
 * This is a display-only fix: it does not touch the uploaded files or run any
 * WP thumbnail regeneration. Every product image container is pinned to the
 * 1120x1380 box with a white background and object-fit: contain, so an old
 * image whose native ratio doesn't match this box is letterboxed on white
 * (visible top/bottom bars) instead of being cropped or stretched. An image
 * already delivered at 1120x1380 (including ones letterboxed with white
 * padding at the file level) fills the box exactly, so contain produces no
 * visible bars for those.
 *
 * Covers: shop/category loop grid (including Woodmart's on-hover swap image,
 * which is a sibling of the main image link, not a child of it), single
 * product main gallery + thumbnails, and related/upsell product blocks (same
 * Woodmart markup as the loop).
 *
 * Woodmart's own CSS already makes .wd-product-img-hover a flex container
 * with a white background, letterboxing a differently-shaped hover image
 * rather than cropping it — the same behavior we now want everywhere, so the
 * hover image just gets the same box + contain + white background treatment
 * as the main image instead of being overridden to cover.
 *
 * !important is required: Woodmart's loop markup puts "product-grid-item"
 * and "product" as co-occurring classes on the same element (not nested), so
 * a plain descendant selector targeting the card itself never matches; the
 * actual box that reliably works everywhere is .product-image-link /
 * .woocommerce-product-gallery__image, which sit at varying specificity
 * against the theme's own rules depending on template context.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', 'lior_force_product_image_ratio_css', 20 );

function lior_force_product_image_ratio_css() {
	if ( is_admin() ) {
		return;
	}
	?>
	<style id="lior-force-product-image-ratio">
		.products .product .product-image-link,
		.wd-products-slider .product-image-link,
		.woocommerce-product-gallery__wrapper .woocommerce-product-gallery__image,
		.woocommerce-product-gallery .flex-control-thumbs li,
		.wd-product-thumb .wd-product-img-hover {
			aspect-ratio: 1120 / 1380 !important;
			overflow: hidden !important;
			background-color: #fff !important;
		}

		.products .product .product-image-link img,
		.wd-products-slider .product-image-link img,
		.woocommerce-product-gallery__wrapper .woocommerce-product-gallery__image img,
		.woocommerce-product-gallery .flex-control-thumbs li img,
		.wd-product-thumb .wd-product-img-hover img {
			width: 100% !important;
			height: 100% !important;
			object-fit: contain !important;
			object-position: center center !important;
		}
	</style>
	<?php
}
