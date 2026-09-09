<?php
/**
 * Plugin Name: Lior Jewellery — Site Toolkit
 * Description: Custom functionality for lior-jewellery.com, kept as separate self-contained modules under includes/modules/ so new features can be dropped in later without touching what already works.
 * Version: 1.4.0
 * Author: Michael
 * Text Domain: lior-toolkit
 * Update URI: https://github.com/Mohammad78SD/lior-toolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'LIOR_TOOLKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIOR_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );
define( 'LIOR_TOOLKIT_VERSION', '1.4.0' );

/**
 * Self-update from GitHub Releases.
 *
 * When a new tagged Release is published on the repo (see README → "Releasing
 * a new version"), the site shows a normal plugin update on WP Admin →
 * Plugins once the release version is higher than the installed one. Updates
 * are applied by hand — nothing installs itself.
 *
 * Only loaded in the admin / cron / WP-CLI to keep front-end requests light.
 */
if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once LIOR_TOOLKIT_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';

	$lior_toolkit_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Mohammad78SD/lior-toolkit/',
		__FILE__,
		'lior-toolkit'
	);

	// Track tagged Releases, not the tip of a branch. 'master' is only the
	// fallback for reading version info before the first Release exists.
	$lior_toolkit_updater->setBranch( 'master' );
}

/**
 * Load every module in includes/modules/.
 *
 * To add a new feature later: drop a new .php file in includes/modules/ and it
 * loads automatically — no need to touch this file or register it anywhere.
 *
 * To turn a module off without deleting it, rename its file so it doesn't end
 * in .php (e.g. admin-order-email-link.php -> admin-order-email-link.php.off).
 */
add_action( 'plugins_loaded', 'lior_toolkit_load_modules' );

function lior_toolkit_load_modules() {
	$modules_dir = LIOR_TOOLKIT_DIR . 'includes/modules/';

	if ( ! is_dir( $modules_dir ) ) {
		return;
	}

	$modules = glob( $modules_dir . '*.php' );

	if ( ! $modules ) {
		return;
	}

	sort( $modules );

	foreach ( $modules as $module_file ) {
		require_once $module_file;
	}
}
