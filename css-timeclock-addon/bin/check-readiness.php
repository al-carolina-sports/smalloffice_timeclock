<?php
/**
 * USOTC 1.9.1 checks: shift order, overnight label, whole-minute hours,
 * overtime exclusions, the hours cap, the manager-only note, and CSV columns.
 *
 * Usage: php bin/check-readiness.php
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
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
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

require_once dirname( __DIR__ ) . '/includes/class-time.php';
require_once dirname( __DIR__ ) . '/includes/class-overtime.php';
require_once dirname( __DIR__ ) . '/includes/class-pay-codes.php';
require_once dirname( __DIR__ ) . '/includes/class-reports.php';

$failed = 0;
$time   = new Css_Tc_Time();

/**
 * @param bool   $cond  Condition.
 * @param string $label Label.
 * @return void
 */
function css_tc_ready( $cond, $label ) {
	global $failed;
	if ( $cond ) {
		echo "ok  {$label}\n";
		return;
	}
	echo "FAIL {$label}\n";
	++$failed;
}

$noon = strtotime( '2026-09-22 16:00:00 UTC' );
$am   = strtotime( '2026-09-22 12:00:00 UTC' );
$late = strtotime( '2026-09-22 18:15:00 UTC' );
$rows = array(
	array( 'id' => 1, 'sort_ts' => $late, 'in' => '02:15 PM' ),
	array( 'id' => 2, 'sort_ts' => $am, 'in' => '08:00 AM' ),
	array( 'id' => 3, 'sort_ts' => $noon, 'in' => '12:00 PM' ),
);
$as_text = $rows;
usort(
	$as_text,
	static function ( $a, $b ) {
		return strcmp( $a['in'], $b['in'] );
	}
);
css_tc_ready( array( 1, 2, 3 ) === array( $as_text[0]['id'], $as_text[1]['id'], $as_text[2]['id'] ), '12-hour text order is not chronological' );
usort( $rows, array( 'Css_Tc_Time', 'compare_shift_rows' ) );
css_tc_ready( array( 2, 3, 1 ) === array( $rows[0]['id'], $rows[1]['id'], $rows[2]['id'] ), 'shifts sort by timestamp' );

css_tc_ready( '06:00 AM next day' === Css_Tc_Time::clock_out_label( '06:00 AM', true ), 'overnight clock-out says next day' );
css_tc_ready( '05:00 PM' === Css_Tc_Time::clock_out_label( '05:00 PM', false ), 'same-day clock-out is unchanged' );
css_tc_ready( false === strpos( Css_Tc_Time::clock_out_label( '06:00 AM', true ), '(+1)' ), 'overnight label does not use (+1)' );

$seconds = 1770;
css_tc_ready( 30 === Css_Tc_Time::whole_minutes( $seconds ), '1770 seconds is 30 whole minutes' );
css_tc_ready( '0:30' === $time->format_duration( $seconds ), 'H:MM uses the whole-minute total' );
css_tc_ready( '0.50' === Css_Tc_Time::decimal_from_seconds( $seconds ), 'decimal hours uses the same whole-minute total' );
css_tc_ready( '00:30' === $time->format_hours_hm( $seconds ), 'zero-padded H:MM matches' );
$half = 8 * HOUR_IN_SECONDS + 30;
css_tc_ready( 481 === Css_Tc_Time::whole_minutes( $half ) && '8:01' === $time->format_duration( $half ) && '8.02' === Css_Tc_Reports::decimal_hours( $half ), 'a half-minute rounds the same way in both formats' );

css_tc_ready( Css_Tc_Overtime::exclude_from_overtime( array( 'is_long' => true, 'manager_reviewed' => false ) ), 'an uncorrected long shift is left out of overtime' );
css_tc_ready( Css_Tc_Overtime::exclude_from_overtime( array( 'is_stale_open' => true, 'manager_reviewed' => false ) ), 'an uncorrected missed clock-out is left out of overtime' );
css_tc_ready( ! Css_Tc_Overtime::exclude_from_overtime( array( 'is_long' => true, 'manager_reviewed' => true ) ), 'a manager-corrected long shift counts again' );
css_tc_ready( ! Css_Tc_Overtime::exclude_from_overtime( array( 'is_long' => false, 'is_stale_open' => false ) ), 'a normal shift counts toward overtime' );

$segments = array(
	array( 'week' => 0, 'seconds' => 32 * HOUR_IN_SECONDS, 'group' => 1 ),
	array( 'week' => 0, 'seconds' => 18 * HOUR_IN_SECONDS, 'group' => 1 ),
);
$with_long = Css_Tc_Overtime::allocate( $segments, 40 * HOUR_IN_SECONDS, 1, false );
css_tc_ready( 10 * HOUR_IN_SECONDS === array_sum( $with_long ), 'counting the long shift would create 10 hours of overtime' );
$without = Css_Tc_Overtime::allocate( array( $segments[0] ), 40 * HOUR_IN_SECONDS, 1, false );
css_tc_ready( 0 === array_sum( $without ), 'leaving the long shift out produces no overtime' );

css_tc_ready( 168 === Css_Tc_Overtime::max_hours( 1 ), 'a 1-week window caps at 168 hours' );
css_tc_ready( 336 === Css_Tc_Overtime::max_hours( 2 ), 'a 2-week window caps at 336 hours' );
css_tc_ready( 168.0 === Css_Tc_Overtime::clamp_hours( 336, 1 ), '336 hours does not fit a 1-week window' );
css_tc_ready( 40.0 === Css_Tc_Overtime::clamp_hours( 40, 1 ), '40 hours still fits a 1-week window' );

$disabled = 'Overtime is not calculated. All hours are Regular.';
$active   = 'Overtime after 40 hours per week.';
css_tc_ready( '' === Css_Tc_Overtime::visible_note( $disabled, false, false ), 'employees do not see the overtime-not-calculated note' );
css_tc_ready( $disabled === Css_Tc_Overtime::visible_note( $disabled, false, true ), 'managers see the overtime-not-calculated note' );
css_tc_ready( $active === Css_Tc_Overtime::visible_note( $active, true, false ), 'employees still see an active overtime rule' );

$weeks = array(
	0 => array( 'label' => 'Week 1', 'range' => '09/07-09/13' ),
	1 => array( 'label' => 'Week 2', 'range' => '09/14-09/20' ),
);
$off = Css_Tc_Reports::summary_csv_header( false, $weeks, array( 'Holiday' ) );
$on  = Css_Tc_Reports::summary_csv_header( true, $weeks, array( 'Holiday', 'PTO', 'Sick' ) );
css_tc_ready( $off === array( 'Employee', 'Department', 'Shifts', 'Regular (hours)', 'Overtime (hours)', 'Holiday (hours)', 'Total (hours)', 'Week 1 09/07-09/13 (hours)', 'Week 2 09/14-09/20 (hours)', 'Needs attention' ), 'summary CSV header is stable' );
css_tc_ready( 'Overtime (hours)' === $on[4] && 'Worked in' === $on[1] && 'Holiday (hours)' === $on[5] && 'PTO (hours)' === $on[6], 'organization CSV keeps overtime in the same place' );

$row = array(
	'name'       => 'Ada',
	'department' => 'CSS',
	'shifts'     => 2,
	'codes'      => array(
		'regular' => 8 * HOUR_IN_SECONDS,
		'holiday' => 8 * HOUR_IN_SECONDS,
	),
	'total'      => 16 * HOUR_IN_SECONDS,
	'weeks'      => array( 0 => 16 * HOUR_IN_SECONDS, 1 => 0 ),
	'attention'  => array(),
);
$summary = array(
	'use_org' => false,
	'rule'    => array( 'enabled' => false ),
	'codes'   => array(
		'regular' => array( 'label' => 'Regular' ),
		'holiday' => array( 'label' => 'Holiday' ),
	),
	'weeks'   => $weeks,
	'rows'    => array( $row ),
);
$method = new ReflectionMethod( 'Css_Tc_Reports', 'summary_csv_lines' );
$method->setAccessible( true );
$lines = $method->invoke( new Css_Tc_Reports(), $summary );
css_tc_ready( $lines[0] === $off, 'CSV with overtime off uses the stable header' );
css_tc_ready( '' === $lines[1][4] && '8.00' === $lines[1][3] && '8.00' === $lines[1][5] && '16.00' === $lines[1][6], 'overtime cell is blank when overtime is off' );
$summary['rule']['enabled'] = true;
$summary['rows'][0]['codes']['overtime'] = 0;
$lines = $method->invoke( new Css_Tc_Reports(), $summary );
css_tc_ready( '0.00' === $lines[1][4], 'overtime cell is filled when overtime is on' );

if ( $failed > 0 ) {
	fwrite( STDERR, "{$failed} check(s) failed\n" );
	exit( 1 );
}
echo "all checks passed\n";
exit( 0 );
