<?php
/**
 * Plugin Name:       MBR Critical CSS Generator
 * Plugin URI:        https://littlewebshack.com/
 * Description:       A front-end Critical CSS generator. Visitors enter a URL and viewport size; your server fetches the page and its stylesheets, and the visitor's own browser renders it and extracts the above-the-fold CSS. Add the [mbr_critical_css] shortcode to any page.
 * Version:           1.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Robert Palmer
 * Author URI:        https://littlewebshack.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mbr-critical-css
 *
 * @package MBR_Critical_CSS
 */

defined( 'ABSPATH' ) || exit;

define( 'MBR_CCSS_VERSION', '1.2.1' );
define( 'MBR_CCSS_FILE', __FILE__ );
define( 'MBR_CCSS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MBR_CCSS_URL', plugin_dir_url( __FILE__ ) );

require_once MBR_CCSS_DIR . 'includes/class-mbr-ccss-fetcher.php';
require_once MBR_CCSS_DIR . 'includes/class-mbr-ccss-plugin.php';

MBR_CCSS_Plugin::instance();

/*
 * Self-hosted updates via Plugin Update Checker 5.7, reading the manifest in the
 * HarbourBob/mbr-updates repository.
 */
require_once MBR_CCSS_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

if ( class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
	YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://raw.githubusercontent.com/HarbourBob/mbr-updates/main/mbr-critical.json',
		MBR_CCSS_FILE,
		'mbr-critical-css'
	);
}

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'mbr_ccss_cleanup' );
	}
);
