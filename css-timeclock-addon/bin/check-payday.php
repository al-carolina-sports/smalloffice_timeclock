<?php
/**
 * Pay & Payday date math. Run: php bin/check-payday.php
 *
 * Does not boot WordPress. The owner's dates are fixtures here only.
 * Nothing in the plugin defaults to those dates, and an empty history
 * draws no markers.
 *
 * Owner coverage (not a default):
 * bi-weekly Sunday–Saturday, period anchor Sun Oct 18 2026.
 * Sun Oct 4–Sat Oct 17 paid Fri Oct 23.
 * Sun Oct 18–Sat Oct 31 paid Fri Nov 6.
 * Sun Nov 1–Sat Nov 14 paid Fri Nov 20.
 * Sun Nov 15–Sat Nov 28 paid Fri Dec 4.
 * First Friday after the period ends, which is also 6 days after a Saturday end.
 * A payday anchor of Fri Oct 23 steps every 14 days and lists the same Fridays.
 *
 * The Monday timecard engine (default anchor 2026-09-07) does not use these
 * Sunday boundaries. This script checks that disagreement and does not change it.
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
	 * @param string $text   Text.
	 * @param string $domain Unused.
	 * @return string
	 */
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-payday.php';

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

/**
 * Owner display schedule. Not saved unless a test builds a history row.
 *
 * @param string $mode weekday|days|anchor.
 * @return array<string,mixed>
 */
function css_tc_owner_config( $mode ) {
	return array(
		'frequency'      => 'biweekly',
		'anchor'         => '2026-10-18',
		'week_start'     => 0,
		'payday_mode'    => $mode,
		'payday_weekday' => 5,
		'payday_days'    => 6,
		'payday_anchor'  => '2026-10-23',
		'shift'          => 'previous',
		'holidays'       => '',
	);
}

/**
 * @param string $mode weekday|days|anchor.
 * @return array<int,array<string,mixed>>
 */
function css_tc_owner_history( $mode ) {
	return Css_Tc_Payday::with_snapshot( array(), '2026-10-18', css_tc_owner_config( $mode ), '2026-10-26 12:00:00' );
}

/**
 * Monday–Sunday biweekly from the hours-engine default anchor.
 * Mirrors Css_Tc_Pay_Periods for that default only.
 *
 * @param string $date Y-m-d.
 * @return array{0:string,1:string}
 */
function css_tc_monday_period( $date ) {
	$anchor = '2026-09-07';
	$len    = 14;
	$diff   = Css_Tc_Payday::diff_days( $anchor, $date );
	$index  = (int) floor( $diff / $len );
	$start  = Css_Tc_Payday::add_ymd( $anchor, $index * $len );
	$end    = Css_Tc_Payday::add_ymd( $start, $len - 1 );
	return array( $start, $end );
}

$blank = Css_Tc_Payday::default_config();
css_tc_check( '' === $blank['anchor'] && '' === $blank['payday_anchor'], 'factory config has no period anchor and no payday anchor' );
css_tc_check( array() === Css_Tc_Payday::normalize_history( array() ), 'empty history stays empty' );
css_tc_check( null === Css_Tc_Payday::period_covering( array(), '2026-10-26' ), 'unconfigured date has no pay period' );
css_tc_check( ! Css_Tc_Payday::visible( array(), 'employee' ) && ! Css_Tc_Payday::visible( array(), 'admin' ), 'unconfigured calendars draw no markers' );
css_tc_check( '' === Css_Tc_Payday::widget_line( array(), '2026-10-26' ), 'unconfigured widget has no payday' );

$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-payday.php' );
css_tc_check( false === strpos( $src, '2026-10-18' ) && false === strpos( $src, '2026-10-23' ) && false === strpos( $src, '2026-11-06' ), 'owner dates are not hard-coded in the payday class' );
$settings_view = file_get_contents( dirname( __DIR__ ) . '/admin/views/payday-settings.php' );
css_tc_check( false === strpos( $settings_view, 'value="2026-10-18"' ) && false === strpos( $settings_view, 'value="2026-10-23"' ) && false === strpos( $settings_view, 'value="2026-11-06"' ), 'the settings form does not prefill the owner dates' );

$hours_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-pay-periods.php' );
css_tc_check( false !== strpos( $hours_src, "DEFAULT_ANCHOR = '2026-09-07'" ) && false !== strpos( $hours_src, 'Monday' ), 'hours engine still documents Monday weeks from 2026-09-07' );
css_tc_check( '1' === ( new DateTimeImmutable( '2026-09-07' ) )->format( 'N' ), '2026-09-07 is a Monday' );

$owner_rows = array(
	array( '2026-10-04', '2026-10-17', '2026-10-23' ),
	array( '2026-10-18', '2026-10-31', '2026-11-06' ),
	array( '2026-11-01', '2026-11-14', '2026-11-20' ),
	array( '2026-11-15', '2026-11-28', '2026-12-04' ),
	array( '2026-11-29', '2026-12-12', '2026-12-18' ),
);

foreach ( array( 'weekday', 'days' ) as $css_tc_mode ) {
	$history = css_tc_owner_history( $css_tc_mode );
	foreach ( $owner_rows as $row ) {
		$period = Css_Tc_Payday::period_covering( $history, $row[0] );
		css_tc_check(
			$period && $row[0] === $period['start'] && $row[1] === $period['end'] && $row[2] === $period['payday'] && 14 === (int) $period['length'],
			$css_tc_mode . ' ' . $row[0] . '–' . $row[1] . ' pays ' . $row[2]
		);
	}
	$next = Css_Tc_Payday::upcoming( $history, '2026-10-26', 4 );
	css_tc_check( 4 === count( $next ), $css_tc_mode . ' preview returns four periods' );
	css_tc_check( $next && '2026-10-18' === $next[0]['start'] && '2026-11-06' === $next[0]['payday'], $css_tc_mode . ' period containing Oct 26 pays Nov 6' );
	css_tc_check( $next && '2026-11-01' === $next[1]['start'] && '2026-11-20' === $next[1]['payday'], $css_tc_mode . ' Nov 1–14 pays Nov 20' );
	css_tc_check( $next && '2026-11-15' === $next[2]['start'] && '2026-12-04' === $next[2]['payday'], $css_tc_mode . ' Nov 15–28 pays Dec 4' );
	css_tc_check( $next && '2026-11-29' === $next[3]['start'] && '2026-12-18' === $next[3]['payday'], $css_tc_mode . ' Nov 29–Dec 12 pays Dec 18' );
	$sum = Css_Tc_Payday::summary( $history, '2026-10-26' );
	css_tc_check( $sum && '2026-10-18' === $sum['start'] && '2026-10-31' === $sum['end'] && '2026-11-06' === $sum['payday'], $css_tc_mode . ' on Oct 26 the current period is Oct 18–Oct 31 and the next payday is Nov 6' );
	css_tc_check( $sum && 'Oct 18 – Oct 31, 2026' === $sum['range'] && 'Fri, Nov 6, 2026' === $sum['payday_label'], $css_tc_mode . ' header uses the owner dates' );
}

$series = array( '2026-10-09', '2026-10-23', '2026-11-06', '2026-11-20', '2026-12-04', '2026-12-18' );
foreach ( $series as $css_tc_day ) {
	css_tc_check( Css_Tc_Payday::is_series_payday( '2026-10-23', $css_tc_day, 14 ), $css_tc_day . ' is on the Oct 23 every-14-days series' );
}
css_tc_check( ! Css_Tc_Payday::is_series_payday( '2026-10-23', '2026-11-13', 14 ), 'Nov 13 is not a series payday' );
css_tc_check( '2026-10-23' === Css_Tc_Payday::series_on_or_after( '2026-10-23', '2026-10-23', 14 ), 'Oct 23 is the payday on Oct 23' );
css_tc_check( '2026-11-06' === Css_Tc_Payday::series_on_or_after( '2026-10-23', '2026-10-24', 14 ), 'the day after Oct 23 the next series payday is Nov 6' );
css_tc_check( '2026-11-06' === Css_Tc_Payday::series_on_or_after( '2026-10-23', '2026-10-26', 14 ), 'on Oct 26 the next series payday is Nov 6' );
css_tc_check( '2026-10-23' === Css_Tc_Payday::series_on_or_after( '2026-10-23', '2026-10-10', 14 ), 'before the anchor the next series payday is Oct 23' );
css_tc_check( '2026-10-09' === Css_Tc_Payday::series_on_or_after( '2026-10-23', '2026-10-09', 14 ), '14 days before the anchor is itself a payday' );

$anchor_mode = css_tc_owner_history( 'anchor' );
$anchor_oct  = Css_Tc_Payday::period_covering( $anchor_mode, '2026-10-26' );
css_tc_check( $anchor_oct && '2026-11-06' === $anchor_oct['payday'], 'every-14-days rule pays Nov 6 for the Oct 18–31 period' );
$moved = css_tc_owner_config( 'anchor' );
$moved['anchor'] = '2026-09-06';
$moved_history   = Css_Tc_Payday::with_snapshot( array(), '2026-09-06', $moved, '' );
css_tc_check(
	'2026-11-06' === Css_Tc_Payday::series_on_or_after( '2026-10-23', '2026-10-26', 14 )
	&& '2026-11-06' === Css_Tc_Payday::next_payday( $moved, '2026-10-26' ),
	'changing the period start does not move the 14-day payday series'
);
$wrong_anchor = css_tc_owner_config( 'weekday' );
$wrong_anchor['payday_anchor'] = '2026-01-02';
css_tc_check( '2026-11-06' === Css_Tc_Payday::next_payday( $wrong_anchor, '2026-10-26' ), 'first Friday ignores a different payday anchor' );

$early = Css_Tc_Payday::period_covering( css_tc_owner_history( 'weekday' ), '2026-10-10' );
css_tc_check( $early && '2026-10-04' === $early['start'] && '2026-10-17' === $early['end'] && '2026-10-23' === $early['payday'], 'Oct 23 payday covers Oct 4–17' );

foreach ( array( '2026-10-18', '2026-10-31', '2026-11-06', '2026-11-14' ) as $css_tc_day ) {
	$display = Css_Tc_Payday::period_covering( css_tc_owner_history( 'weekday' ), $css_tc_day );
	$monday  = css_tc_monday_period( $css_tc_day );
	css_tc_check(
		$display && ( $monday[0] !== $display['start'] || $monday[1] !== $display['end'] ),
		'Monday timecard period does not match the Sunday display period on ' . $css_tc_day
	);
}
css_tc_check( array( '2026-10-05', '2026-10-18' ) === css_tc_monday_period( '2026-10-18' ), 'hours engine puts Oct 18 at the end of Mon Oct 5–Sun Oct 18' );

$weekly = array(
	'frequency'      => 'weekly',
	'anchor'         => '2026-10-18',
	'payday_mode'    => 'weekday',
	'payday_weekday' => 1,
	'shift'          => 'keep',
);
$week = Css_Tc_Payday::period_for( $weekly, '2026-10-18' );
css_tc_check( $week && '2026-10-18' === $week['start'] && '2026-10-24' === $week['end'] && 7 === (int) $week['length'] && '2026-10-26' === $week['payday'], 'weekly Sunday anchor ends Saturday and pays the next Monday' );

$friday_end = array(
	'frequency'      => 'weekly',
	'anchor'         => '2026-10-10',
	'payday_mode'    => 'weekday',
	'payday_weekday' => 5,
	'shift'          => 'keep',
);
$strict = Css_Tc_Payday::period_for( $friday_end, '2026-10-10' );
css_tc_check( $strict && '2026-10-16' === $strict['end'] && '2026-10-23' === $strict['payday'], 'first Friday is strictly after a Friday period end' );

$semi_a = array( 'frequency' => 'semimonthly_1_15', 'anchor' => '2026-01-01', 'payday_mode' => 'on_end', 'shift' => 'keep' );
$s1     = Css_Tc_Payday::period_for( $semi_a, '2026-11-10' );
$s2     = Css_Tc_Payday::period_for( $semi_a, '2026-11-20' );
$s3     = Css_Tc_Payday::period_for( $semi_a, '2026-02-20' );
$s4     = Css_Tc_Payday::period_for( $semi_a, '2024-02-20' );
css_tc_check( $s1 && '2026-11-01' === $s1['start'] && '2026-11-14' === $s1['end'] && '2026-11-14' === $s1['payday'], 'semi-monthly 1st and 15th is the 1st through the 14th' );
css_tc_check( $s2 && '2026-11-15' === $s2['start'] && '2026-11-30' === $s2['end'], 'semi-monthly 1st and 15th second half ends on the last day' );
css_tc_check( $s3 && '2026-02-15' === $s3['start'] && '2026-02-28' === $s3['end'], 'semi-monthly 1st and 15th clamps February 2026' );
css_tc_check( $s4 && '2024-02-15' === $s4['start'] && '2024-02-29' === $s4['end'], 'semi-monthly 1st and 15th keeps leap day 2024' );

$semi_b = array( 'frequency' => 'semimonthly_15_eom', 'anchor' => '2026-01-01', 'payday_mode' => 'on_end', 'shift' => 'keep' );
$e1     = Css_Tc_Payday::period_for( $semi_b, '2026-11-10' );
$e2     = Css_Tc_Payday::period_for( $semi_b, '2026-11-20' );
$e3     = Css_Tc_Payday::period_for( $semi_b, '2026-02-20' );
$e4     = Css_Tc_Payday::period_for( $semi_b, '2024-02-20' );
css_tc_check( $e1 && '2026-11-01' === $e1['start'] && '2026-11-15' === $e1['end'], 'semi-monthly 15th and last day ends on the 15th' );
css_tc_check( $e2 && '2026-11-16' === $e2['start'] && '2026-11-30' === $e2['end'], 'semi-monthly 15th and last day second half starts on the 16th' );
css_tc_check( $e3 && '2026-02-16' === $e3['start'] && '2026-02-28' === $e3['end'], 'semi-monthly 15th and last day clamps February 2026' );
css_tc_check( $e4 && '2024-02-16' === $e4['start'] && '2024-02-29' === $e4['end'], 'semi-monthly 15th and last day keeps leap day 2024' );
$e_sun = Css_Tc_Payday::period_for( array( 'frequency' => 'semimonthly_15_eom', 'anchor' => '2026-01-01', 'payday_mode' => 'on_end', 'shift' => 'previous' ), '2026-11-10' );
css_tc_check( $e_sun && '2026-11-15' === $e_sun['end'] && '2026-11-13' === $e_sun['payday'], 'on-the-end payday moves a Sunday 15th back to Friday' );

$monthly = array( 'frequency' => 'monthly', 'anchor' => '2026-10-18', 'payday_mode' => 'on_end', 'shift' => 'keep' );
$m1      = Css_Tc_Payday::period_for( $monthly, '2026-10-26' );
$m2      = Css_Tc_Payday::period_for( $monthly, '2026-12-20' );
$m3      = Css_Tc_Payday::period_for( array( 'frequency' => 'monthly', 'anchor' => '2026-01-31', 'payday_mode' => 'on_end', 'shift' => 'keep' ), '2026-02-15' );
$m4      = Css_Tc_Payday::period_for( array( 'frequency' => 'monthly', 'anchor' => '2026-01-31', 'payday_mode' => 'on_end', 'shift' => 'keep' ), '2026-02-28' );
$m5      = Css_Tc_Payday::period_for( array( 'frequency' => 'monthly', 'anchor' => '2024-01-31', 'payday_mode' => 'on_end', 'shift' => 'keep' ), '2024-02-15' );
$m6      = Css_Tc_Payday::period_for( array( 'frequency' => 'monthly', 'anchor' => '2024-01-31', 'payday_mode' => 'on_end', 'shift' => 'keep' ), '2024-02-29' );
css_tc_check( $m1 && '2026-10-18' === $m1['start'] && '2026-11-17' === $m1['end'], 'monthly period starts on the anchor day of the month' );
css_tc_check( $m2 && '2026-12-18' === $m2['start'] && '2027-01-17' === $m2['end'], 'monthly period crosses the year boundary' );
css_tc_check( $m3 && '2026-01-31' === $m3['start'] && '2026-02-27' === $m3['end'], 'monthly day 31 starts January 31 and ends the day before February 28' );
css_tc_check( $m4 && '2026-02-28' === $m4['start'] && '2026-03-30' === $m4['end'], 'monthly day 31 uses February 28 as the next start' );
css_tc_check( $m5 && '2024-01-31' === $m5['start'] && '2024-02-28' === $m5['end'], 'monthly day 31 in a leap year ends the day before February 29' );
css_tc_check( $m6 && '2024-02-29' === $m6['start'] && '2024-03-30' === $m6['end'], 'monthly day 31 uses leap day as the February start' );

$year = Css_Tc_Payday::period_for( css_tc_owner_config( 'weekday' ), '2026-12-31' );
css_tc_check( $year && '2026-12-27' === $year['start'] && '2027-01-09' === $year['end'] && '2027-01-15' === $year['payday'], 'bi-weekly period crosses into 2027 and pays the Friday after' );
$year_days = Css_Tc_Payday::period_for( css_tc_owner_config( 'days' ), '2027-01-02' );
css_tc_check( $year_days && '2027-01-15' === $year_days['payday'], '6 days after the Saturday year-boundary end is the same Friday' );

css_tc_check( '2026-03-09' === Css_Tc_Payday::add_ymd( '2026-03-08', 1 ), 'the day after US spring-forward is the next calendar date' );
css_tc_check( '2026-03-22' === Css_Tc_Payday::add_ymd( '2026-03-08', 14 ), '14 days across spring-forward stays on the calendar' );
css_tc_check( '2026-11-02' === Css_Tc_Payday::add_ymd( '2026-11-01', 1 ), 'the day after US fall-back is the next calendar date' );
css_tc_check( '2026-11-15' === Css_Tc_Payday::add_ymd( '2026-11-01', 14 ), '14 days across fall-back does not repeat a date' );
$dst = Css_Tc_Payday::period_for( array( 'frequency' => 'weekly', 'anchor' => '2026-03-01', 'payday_mode' => 'days', 'payday_days' => 0, 'shift' => 'keep' ), '2026-03-08' );
css_tc_check( $dst && '2026-03-08' === $dst['start'] && '2026-03-14' === $dst['end'], 'a weekly period that starts on spring-forward is seven real dates' );
$walk = '2026-03-08';
$ok_walk = true;
for ( $i = 0; $i < 6; $i++ ) {
	$next = Css_Tc_Payday::add_ymd( $walk, 1 );
	if ( $next !== Css_Tc_Payday::add_ymd( '2026-03-08', $i + 1 ) ) {
		$ok_walk = false;
	}
	$walk = $next;
}
css_tc_check( $ok_walk && '2026-03-14' === $walk, 'spring-forward week has no skipped Y-m-d' );

$shift_prev = array( 'frequency' => 'weekly', 'anchor' => '2026-10-24', 'payday_mode' => 'days', 'payday_days' => 1, 'shift' => 'previous', 'holidays' => '' );
$sat        = Css_Tc_Payday::period_for( $shift_prev, '2026-10-24' );
css_tc_check( $sat && '2026-10-30' === $sat['end'] && '2026-10-31' === $sat['raw_payday'] && '2026-10-30' === $sat['payday'], 'a Saturday payday moves to the previous Friday' );
$shift_next = $shift_prev;
$shift_next['shift'] = 'next';
$sat_next   = Css_Tc_Payday::period_for( $shift_next, '2026-10-24' );
css_tc_check( $sat_next && '2026-11-02' === $sat_next['payday'], 'a Saturday payday moves to the next Monday' );
$shift_keep = $shift_prev;
$shift_keep['shift'] = 'keep';
css_tc_check( '2026-10-31' === Css_Tc_Payday::period_for( $shift_keep, '2026-10-24' )['payday'], 'keep leaves the Saturday payday' );

$holiday = css_tc_owner_config( 'weekday' );
$holiday['holidays'] = "2026-11-06 Office closed\n11-27\nnot-a-date";
$parsed = Css_Tc_Payday::parse_holidays( $holiday['holidays'] );
css_tc_check( isset( $parsed['exact']['2026-11-06'] ) && isset( $parsed['annual']['11-27'] ) && in_array( 'not-a-date', $parsed['invalid'], true ), 'holiday lines accept YYYY-MM-DD and MM-DD and report junk' );
$moved_pay = Css_Tc_Payday::period_for( $holiday, '2026-10-26' );
css_tc_check( $moved_pay && '2026-11-06' === $moved_pay['raw_payday'] && '2026-11-05' === $moved_pay['payday'], 'a Friday holiday payday moves to Thursday' );
$annual = $holiday;
$annual['holidays'] = '11-06';
css_tc_check( '2026-11-05' === Css_Tc_Payday::period_for( $annual, '2026-10-18' )['payday'], 'an annual MM-DD holiday moves that payday every year' );
$chain = $shift_prev;
$chain['holidays'] = '2026-10-30';
css_tc_check( '2026-10-29' === Css_Tc_Payday::period_for( $chain, '2026-10-24' )['payday'], 'previous business day walks through a Friday holiday off a Saturday' );

$series_holiday = css_tc_owner_config( 'anchor' );
$series_holiday['holidays'] = '2026-11-06';
$series_hit = Css_Tc_Payday::next_payday( $series_holiday, '2026-10-26' );
$series_later = Css_Tc_Payday::series_on_or_after( '2026-10-23', '2026-11-07', 14 );
css_tc_check( '2026-11-05' === $series_hit && '2026-11-20' === $series_later, 'a holiday moves that series payday only; the next one stays 14 days from the anchor' );

$blocked = '';
for ( $i = 0; $i < 30; $i++ ) {
	$blocked .= ( $i ? "\n" : '' ) . Css_Tc_Payday::add_ymd( '2026-10-31', -$i );
}
$long = Css_Tc_Payday::shift_payday( '2026-10-31', array( 'shift' => 'previous', 'holidays' => $blocked ) );
css_tc_check( Css_Tc_Payday::is_ymd( $long ) && '2026-10-31' !== $long, 'a long holiday stretch still returns a calendar date' );

$later_weekly = array(
	'frequency'      => 'weekly',
	'anchor'         => '2026-10-18',
	'payday_mode'    => 'weekday',
	'payday_weekday' => 5,
	'shift'          => 'keep',
);
$hist = css_tc_owner_history( 'weekday' );
$hist = Css_Tc_Payday::with_snapshot( $hist, '2026-11-05', $later_weekly, '2026-11-05 09:00:00' );
$kept = Css_Tc_Payday::period_covering( $hist, '2026-11-10' );
$fresh = Css_Tc_Payday::period_covering( $hist, '2026-11-16' );
css_tc_check( 2 === count( $hist ), 'a second save keeps the earlier snapshot' );
css_tc_check( $kept && '2026-11-01' === $kept['start'] && '2026-11-14' === $kept['end'] && '2026-11-20' === $kept['payday'], 'a period that already started keeps Nov 1–14 and Nov 20' );
css_tc_check( $fresh && '2026-11-15' === $fresh['start'] && '2026-11-21' === $fresh['end'], 'the day after that period uses the new weekly schedule' );
$replaced = Css_Tc_Payday::with_snapshot( $hist, '2026-11-05', css_tc_owner_config( 'weekday' ), 'again' );
css_tc_check( 2 === count( $replaced ) && 'again' === $replaced[1]['saved_at'], 'saving the same effective date replaces that row' );
$stored = Css_Tc_Payday::storage_rows( $hist );
css_tc_check( ! isset( $stored[0]['config']['holiday_set'] ) && isset( Css_Tc_Payday::normalize_history( $stored )[0]['config']['holiday_set'] ), 'stored history drops the derived holiday set and reloads it' );

$clean = Css_Tc_Payday::sanitize_fields( array( 'anchor' => '2026-10-18', 'effective' => '2026-10-26', 'payday_mode' => 'weekday', 'holidays' => "11-26\nnope" ) );
css_tc_check( in_array( 'holidays', $clean['errors'], true ) && ! in_array( 'anchor', $clean['errors'], true ), 'a bad holiday line blocks the save' );
$need_anchor = Css_Tc_Payday::sanitize_fields( array( 'payday_mode' => 'anchor', 'anchor' => '2026-10-18', 'effective' => '2026-10-26' ) );
css_tc_check( in_array( 'payday_anchor', $need_anchor['errors'], true ), 'the 14-day rule requires a payday anchor' );
$on_end_weekly = Css_Tc_Payday::normalize_config( array( 'frequency' => 'weekly', 'payday_mode' => 'on_end', 'anchor' => '2026-10-18' ) );
css_tc_check( 'weekday' === $on_end_weekly['payday_mode'], 'on the period end is not kept for a weekly schedule' );

$band_old = Css_Tc_Payday::period_covering( css_tc_owner_history( 'weekday' ), '2026-10-10' );
$band_new = Css_Tc_Payday::period_covering( css_tc_owner_history( 'weekday' ), '2026-10-18' );
css_tc_check( $band_old && 1 === (int) $band_old['band'] && $band_new && 0 === (int) $band_new['band'], 'periods before the anchor still alternate colors' );

$view = Css_Tc_Payday::month_view(
	css_tc_owner_history( 'weekday' ),
	2026,
	11,
	'2026-10-26',
	true,
	array( '2026-11-02' => '8.0 h' ),
	array( '2026-11-26' => 'Thanksgiving' )
);
css_tc_check( $view && 'November 2026' === $view['title'] && 'Sun' === $view['weekdays'][0] && '2026-11-01' === $view['weeks'][0][0]['date'], 'November 2026 opens on Sunday the 1st' );
$by_date = array();
foreach ( $view['weeks'] as $css_tc_week ) {
	foreach ( $css_tc_week as $css_tc_cell ) {
		$by_date[ $css_tc_cell['date'] ] = $css_tc_cell;
	}
}
css_tc_check( isset( $by_date['2026-11-01'] ) && false !== strpos( $by_date['2026-11-01']['label'], 'Nov 1 – Nov 14' ), 'Nov 1 carries the period label' );
css_tc_check( isset( $by_date['2026-11-08'] ) && false !== strpos( $by_date['2026-11-08']['label'], 'Nov 1 – Nov 14' ), 'the next Sunday repeats the period label' );
css_tc_check( isset( $by_date['2026-11-02'] ) && '' === $by_date['2026-11-02']['label'] && $by_date['2026-11-02']['bar'] && '8.0 h' === $by_date['2026-11-02']['hours'], 'a middle day keeps the band and the hours' );
css_tc_check( ! empty( $by_date['2026-11-06']['payday'] ) && ! empty( $by_date['2026-11-20']['payday'] ) && empty( $by_date['2026-11-13']['payday'] ), 'November paydays are Nov 6 and Nov 20' );
css_tc_check( ! empty( $by_date['2026-12-04']['payday'] ), 'Dec 4 payday is on the November grid' );
css_tc_check( ! empty( $by_date['2026-11-14']['closes'] ) && ! empty( $by_date['2026-11-28']['closes'] ) && ! empty( $view['show_closes'] ), 'admin period-closes marks Nov 14 and Nov 28' );
css_tc_check( 'Thanksgiving' === $by_date['2026-11-26']['holiday'], 'office holiday label is passed through' );
$employee = Css_Tc_Payday::month_view( css_tc_owner_history( 'weekday' ), 2026, 11, '2026-10-26', false );
css_tc_check( $employee && empty( $employee['show_closes'] ), 'employee calendars do not show period closes' );
css_tc_check( false !== strpos( Css_Tc_Payday::widget_line( css_tc_owner_history( 'weekday' ), '2026-10-26' ), 'Fri, Nov 6, 2026' ), 'widget line names Fri, Nov 6, 2026' );

$off = css_tc_owner_config( 'weekday' );
$off['show_employee'] = 0;
$off['show_admin']    = 0;
$hidden = Css_Tc_Payday::with_snapshot( array(), '2026-10-18', $off, '' );
css_tc_check( ! Css_Tc_Payday::visible( $hidden, 'employee' ) && ! Css_Tc_Payday::visible( $hidden, 'admin' ), 'display toggles can hide both calendars after a save' );
css_tc_check( '' !== Css_Tc_Payday::widget_line( $hidden, '2026-10-26' ), 'the widget still names the next payday when calendars are hidden' );

if ( $failed > 0 ) {
	echo "{$failed} failed\n";
	exit( 1 );
}
echo "all ok\n";
exit( 0 );
