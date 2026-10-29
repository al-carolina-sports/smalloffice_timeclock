<?php
/**
 * Missed-lunch rules. Run: php bin/check-lunch.php
 *
 * Does not boot WordPress. Hours, overtime, and pay are not part of this check.
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

require_once dirname( __DIR__ ) . '/includes/class-time.php';
require_once dirname( __DIR__ ) . '/includes/class-lunch.php';

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

$defaults = Css_Tc_Lunch::defaults();
css_tc_check( 0 === (int) $defaults['lunch_alert_enabled'], 'lunch alerts are off until turned on' );
css_tc_check( 4 === (int) $defaults['lunch_alert_hours'] && 30 === (int) $defaults['lunch_alert_break_minutes'], 'defaults are 4 hours and a 30 minute break' );

$rules = Css_Tc_Lunch::rules_from( $defaults );
$four  = 4 * 3600;
$five  = 5 * 3600;

$none = array(
	array(
		'in_ts'  => 0,
		'out_ts' => $five,
		'open'   => false,
	),
);
$exact = Css_Tc_Lunch::assess( $four, $none, $rules );
css_tc_check( empty( $exact['missed'] ) && empty( $exact['has_lunch'] ), 'exactly 4 hours with no meal is not a missed lunch' );
$over = Css_Tc_Lunch::assess( $four + 60, $none, $rules );
css_tc_check( ! empty( $over['missed'] ), '4 hours and 1 minute with no meal is a missed lunch' );
$short = Css_Tc_Lunch::assess( 3 * 3600, $none, $rules );
css_tc_check( empty( $short['missed'] ), '3 hours with no meal is not a missed lunch' );

$gap_ok = array(
	array( 'in_ts' => 0, 'out_ts' => 3 * 3600, 'open' => false ),
	array( 'in_ts' => 3 * 3600 + 30 * 60, 'out_ts' => $five + 30 * 60, 'open' => false ),
);
css_tc_check( empty( Css_Tc_Lunch::assess( $five, $gap_ok, $rules )['missed'] ) && Css_Tc_Lunch::has_lunch( $gap_ok, 30 * 60 ), 'a 30 minute gap counts as lunch' );

$gap_short = array(
	array( 'in_ts' => 0, 'out_ts' => 3 * 3600, 'open' => false ),
	array( 'in_ts' => 3 * 3600 + 29 * 60, 'out_ts' => $five, 'open' => false ),
);
css_tc_check( ! empty( Css_Tc_Lunch::assess( $five, $gap_short, $rules )['missed'] ), 'a 29 minute gap is not lunch' );

$switch = array(
	array( 'in_ts' => 1000, 'out_ts' => 1000 + $five, 'open' => false ),
	array( 'in_ts' => 1000 + $five, 'out_ts' => 1000 + 8 * 3600, 'open' => false ),
);
css_tc_check( ! Css_Tc_Lunch::has_lunch( $switch, 30 * 60 ) && ! empty( Css_Tc_Lunch::assess( 8 * 3600, $switch, $rules )['missed'] ), 'a zero-second department switch is not lunch' );

$meal = array(
	array( 'in_ts' => 0, 'out_ts' => $five, 'open' => false, 'is_meal' => true, 'break_seconds' => 10 * 60 ),
);
css_tc_check( Css_Tc_Lunch::has_lunch( $meal, 30 * 60 ) && empty( Css_Tc_Lunch::assess( $five, $meal, $rules )['missed'] ), 'a lunch or meal punch counts even when it is shorter than the minimum' );

$break_long = array(
	array( 'in_ts' => 0, 'out_ts' => $five, 'open' => false, 'break_seconds' => 30 * 60 ),
);
$break_short = array(
	array( 'in_ts' => 0, 'out_ts' => $five, 'open' => false, 'break_seconds' => 10 * 60 ),
);
css_tc_check( Css_Tc_Lunch::has_lunch( $break_long, 30 * 60 ) && ! Css_Tc_Lunch::has_lunch( $break_short, 30 * 60 ), 'a stored break counts only at the minimum length' );

$meta_meal = Css_Tc_Lunch::break_from_meta( array( 'break_type' => 'Lunch', 'employee_break_time' => 10 ) );
$meta_len  = Css_Tc_Lunch::break_from_meta( array( 'aio_break_length' => 30 ) );
css_tc_check( ! empty( $meta_meal['is_meal'] ) && 600 === (int) $meta_meal['break_seconds'], 'a Lunch break type is a meal' );
css_tc_check( 1800 === (int) $meta_len['break_seconds'] && empty( $meta_len['is_meal'] ), 'a numeric break length is minutes' );

$rendered = Css_Tc_Lunch::render( $defaults['lunch_alert_body'], 'Ada Lovelace', 'Oct 26, 2026', '5:00' );
css_tc_check( 'Ada Lovelace worked 5:00 on Oct 26, 2026. No lunch was recorded.' === $rendered, 'the email names the employee, date, hours, and that no lunch was recorded' );

$people = Css_Tc_Lunch::recipients(
	$rules,
	'Ada@Example.com',
	array( 'ada@example.com', 'francis@example.com' )
);
css_tc_check( array( 'Ada@Example.com', 'francis@example.com' ) === $people, 'the employee is not emailed twice when they are also an admin' );

$with_extra = $rules;
$with_extra['extra'] = "alvin@example.com\nnot-an-email";
$parsed = Css_Tc_Lunch::parse_extra( $with_extra['extra'] );
css_tc_check( array( 'alvin@example.com' ) === $parsed['emails'] && array( 'not-an-email' ) === $parsed['invalid'], 'extra addresses keep real emails and report junk' );
$clean = Css_Tc_Lunch::sanitize_input(
	array(
		'lunch_alert_enabled'       => 1,
		'lunch_alert_hours'         => 20,
		'lunch_alert_break_minutes' => 0,
		'lunch_alert_extra'         => 'not-an-email',
		'lunch_alert_subject'       => '',
		'lunch_alert_body'          => '<b>Hi</b>',
	)
);
css_tc_check( 16.0 === (float) $clean['settings']['lunch_alert_hours'] && 1 === (int) $clean['settings']['lunch_alert_break_minutes'], 'hours and minutes stay inside their limits' );
css_tc_check( array( 'not-an-email' ) === $clean['invalid'], 'a bad extra address is reported' );
css_tc_check( $defaults['lunch_alert_subject'] === $clean['settings']['lunch_alert_subject'] && 'Hi' === $clean['settings']['lunch_alert_body'], 'an empty subject uses the default and tags are removed from the body' );

$log = array();
css_tc_check( ! Css_Tc_Lunch::was_sent( $log, '2026-10-26' ), 'a day with no log has not been emailed' );
$log = Css_Tc_Lunch::remember(
	$log,
	'2026-10-26',
	array(
		'ok'      => 1,
		'to'      => array( 'ada@example.com' ),
		'subject' => 'No lunch recorded: Ada Lovelace on Oct 26, 2026',
		'body'    => $rendered,
		'hours'   => '5:00',
	)
);
css_tc_check( Css_Tc_Lunch::was_sent( $log, '2026-10-26' ) && '5:00' === $log['2026-10-26']['hours'], 'a sent day is logged once' );
css_tc_check( ! Css_Tc_Lunch::was_sent( array( '2026-10-26' => array( 'ok' => 0 ) ), '2026-10-26' ), 'a failed send can be tried again' );

$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunch.php' );
css_tc_check( false !== strpos( $src, "if ( 'switch' === (string) \$source" ) && false !== strpos( $src, 'css_tc_after_clock_out' ), 'clock-out sends the alert and a department switch does not' );
css_tc_check( false === stripos( $src, 'alvin@' ) && false === stripos( $src, 'francis@' ), 'recipient addresses are not hard-coded' );
$settings_view = file_get_contents( dirname( __DIR__ ) . '/admin/views/settings-page.php' );
css_tc_check( false !== strpos( $settings_view, 'Lunch alerts' ) && false !== strpos( $settings_view, 'lunch_alert_enabled' ), 'TC-Config has the Lunch alerts section' );
$sheet = file_get_contents( dirname( __DIR__ ) . '/public/views/timecard-sheet.php' );
css_tc_check( false !== strpos( $sheet, 'missed_lunch' ) && false !== strpos( $sheet, 'No lunch recorded' ), 'employee and admin timecards can show the missed-lunch flag' );
$overtime = file_get_contents( dirname( __DIR__ ) . '/includes/class-overtime.php' );
css_tc_check( false === strpos( $overtime, 'lunch' ), 'overtime math does not mention lunch' );

if ( $failed > 0 ) {
	echo "{$failed} failed\n";
	exit( 1 );
}
echo "all ok\n";
exit( 0 );
