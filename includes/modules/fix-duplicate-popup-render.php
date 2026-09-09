<?php
/**
 * Module: Fix Duplicate Popup Render
 *
 * The Newsletter popup (a Woodmart-native `wd_popup`) was rendering twice on
 * every page load — once correctly via Woodmart's own
 * XTS\Modules\Floating_Blocks\Frontend::render_all_floating_blocks (on
 * wp_body_open), and a second time via Elementor Pro's own generic
 * ElementorPro\Modules\Popup\Module::print_popups (on wp_footer), which
 * calls elementor_theme_do_location('popup') and matches it despite it not
 * being an Elementor-Pro-native popup (_elementor_template_type is
 * "wd_popup", not "popup").
 *
 * Confirmed there is no legitimate use of Elementor Pro's own popup system
 * on this site (zero posts with _elementor_template_type = "popup"), so
 * this is dead weight causing pure duplication — safe to remove entirely
 * rather than touch the popup's own conditions/meta.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp', 'lior_remove_duplicate_popup_render' );

function lior_remove_duplicate_popup_render() {
	if ( ! class_exists( '\ElementorPro\Modules\Popup\Module' ) ) {
		return;
	}

	$module = \ElementorPro\Modules\Popup\Module::instance();

	remove_action( 'wp_footer', array( $module, 'print_popups' ), 10 );
}
