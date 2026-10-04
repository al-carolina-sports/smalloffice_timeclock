<?php
/**
 * CLI checks for SMOTC menu labels and Codebangers HTML removal.
 *
 * Usage: php bin/check-branding.php
 *
 * @package CssTimeclockAddon
 */

if ( PHP_SAPI !== 'cli' ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Raw key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-branding.php';

$failed = 0;

/**
 * @param bool   $cond  Condition.
 * @param string $label Label.
 * @return void
 */
function css_tc_check( $cond, $label ) {
	global $failed;
	if ( $cond ) {
		echo "ok  {$label}\n";
		return;
	}
	$failed++;
	echo "FAIL {$label}\n";
}

$menu = array(
	5 => array( 'Time Clock Lite', 'edit_posts', 'aio-tc-lite', 'Time Clock Lite', 'menu-top', 'hook', 'dashicons-clock' ),
	6 => array( 'Posts', 'edit_posts', 'edit.php', 'Posts' ),
);
$submenu = array(
	'aio-tc-lite' => array(
		array( 'Settings', 'edit_posts', 'aio-tc-lite', 'Settings' ),
		array( 'Real Time Monitoring', 'edit_posts', 'aio-monitoring-sub', 'Real Time Monitoring' ),
		array( 'Employees', 'edit_posts', 'aio-employees-sub', 'Employees' ),
		array( 'Time Clock Lite', 'edit_posts', 'aio-tc-lite', 'Time Clock Lite' ),
		array( 'Kiosk & PINs', 'edit_posts', 'css-tc-addon', 'Kiosk & PINs' ),
	),
	'options-general.php' => array(
		array( 'Time Clock Kiosk', 'manage_options', 'css-tc-addon', 'Time Clock Kiosk' ),
		array( 'General', 'manage_options', 'options-general.php', 'General Settings' ),
	),
);

Css_Tc_Branding::rebrand_menu_arrays( $menu, $submenu );

css_tc_check( 'SMOTC' === $menu[5][0] && 'SMOTC' === $menu[5][3], 'top-level Time Clock Lite becomes SMOTC' );
css_tc_check( 'Posts' === $menu[6][0], 'unrelated top-level menu stays' );
css_tc_check( 'Settings' === $submenu['aio-tc-lite'][0][0], 'Settings submenu stays' );
css_tc_check( 'Real Time Monitoring' === $submenu['aio-tc-lite'][1][0], 'Real Time Monitoring stays' );
css_tc_check( 'Employees' === $submenu['aio-tc-lite'][2][0], 'Employees stays' );
css_tc_check( 'SMOTC' === $submenu['aio-tc-lite'][3][0] && 'SMOTC' === $submenu['aio-tc-lite'][3][3], 'duplicate Time Clock Lite submenu becomes SMOTC' );
css_tc_check( 'SMOTC' === $submenu['aio-tc-lite'][4][0] && 'SMOTC' === $submenu['aio-tc-lite'][4][3], 'Kiosk & PINs menu becomes SMOTC' );
css_tc_check( 'SMOTC' === $submenu['options-general.php'][0][0] && 'SMOTC' === $submenu['options-general.php'][0][3], 'Settings → Time Clock Kiosk becomes SMOTC' );
css_tc_check( 'General' === $submenu['options-general.php'][1][0], 'unrelated settings item stays' );

$settings = <<<'HTML'
<div class="wrap aio_admin_wrapper">
    <a href="https://codebangers.com" target="_blank">
        <img src="https://example.com/wp-content/plugins/aio-time-clock-lite/images/logo.png" style="width:15%;">
    </a>

    <h1>All in One Time Clock Lite</h1>
    <h2 class="nav-tab-wrapper">
        <a href="?page=aio-tc-lite&tab=general_settings" class="nav-tab nav-tab-active">Settings</a>
        <a href="?page=aio-tc-lite&tab=help" class="nav-tab">
            <i class="dashicons dashicons-phone"></i>
            Help
        </a>
        <a href="?page=aio-tc-lite&tab=get_pro" class="nav-tab">Get Pro</a>
    </h2>
    <a href="https://codebangers.com/product/all-in-one-time-clock/" target="_blank" class="button button-primary aio-pro-button">Available in Pro</a>
        <div class="aio-support-section">
            <div class="support-image">
                <img src="https://example.com/wp-content/plugins/aio-time-clock-lite/images/support.jpg" alt="Support">
            </div>
            <div class="support-content">
                <h2>Need Support?</h2>
                <p>We are here to help!</p>
                <a href="https://codebangers.com/support/" class="button-primary" target="_blank">Get Support</a>
            </div>
        </div>
    <p>Thanks again for using the All In One Time Clock Lite <a href="?page=aio-tc-lite">Navigate to Settings Page</a></p>
</div>
HTML;

$monitoring = '<div class="wrap aio_admin_wrapper">'
	. '<a href="https://codebangers.com" target="_blank"><img src="https://example.com/wp-content/plugins/aio-time-clock-lite/images/logo.png" style="width:15%;"></a>'
	. '<hr><h1 style="padding-left: 10px;">Employees Currently Working</h1></div>';

$settings_out   = Css_Tc_Branding::filter_aio_admin_html( $settings );
$monitoring_out = Css_Tc_Branding::filter_aio_admin_html( $monitoring );

css_tc_check( false === stripos( $settings_out, 'logo.png' ), 'settings logo removed' );
css_tc_check( false === stripos( $settings_out, 'codebangers.com"' ) && false === stripos( $settings_out, "codebangers.com'" ), 'settings codebangers logo and support links removed' );
css_tc_check( false !== strpos( $settings_out, '<h1>SMOTC</h1>' ), 'settings h1 is SMOTC' );
css_tc_check( false === stripos( $settings_out, 'tab=help' ), 'help tab removed' );
css_tc_check( false === stripos( $settings_out, 'aio-support-section' ), 'support banner removed' );
css_tc_check( false === stripos( $settings_out, 'All In One Time Clock Lite' ), 'news credit renamed' );
css_tc_check( false !== strpos( $settings_out, 'Thanks again for using SMOTC' ), 'news credit says SMOTC' );
css_tc_check( false !== strpos( $settings_out, 'aio-pro-button' ), 'pro button left for upsell CSS' );
css_tc_check( false !== strpos( $settings_out, 'tab=general_settings' ), 'settings tab kept' );
css_tc_check( false === stripos( $monitoring_out, 'logo.png' ) && false === stripos( $monitoring_out, '<hr>' ), 'monitoring logo and following rule removed' );
css_tc_check( false !== strpos( $monitoring_out, 'Employees Currently Working' ), 'monitoring heading kept' );

$plugins = array(
	'css-timeclock-addon/css-timeclock-addon.php' => array(
		'Name'  => 'CSS Time Clock Addon',
		'Title' => 'CSS Time Clock Addon',
	),
	'aio-time-clock-lite/aio-time-clock-lite.php' => array(
		'Name'      => 'All in One Time Clock Lite - Tracking Employee Time Has Never Been Easier',
		'Title'     => 'All in One Time Clock Lite - Tracking Employee Time Has Never Been Easier',
		'Author'    => 'Codebangers',
		'AuthorName'=> 'Codebangers',
		'AuthorURI' => 'https://codebangers.com',
		'PluginURI' => 'https://codebangers.com/product/all-in-one-time-clock-lite/',
	),
	'hello.php' => array(
		'Name'   => 'Hello',
		'Author' => 'Someone',
	),
);
if ( ! defined( 'CSS_TC_ADDON_BASENAME' ) ) {
	define( 'CSS_TC_ADDON_BASENAME', 'css-timeclock-addon/css-timeclock-addon.php' );
}
$branding = new Css_Tc_Branding();
$plugins  = $branding->filter_plugins( $plugins );
css_tc_check( 'SMOTC' === $plugins['css-timeclock-addon/css-timeclock-addon.php']['Name'], 'addon plugin name is SMOTC' );
$aio = $plugins['aio-time-clock-lite/aio-time-clock-lite.php'];
css_tc_check( 'SMOTC Core' === $aio['Name'] && 'SMOTC Core' === $aio['Title'], 'AIO plugin name is SMOTC Core' );
css_tc_check( '' === $aio['Author'] && '' === $aio['AuthorName'] && '' === $aio['AuthorURI'] && '' === $aio['PluginURI'], 'AIO author and URIs blanked' );
css_tc_check( 'Hello' === $plugins['hello.php']['Name'] && 'Someone' === $plugins['hello.php']['Author'], 'other plugins untouched' );

$meta = array(
	'Version 2.1.0',
	'By <a href="https://codebangers.com">Codebangers</a>',
	'<a href="https://example.com/plugin-install.php?tab=plugin-information&amp;plugin=aio-time-clock-lite">View details</a>',
);
$meta = $branding->filter_plugin_row_meta( $meta, 'aio-time-clock-lite/aio-time-clock-lite.php' );
css_tc_check( array( 'Version 2.1.0' ) === array_values( $meta ), 'AIO row meta drops Codebangers and View details' );

$title = $branding->filter_admin_title( 'Time Clock Lite &lsaquo; Sandbox &#8212; WordPress', 'Time Clock Lite' );
css_tc_check( false === strpos( $title, 'SMOTC' ), 'admin title unchanged off AIO screens' );
$_GET['page'] = 'aio-tc-lite';
$title        = $branding->filter_admin_title( 'Time Clock Lite &lsaquo; Sandbox &#8212; WordPress', 'Time Clock Lite' );
css_tc_check( 0 === strpos( $title, 'SMOTC ' ), 'admin title replaces Time Clock Lite on AIO screens' );

exit( $failed > 0 ? 1 : 0 );
