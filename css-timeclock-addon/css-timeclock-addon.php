<?php
/**
 * Plugin Name:       SMOTC
 * Plugin URI:        https://wordpress.org/plugins/aio-time-clock-lite/
 * Description:       PIN pad and name-list kiosk add-on for SMOTC Core. Shared tablets clock employees in and out without a WordPress login, show a live who-is-working board, and let staff suggest punch edits for supervisor approval.
 * Version:           1.4.5
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            CSS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       css-timeclock-addon
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CSS_TC_ADDON_VERSION', '1.4.5' );
define( 'CSS_TC_ADDON_FILE', __FILE__ );
define( 'CSS_TC_ADDON_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSS_TC_ADDON_URL', plugin_dir_url( __FILE__ ) );
define( 'CSS_TC_ADDON_BASENAME', plugin_basename( __FILE__ ) );

require_once CSS_TC_ADDON_DIR . 'includes/class-plugin.php';

/**
 * Plugin bootstrap.
 *
 * @return Css_Tc_Plugin
 */
function css_tc_addon() {
	return Css_Tc_Plugin::instance();
}

css_tc_addon();

register_activation_hook( CSS_TC_ADDON_FILE, array( 'Css_Tc_Plugin', 'activate' ) );
register_deactivation_hook( CSS_TC_ADDON_FILE, array( 'Css_Tc_Plugin', 'deactivate' ) );
