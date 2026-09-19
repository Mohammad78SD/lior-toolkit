<?php
/**
 * Module: Show Both Real & Model Images on Mobile Shop Page
 *
 * On desktop, Woodmart shows the "real" product image and swaps to the "model"
 * image on hover via .wd-product-img-hover. On mobile there is no hover, so
 * only the first image is visible. This module makes both images visible on
 * mobile by stacking them vertically inside the product card.
 *
 * Both images keep the 1120x1380 aspect-ratio + object-fit: contain treatment
 * from force-product-image-ratio.php so they letterbox consistently on white.
 *
 * Client feedback round 1: the two stacked images read as two different
 * products. Tried a shared background + padding around the pair to bind them
 * visually — broke the wishlist/quickview icon positions, which are anchored
 * inside this same container and assume the theme's original box model, and
 * still felt like two products because each image already renders as its own
 * white boxed tile (aspect-ratio + white background from
 * force-product-image-ratio.php), so two boxed tiles stacked just reads as
 * two small cards regardless of what wraps them.
 *
 * Client feedback round 2: dropped the background/padding (fixes the icons).
 * Instead: the two images sit almost flush (near-zero gap) with a hairline
 * divider between them so they read as one continuous strip, not two boxes;
 * the gap between *different* product cards is pushed wider so proximity
 * itself signals which images belong together; and the "On model" label on
 * the second image stays as the explicit fallback.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', 'lior_mobile_show_both_product_images_css', 25 );

function lior_mobile_show_both_product_images_css() {
	if ( is_admin() ) {
		return;
	}
	?>
	<style id="lior-mobile-both-product-images">
		@media (max-width: 768px) {
			/* Make product card image container a vertical stack. No
			   background/padding here — that box also holds the theme's
			   wishlist/quickview icons, which are positioned assuming the
			   original box model. */
			.products .product .product-image-link,
			.wd-products-slider .product-image-link,
			.product-image-link,
			.wd-product-thumb {
				display: flex !important;
				flex-direction: column !important;
				gap: 0 !important;
			}

			/* Wider gap between *different* product cards, so proximity
			   itself signals that the two images above belong together and
			   the next card's images don't */
			.products .product,
			.wd-products-slider .product {
				margin-bottom: 14px !important;
			}

			/* Tighten the gap between the product title and the price right
			   below it — client flagged it as too much space */
			.product-element-bottom .wd-product-header,
			.wd-product-header {
				margin-bottom: 0 !important;
			}

			.wd-entities-title {
				margin: 0 !important;
			}

			.product-element-bottom .wrap-price,
			.wrap-price {
				margin-top: 0 !important;
			}

			/* Pull product title and price closer together on mobile */
			.product-element-bottom,
			.product-bottom {
				margin-top: 2px !important;
			}

			.product-element-bottom .wd-product-header,
			.product-element-bottom .product-title,
			.wd-product-header,
			.product-title,
			.product-element-bottom h2,
			.product-element-bottom .wd-entities-title,
			.wd-entities-title,
			.product-element-bottom h3,
			.product-element-bottom .entry-title {
				margin-bottom: 0 !important;
				padding-bottom: 0 !important;
				line-height: 1.2 !important;
			}

			/* The actual title-price gap comes from Woodmart's own flex
			   `gap` on .product-element-bottom (--wd-prod-gap, 12px default)
			   -- not margin/padding on the children, which is why the rules
			   above alone didn't move it. Halved per client request. */
			.product-element-bottom {
				--wd-prod-gap: 6px;
				gap: 6px !important;
			}

			/* Main (real) product image */
			.products .product .product-image-link > a > img,
			.products .product .product-image-link > img,
			.wd-products-slider .product-image-link > a > img,
			.wd-products-slider .product-image-link > img,
			.product-image-link img,
			.woocommerce-product-gallery__image,
			.woocommerce-product-gallery__image img {
				flex: 1 1 auto !important;
				width: 100% !important;
				height: auto !important;
				aspect-ratio: 1120 / 1380 !important;
				object-fit: contain !important;
				background-color: #fff !important;
			}

			/* Hover/model image: force visible on mobile */
			.wd-product-img-hover,
			.product-image-link .wd-product-img-hover,
			.wd-product-thumb .wd-product-img-hover,
			.products .product .wd-product-img-hover {
				display: block !important;
				display: flex !important;
				flex-direction: column !important;
				opacity: 1 !important;
				visibility: visible !important;
				position: static !important;
				position: relative !important;
				flex: 1 1 auto !important;
				width: 100% !important;
				height: auto !important;
				min-height: 0 !important;
				aspect-ratio: 1120 / 1380 !important;
				object-fit: contain !important;
				background-color: #fff !important;
				overflow: hidden !important;
				transform: none !important;
				z-index: 1 !important;
				border-top: 1px solid #ececec !important;
			}

			.wd-product-img-hover img,
			.product-image-link .wd-product-img-hover img,
			.products .product .wd-product-img-hover img {
				width: 100% !important;
				height: 100% !important;
				object-fit: contain !important;
				object-position: center center !important;
				background-color: #fff !important;
			}

			/* Label the second image so the pair reads as one product's
			   two views, not two different products */
			.wd-product-img-hover::after,
			.product-image-link .wd-product-img-hover::after,
			.products .product .wd-product-img-hover::after {
				content: "On model";
				position: absolute !important;
				bottom: 6px;
				left: 6px;
				z-index: 2;
				background-color: rgba(255, 255, 255, 0.85) !important;
				color: #333 !important;
				font-size: 9px !important;
				line-height: 1 !important;
				padding: 3px 6px !important;
				border-radius: 3px !important;
				text-transform: uppercase !important;
				letter-spacing: 0.03em !important;
				pointer-events: none !important;
			}

			/* Shrink the quick-view/wishlist icon buttons to the same tight
			   scale as the "On model" label (45px circle felt bulky next to
			   it). --wd-action-w/h and --wd-btn-inset are Woodmart's own
			   sizing hooks, no literal padding to trim on these. */
			.wd-buttons .wd-action-btn {
				--wd-action-w: 32px !important;
				--wd-action-h: 32px !important;
			}

			.wd-buttons .wd-action-btn.wd-style-icon {
				--wd-action-icon-size: 14px !important;
			}

			.wd-buttons.wd-pos-r-t,
			.wd-buttons.wd-pos-r-b {
				--wd-btn-inset: 6px !important;
			}
		}
	</style>
	<?php
}