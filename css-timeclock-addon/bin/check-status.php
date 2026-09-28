<?php
/**
 * Employment status rules. Run: php bin/check-status.php
 *
 * @package CssTimeclockAddon
 */

if ( PHP_SAPI !== 'cli' ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

define( 'ABSPATH', __DIR__ );
if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $s Text.
	 * @return string
	 */
	function __( $s ) {
		return $s;
	}
}
require_once dirname( __DIR__ ) . '/includes/class-status.php';
require_once dirname( __DIR__ ) . '/includes/class-holidays.php';

$failed = 0;
/**
 * @param bool   $cond  Condition.
 * @param string $label Label.
 * @return void
 */
function css_tc_check( $cond, $label ) {
	global $failed;
	echo ( $cond ? 'ok  ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$failed;
	}
}

$S = 'Css_Tc_Status';
$active = array( 'status' => 'active', 'leave_from' => '', 'leave_to' => '', 'last_day' => '' );
$leave  = array( 'status' => 'leave', 'leave_from' => '2026-10-05', 'leave_to' => '2026-10-30', 'last_day' => '' );
$open   = array( 'status' => 'leave', 'leave_from' => '2026-10-05', 'leave_to' => '', 'last_day' => '' );
$gone   = array( 'status' => 'inactive', 'leave_from' => '', 'leave_to' => '', 'last_day' => '2026-10-15' );

css_tc_check( 'active' === $S::status_on( $active, '2026-10-10' ), 'active is active' );
css_tc_check( 'active' === $S::status_on( $leave, '2026-10-04' ), 'before leave starts: active' );
css_tc_check( 'leave' === $S::status_on( $leave, '2026-10-05' ) && 'leave' === $S::status_on( $leave, '2026-10-30' ), 'first and last day of leave: on leave' );
css_tc_check( 'active' === $S::status_on( $leave, '2026-10-31' ), 'day after leave ends: active again on its own' );
css_tc_check( 'leave' === $S::status_on( $open, '2027-03-01' ), 'leave with no end date stays on' );
css_tc_check( 'active' === $S::status_on( $gone, '2026-10-15' ), 'last day worked is still active' );
css_tc_check( 'inactive' === $S::status_on( $gone, '2026-10-16' ), 'day after last day: inactive' );
css_tc_check( 'active' === $S::status_on( array(), '2026-10-16' ), 'nothing stored: active' );

css_tc_check( '' === $S::validate( 'leave', '2026-10-05', '', '', '' ), 'leave with start only is fine' );
css_tc_check( '' !== $S::validate( 'leave', '', '', '', '' ), 'leave needs a start date' );
css_tc_check( '' !== $S::validate( 'leave', '2026-10-05', '2026-10-01', '', '' ), 'leave end before start refused' );
css_tc_check( '' !== $S::validate( 'inactive', '', '', '', '' ), 'inactive needs a last day' );
css_tc_check( '' !== $S::validate( 'inactive', '', '', '2026-01-01', '2026-09-01' ), 'last day before hire refused' );
css_tc_check( '' !== $S::validate( 'bogus', '', '', '', '' ), 'unknown status refused' );
css_tc_check( '' !== $S::validate( 'inactive', '', '', '2026-02-30', '' ), 'bad date refused' );

// Holidays: none on leave or after the last day.
$h = new Css_Tc_Holidays(
	array(
		'holidays_enabled'      => 1,
		'holiday_hours'         => 8,
		'holidays_observed'     => array( 'thanksgiving', 'christmas', 'columbus' ),
		'holidays_custom'       => '',
		'holiday_weekend_shift' => 1,
	)
);
$r = $h->for_range( '2026-10-01', '2026-12-31', '', $leave );
css_tc_check( 0 === $r['2026-10-12']['seconds'] && 'leave' === $r['2026-10-12']['blocked'], 'Columbus Day during leave: not paid' );
css_tc_check( 28800 === $r['2026-11-26']['seconds'], 'Thanksgiving after leave ended: paid' );
$r = $h->for_range( '2026-10-01', '2026-12-31', '', $gone );
css_tc_check( 28800 === $r['2026-10-12']['seconds'], 'holiday before the last day: paid' );
css_tc_check( 0 === $r['2026-12-25']['seconds'] && 'inactive' === $r['2026-12-25']['blocked'], 'Christmas after the last day: not paid' );

if ( $failed ) {
	fwrite( STDERR, "{$failed} check(s) failed\n" );
	exit( 1 );
}
echo "all checks passed\n";
