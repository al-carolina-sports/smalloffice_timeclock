<?php
/**
 * Links to My Time Clock. Run: php bin/check-reach.php
 *
 * Does not boot WordPress. There is no login redirect.
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

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-reach.php';

$failed = 0;

/**
 * @param bool   $cond  Assertion.
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

css_tc_check( 'https://ncfrl.com/my-time-clock/' === Css_Tc_Reach::normalize_override( ' https://ncfrl.com/my-time-clock/ ' ), 'https override is kept' );
css_tc_check( '/my-time-clock/' === Css_Tc_Reach::normalize_override( '/my-time-clock/' ), 'site path override is kept' );
css_tc_check( '' === Css_Tc_Reach::normalize_override( '' ), 'blank override stays blank' );
css_tc_check( '' === Css_Tc_Reach::normalize_override( 'javascript:alert(1)' ), 'a script URL is refused' );
css_tc_check( '' === Css_Tc_Reach::normalize_override( 'my-time-clock' ), 'a bare slug is refused' );
css_tc_check( '' === Css_Tc_Reach::normalize_override( "https://ncfrl.com/a b" ), 'a URL with a space is refused' );

css_tc_check( Css_Tc_Reach::content_has_shortcode( 'Hello [css_tc_my_times] there' ), 'shortcode in page content is detected' );
css_tc_check( Css_Tc_Reach::content_has_shortcode( '[css_tc_my_times period="1"]' ), 'shortcode with attributes is detected' );
css_tc_check( ! Css_Tc_Reach::content_has_shortcode( '[css_tc_pin_kiosk]' ), 'the kiosk shortcode is not the timecard page' );

$id = Css_Tc_Reach::choose_page_id(
	array(
		array( 'id' => 4, 'status' => 'draft', 'content' => '[css_tc_my_times]' ),
		array( 'id' => 9, 'status' => 'publish', 'content' => 'Page [css_tc_my_times] body' ),
		array( 'id' => 12, 'status' => 'publish', 'content' => '[css_tc_my_times]' ),
	)
);
css_tc_check( 9 === $id, 'the first published page with the shortcode wins' );
css_tc_check( 0 === Css_Tc_Reach::choose_page_id( array( array( 'id' => 3, 'status' => 'publish', 'content' => 'no shortcode' ) ) ), 'a page without the shortcode is not used' );

css_tc_check( 'https://example.test/custom/' === Css_Tc_Reach::resolve_url( 'https://example.test/custom/', 'https://example.test/detected/', 'https://example.test/saved/', '/my-time-clock/' ), 'override beats detection' );
css_tc_check( 'https://example.test/detected/' === Css_Tc_Reach::resolve_url( '', 'https://example.test/detected/', 'https://example.test/saved/', '/my-time-clock/' ), 'detection beats the saved page' );
css_tc_check( 'https://example.test/saved/' === Css_Tc_Reach::resolve_url( '', '', 'https://example.test/saved/', '/my-time-clock/' ), 'saved page is the next fallback' );
css_tc_check( '/my-time-clock/' === Css_Tc_Reach::resolve_url( '', '', '', '/my-time-clock/' ), 'slug fallback when nothing is published' );

css_tc_check( 'Clocked in since Oct 26, 8:05 am' === Css_Tc_Reach::status_line( true, 'Oct 26, 8:05 am' ), 'open shift says clocked in since' );
css_tc_check( 'Clocked in' === Css_Tc_Reach::status_line( true, '' ), 'open shift without a time still says clocked in' );
css_tc_check( 'Clocked out' === Css_Tc_Reach::status_line( false, '' ), 'no open shift says clocked out' );
css_tc_check( 'Hours this pay period: 12:30' === Css_Tc_Reach::hours_line( '12:30' ), 'pay period hours use the timecard total' );
css_tc_check( 'Hours this pay period: 0:00' === Css_Tc_Reach::hours_line( '' ), 'empty hours read as 0:00' );

css_tc_check( Css_Tc_Reach::show_for_employee( true, true ), 'an employee sees the dashboard widget and admin menu' );
css_tc_check( ! Css_Tc_Reach::show_for_employee( true, false ), 'a subscriber does not see the employee links' );
css_tc_check( ! Css_Tc_Reach::show_for_employee( false, true ), 'the employee links turn off' );
css_tc_check( Css_Tc_Reach::show_for_staff( true, false, true ), 'a manager sees the toolbar link' );
css_tc_check( Css_Tc_Reach::show_for_staff( true, true, false ), 'an employee sees the toolbar link' );
css_tc_check( ! Css_Tc_Reach::show_for_staff( true, false, false ), 'a subscriber does not see the toolbar link' );
css_tc_check( ! Css_Tc_Reach::show_for_staff( false, true, true ), 'the toolbar link turns off' );

$order = Css_Tc_Reach::pin_dashboard_order(
	array(
		'normal' => 'dashboard_right_now,css_tc_my_time_clock',
		'side'   => 'css_tc_my_time_clock,dashboard_quick_press',
	),
	'css_tc_my_time_clock'
);
css_tc_check( 'css_tc_my_time_clock,dashboard_right_now' === $order['normal'], 'saved dashboard order keeps the widget first' );
css_tc_check( 'dashboard_quick_press' === $order['side'], 'the widget is not also left in the side column' );
css_tc_check( false === Css_Tc_Reach::pin_dashboard_order( false, 'css_tc_my_time_clock' ), 'no saved order is left to WordPress' );

$link = Css_Tc_Reach::nav_link_markup( 'https://ncfrl.com/my-time-clock/' );
$menu = Css_Tc_Reach::nav_item_html( '<li>Home</li>', 'primary', 'primary', true, true, 'https://ncfrl.com/my-time-clock/' );
css_tc_check( false !== strpos( $menu, $link ) && 0 === strpos( $menu, '<li>Home</li>' ), 'logged-in primary menu gains My Timecard' );
css_tc_check( '<li>Home</li>' === Css_Tc_Reach::nav_item_html( '<li>Home</li>', 'primary', 'primary', true, false, 'https://ncfrl.com/my-time-clock/' ), 'logged-out visitors do not see the menu link' );
css_tc_check( '<li>Home</li>' === Css_Tc_Reach::nav_item_html( '<li>Home</li>', 'footer', 'primary', true, true, 'https://ncfrl.com/my-time-clock/' ), 'a different menu location is left alone' );
css_tc_check( '<li>Home</li>' === Css_Tc_Reach::nav_item_html( '<li>Home</li>', 'primary', 'primary', false, true, 'https://ncfrl.com/my-time-clock/' ), 'the menu link turns off' );
css_tc_check( '<li>Home</li>' === Css_Tc_Reach::nav_item_html( '<li>Home</li>', 'primary', '', true, true, 'https://ncfrl.com/my-time-clock/' ), 'an empty location adds nothing' );
$twice = Css_Tc_Reach::nav_item_html( $link, 'primary', 'primary', true, true, 'https://ncfrl.com/my-time-clock/' );
css_tc_check( $twice === $link, 'the same link is not appended twice' );
css_tc_check( false !== strpos( $link, 'href="https://ncfrl.com/my-time-clock/"' ) && false !== strpos( $link, '>My Timecard<' ), 'the menu link text and address are escaped into the markup' );

$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-reach.php' );
css_tc_check( false === strpos( $src, 'login_redirect' ), 'there is no login redirect' );
css_tc_check( false !== strpos( $src, 'wp_nav_menu_items' ), 'front menu uses wp_nav_menu_items' );
css_tc_check( false !== strpos( $src, 'dashicons-clock' ), 'admin menu uses the clock icon' );
css_tc_check( false !== strpos( $src, 'wp_add_dashboard_widget' ), 'dashboard widget is registered' );
css_tc_check( false !== strpos( $src, 'admin_bar_menu' ), 'toolbar node is registered' );
css_tc_check( false !== strpos( $src, 'View my timecard' ) || false !== strpos( file_get_contents( dirname( __DIR__ ) . '/admin/views/dashboard-widget.php' ), 'View my timecard' ), 'widget has the timecard button' );

$widget = file_get_contents( dirname( __DIR__ ) . '/admin/views/dashboard-widget.php' );
css_tc_check( false === strpos( $widget, '<form' ) && false !== strpos( $widget, 'esc_html' ) && false !== strpos( $widget, 'esc_url' ), 'widget output is escaped and read-only' );

$version = file_get_contents( dirname( __DIR__ ) . '/css-timeclock-addon.php' );
css_tc_check( false !== strpos( $version, 'Version:           1.11.0' ) && false !== strpos( $version, "define( 'CSS_TC_ADDON_VERSION', '1.11.0' )" ), 'plugin version is 1.11.0' );

if ( $failed > 0 ) {
	echo "{$failed} failed\n";
	exit( 1 );
}
echo "all ok\n";
exit( 0 );
