<?php
/**
 * Holiday calendar and eligibility checks. Run: php bin/check-holidays.php
 *
 * @package CssTimeclockAddon
 */

if ( PHP_SAPI !== 'cli' ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/New_York' ); // Prove server time zone does not shift days.
if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $s Text.
	 * @return string
	 */
	function __( $s ) {
		return $s;
	}
}
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

$c = 'Css_Tc_Holidays';
css_tc_check( '2026-01-19' === $c::actual_date( 'third monday of january %d', 2026 ), 'MLK 2026 is Jan 19' );
css_tc_check( '2026-05-25' === $c::actual_date( 'last monday of may %d', 2026 ), 'Memorial Day 2026 is May 25' );
css_tc_check( '2026-09-07' === $c::actual_date( 'first monday of september %d', 2026 ), 'Labor Day 2026 is Sep 7' );
css_tc_check( '2026-11-26' === $c::actual_date( 'fourth thursday of november %d', 2026 ), 'Thanksgiving 2026 is Nov 26' );
css_tc_check( '2026-11-27' === $c::actual_date( 'fourth thursday of november %d +1 day', 2026 ), 'Day after Thanksgiving 2026 is Nov 27' );
css_tc_check( '2026-04-03' === $c::actual_date( 'easter-2', 2026 ), 'Good Friday 2026 is Apr 3' );
css_tc_check( '2027-03-26' === $c::actual_date( 'easter-2', 2027 ), 'Good Friday 2027 is Mar 26' );

css_tc_check( '2026-07-03' === $c::weekend_shift( '2026-07-04' ), 'July 4 2026 (Sat) paid Fri Jul 3' );
css_tc_check( '2022-12-26' === $c::weekend_shift( '2022-12-25' ), 'Christmas 2022 (Sun) paid Mon Dec 26' );
css_tc_check( '2022-12-23' === $c::weekend_shift( '2022-12-24', true ), 'Christmas Eve 2022 (Sat) paid Fri Dec 23' );
css_tc_check( '2023-12-22' === $c::weekend_shift( '2023-12-24', true ), 'Christmas Eve 2023 (Sun) paid Fri Dec 22, not on Christmas' );
css_tc_check( '2026-12-25' === $c::weekend_shift( '2026-12-25' ), 'weekday stays put' );

$base = array(
	'holidays_enabled'      => 1,
	'holiday_hours'         => 8,
	'holidays_observed'     => Css_Tc_Holidays::default_observed(),
	'holidays_custom'       => '',
	'holiday_weekend_shift' => 1,
);
$h = new Css_Tc_Holidays( $base );
$y26 = $h->between( '2026-01-01', '2026-12-31' );
css_tc_check( array( '2026-01-01', '2026-05-25', '2026-07-03', '2026-09-07', '2026-11-26', '2026-12-25' ) === array_keys( $y26 ), 'default six for 2026 (July 4 moved to Fri)' );
css_tc_check( 28800 === $h->seconds_per_holiday(), 'default 8 hours' );

$y21 = ( new Css_Tc_Holidays( $base ) )->between( '2021-12-01', '2021-12-31' );
css_tc_check( isset( $y21['2021-12-31'] ) && in_array( "New Year's Day", $y21['2021-12-31'], true ), "New Year's 2022 (Sat) is paid Fri Dec 31 2021" );

$noshift = new Css_Tc_Holidays( array_merge( $base, array( 'holiday_weekend_shift' => 0 ) ) );
css_tc_check( array_key_exists( '2026-07-04', $noshift->between( '2026-07-01', '2026-07-31' ) ), 'weekend shift off: July 4 stays on Saturday' );

css_tc_check( array() === ( new Css_Tc_Holidays( array_merge( $base, array( 'holidays_enabled' => 0 ) ) ) )->between( '2026-01-01', '2026-12-31' ), 'holidays off: none' );

$custom = Css_Tc_Holidays::parse_custom( "# comment\n12-24 Christmas Eve\n2026-10-12 Office closed\n13-40 bad\nnonsense\n2026-02-30 bad" );
css_tc_check( 2 === count( $custom['rows'] ) && array( '13-40 bad', 'nonsense', '2026-02-30 bad' ) === $custom['invalid'], 'custom lines: yearly, one-off, and bad lines' );
$hc = new Css_Tc_Holidays( array_merge( $base, array( 'holidays_custom' => "12-24 Christmas Eve\n2026-10-12 Office closed\n12-25 Christmas Day" ) ) );
$range = $hc->between( '2026-10-01', '2026-12-31' );
css_tc_check( isset( $range['2026-10-12'], $range['2026-12-24'] ), 'custom yearly and one-off dates are paid' );
css_tc_check( array( 'Christmas Day' ) === $range['2026-12-25'], 'a custom line repeating a checked holiday is not listed twice' );

$h8 = new Css_Tc_Holidays( $base );
$emp = $h8->for_range( '2026-11-16', '2026-11-29', '' );
css_tc_check( 28800 === $emp['2026-11-26']['seconds'], 'no hire date: Thanksgiving paid 8h' );
$from = Css_Tc_Holidays::eligible_from_dates( '2026-09-01', 90 );
css_tc_check( '2026-11-30' === $from, 'hired Sep 1 + 90 days = Nov 30' );
$intro = $h8->for_range( '2026-11-16', '2026-11-29', $from );
css_tc_check( 0 === $intro['2026-11-26']['seconds'] && false === $intro['2026-11-26']['eligible'], 'in the 90-day intro period: Thanksgiving not paid' );
$later = $h8->for_range( '2026-12-21', '2026-12-31', $from );
css_tc_check( 28800 === $later['2026-12-25']['seconds'], 'after the intro period: Christmas paid' );
css_tc_check( '2026-09-01' === Css_Tc_Holidays::eligible_from_dates( '2026-09-01', 0 ), 'no intro period: eligible from hire date' );
css_tc_check( '' === Css_Tc_Holidays::eligible_from_dates( '', 90 ), 'intro without hire date: always eligible' );
css_tc_check( 21600 === ( new Css_Tc_Holidays( array_merge( $base, array( 'holiday_hours' => 6 ) ) ) )->seconds_per_holiday(), 'custom hours per holiday' );

if ( $failed ) {
	fwrite( STDERR, "{$failed} check(s) failed\n" );
	exit( 1 );
}
echo "all checks passed\n";
