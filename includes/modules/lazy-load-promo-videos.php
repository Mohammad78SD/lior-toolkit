<?php
/**
 * Module: Lazy Load Promo Banner Videos
 *
 * Woodmart's "Banner" widget (used for the homepage Necklaces/Pendants/Rings
 * category tiles) renders self-hosted videos as plain
 * <video src="..." autoplay muted loop playsinline> with no preload control
 * and no filter hook to intercept — the browser's preload scanner starts
 * fetching them the instant it parses the HTML, regardless of whether the
 * tile is anywhere near the viewport. On a real page load these end up
 * competing at low priority against 100+ other requests and can take
 * 15+ seconds to finish, dragging out DOMContentLoaded/Load and confusing
 * LCP attribution for whatever paints after them.
 *
 * Since there's no filter around that specific line of markup, this module
 * rewrites the final HTML output (same technique caching/optimization
 * plugins use for lazy-loading) to swap the eager `src` for `data-lazy-src`
 * and strip `autoplay`, then a small IntersectionObserver script restores
 * `src` and starts playback only once the tile is actually about to scroll
 * into view. No theme file is touched, so this survives Woodmart updates.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'lior_lazy_promo_videos_buffer_start', 1 );

function lior_lazy_promo_videos_buffer_start() {
	if ( is_admin() || is_feed() || wp_doing_ajax() || defined( 'REST_REQUEST' ) ) {
		return;
	}
	ob_start( 'lior_lazy_promo_videos_rewrite' );
}

function lior_lazy_promo_videos_rewrite( $html ) {
	if ( strpos( $html, 'autoplay muted loop playsinline' ) === false ) {
		return $html;
	}

	return preg_replace(
		'/<video src="([^"]+)" autoplay muted loop playsinline><\/video>/',
		'<video data-lazy-src="$1" muted loop playsinline preload="none" class="lior-lazy-video"></video>',
		$html
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
