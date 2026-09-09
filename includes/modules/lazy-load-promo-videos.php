<?php
/**
 * Module: Lazy Load Promo Banner Videos
 *
 * Woodmart's "Banner" widget (used for the homepage Necklaces/Pendants/Rings
 * category tiles) renders self-hosted videos as plain
 * <video src="..." autoplay muted loop playsinline> with no preload control.
 * The browser's preload scanner fetches them the instant it parses the
 * HTML, regardless of whether the tile is anywhere near the viewport. Under
 * real page-load contention (100+ requests) they can take 15+ seconds to
 * finish, dragging out DOMContentLoaded/Load and skewing LCP attribution.
 *
 * Rewrites just this widget's rendered HTML via Elementor's own
 * `elementor/widget/render_content` filter (per-widget, not a global page
 * buffer — an earlier version of this module used ob_start() on the whole
 * page and that broke Elementor's internal CSS settings manager) to swap
 * the eager `src` for `data-lazy-src` and drop `autoplay`. A small
 * IntersectionObserver script then restores `src` and starts playback only
 * once the tile is about to scroll into view. No theme file is touched, so
 * this survives Woodmart updates.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'elementor/widget/render_content', 'lior_lazy_promo_videos_rewrite', 10, 2 );

function lior_lazy_promo_videos_rewrite( $widget_content, $widget ) {
	$applicable = array( 'wd_banner', 'wd_banner_carousel' );
	if ( ! in_array( $widget->get_name(), $applicable, true ) || strpos( $widget_content, 'autoplay muted loop playsinline' ) === false ) {
		return $widget_content;
	}

	return preg_replace(
		'/<video src="([^"]+)" autoplay muted loop playsinline><\/video>/',
		'<video data-lazy-src="$1" muted loop playsinline preload="none" class="lior-lazy-video"></video>',
		$widget_content
	);
}

add_action( 'wp_footer', 'lior_lazy_promo_videos_script' );

function lior_lazy_promo_videos_script() {
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function () {
		var videos = document.querySelectorAll('video.lior-lazy-video[data-lazy-src]');
		if ( ! videos.length ) {
			return;
		}
		if ( ! ( 'IntersectionObserver' in window ) ) {
			// No IntersectionObserver support — fall back to loading them normally.
			videos.forEach( function ( video ) {
				video.src = video.getAttribute( 'data-lazy-src' );
				video.load();
				video.play().catch( function () {} );
			} );
			return;
		}
		var observer = new IntersectionObserver( function ( entries, obs ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					return;
				}
				var video = entry.target;
				video.src = video.getAttribute( 'data-lazy-src' );
				video.removeAttribute( 'data-lazy-src' );
				video.load();
				video.play().catch( function () {} );
				obs.unobserve( video );
			} );
		}, { rootMargin: '300px' } );
		videos.forEach( function ( video ) {
			observer.observe( video );
		} );
	} );
	</script>
	<?php
}
