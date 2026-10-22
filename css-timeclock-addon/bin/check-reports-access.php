<?php
/**
 * Reports menu capability for Time Clock Admin. Run: php bin/check-reports-access.php
 *
 * Does not boot WordPress.
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
if ( ! defined( 'CSS_TC_ADDON_DIR' ) ) {
	define( 'CSS_TC_ADDON_DIR', dirname( __DIR__ ) . '/' );
}

require_once CSS_TC_ADDON_DIR . 'includes/class-plugin.php';

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

css_tc_check( 'aio-tc-lite' === Css_Tc_Reports::menu_parent( true ), 'AIO reports parent is aio-tc-lite' );
css_tc_check( 'options-general.php' === Css_Tc_Reports::menu_parent( false ), 'without AIO the parent is Settings' );
css_tc_check( 'options-general.php' !== Css_Tc_Reports::menu_parent( true ), 'Time Clock Admin is not sent to Settings' );

css_tc_check( 'css_tc_manage' === Css_Tc_Reports::menu_capability( true, false ), 'Time Clock Admin registers with css_tc_manage' );
css_tc_check( 'manage_options' === Css_Tc_Reports::menu_capability( false, true ), 'administrator without the cap registers with manage_options' );
css_tc_check( 'css_tc_manage' === Css_Tc_Reports::menu_capability( true, true ), 'a user with both caps registers with css_tc_manage' );
css_tc_check( 'do_not_allow' === Css_Tc_Reports::menu_capability( false, false ), 'an employee cannot register the reports page' );
css_tc_check( Css_Tc_Plugin::MANAGE_CAP === Css_Tc_Reports::menu_capability( true, false ), 'menu cap matches the manager capability' );

css_tc_check( Css_Tc_Reports::user_may_view( true, false ), 'css_tc_manage may view and export' );
css_tc_check( Css_Tc_Reports::user_may_view( false, true ), 'manage_options may view and export' );
css_tc_check( ! Css_Tc_Reports::user_may_view( false, false ), 'an employee may not view or export' );

$hooks = Css_Tc_Reports::screen_hooks( 'aio-tc-lite', '' );
css_tc_check( in_array( 'admin_page_css-tc-reports', $hooks, true ), 'early hook name is registered' );
css_tc_check( in_array( 'time-clock-lite_page_css-tc-reports', $hooks, true ), 'AIO parent hook name is registered' );

$hooks = Css_Tc_Reports::screen_hooks( 'aio-tc-lite', 'custom-clock' );
css_tc_check( in_array( 'custom-clock_page_css-tc-reports', $hooks, true ), 'the live parent hook name is registered' );

css_tc_check( 'css-tc-reports' === Css_Tc_Reports::visible_reports_slug( array( 'aio-reports-sub', 'css-tc-reports', 'css-tc-timecards' ) ), 'one Reports link, the add-on page' );
css_tc_check( 'aio-reports-sub' === Css_Tc_Reports::visible_reports_slug( array( 'aio-reports-sub' ) ), 'AIO slug remains only when ours is absent' );
css_tc_check( '' === Css_Tc_Reports::visible_reports_slug( array( 'css-tc-timecards' ) ), 'no reports slug means no reports link' );

$admin = Css_Tc_Plugin::grant_manage_cap(
	array( 'manage_options' => true, 'read' => true ),
	array( 'css_tc_manage' ),
	array(),
	null
);
css_tc_check( ! empty( $admin['css_tc_manage'] ), 'manage_options satisfies css_tc_manage' );

$tc = Css_Tc_Plugin::grant_manage_cap(
	array( 'css_tc_manage' => true, 'read' => true ),
	array( 'css_tc_manage' ),
	array(),
	null
);
css_tc_check( ! empty( $tc['css_tc_manage'] ) && empty( $tc['manage_options'] ), 'Time Clock Admin is not given manage_options' );

$employee = Css_Tc_Plugin::grant_manage_cap(
	array( 'read' => true ),
	array( 'css_tc_manage' ),
	array(),
	null
);
css_tc_check( empty( $employee['css_tc_manage'] ), 'an employee is not given css_tc_manage' );

$src = file_get_contents( CSS_TC_ADDON_DIR . 'includes/class-reports.php' );
css_tc_check( false !== strpos( $src, 'check_admin_referer( self::CSV_ACTION )' ), 'CSV export checks the report nonce' );
css_tc_check( false !== strpos( $src, 'function download_csv' ) && false !== strpos( $src, 'user_may_view' ), 'CSV export uses the reports capability' );

$menu = file_get_contents( CSS_TC_ADDON_DIR . 'includes/class-admin.php' );
css_tc_check( false !== strpos( $menu, 'Css_Tc_Reports::PAGE_SLUG' ) && false !== strpos( $menu, "'aio-reports-sub'" ), 'menu hides AIO reports and places the add-on page' );

if ( $failed > 0 ) {
	echo "{$failed} failed\n";
	exit( 1 );
}
echo "all ok\n";
exit( 0 );
