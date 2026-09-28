<?php
/**
 * PTO / sick leave maths. Run: php bin/check-leave.php
 *
 * @package CssTimeclockAddon
 */

if ( PHP_SAPI !== 'cli' ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/New_York' );
if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $s Text.
	 * @return string
	 */
	function __( $s ) {
		return $s;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal stand-in. */
	class WP_Error {
		/** @var string */
		public $code;
		/** @var string */
		public $message;
		/**
		 * @param string $code Code.
		 * @param string $message Message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		/** @return string */
		public function get_error_code() {
			return $this->code;
		}
		/** @return string */
		public function get_error_message() {
			return $this->message;
		}
	}
}
require_once dirname( __DIR__ ) . '/includes/class-leave.php';
require_once dirname( __DIR__ ) . '/includes/class-status.php';

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
/**
 * @param mixed $r Result.
 * @return string Error code or 'ok'.
 */
function code( $r ) {
	return $r instanceof WP_Error ? $r->get_error_code() : 'ok';
}

$L = 'Css_Tc_Leave';
$h = 3600;

// Leave years from the hire date.
$c = $L::cycle( '2026-09-01', '2026-11-30' );
css_tc_check( 0 === $c['index'] && '2026-09-01' === $c['start'] && '2027-08-31' === $c['end'], 'first year: Sep 1 2026 – Aug 31 2027' );
$c = $L::cycle( '2026-09-01', '2027-09-01' );
css_tc_check( 1 === $c['index'] && '2027-09-01' === $c['start'] && '2028-08-31' === $c['end'], 'anniversary starts year two' );
$c = $L::cycle( '2026-09-01', '2027-08-31' );
css_tc_check( 0 === $c['index'], 'day before anniversary is still year one' );
css_tc_check( null === $L::cycle( '2026-09-01', '2026-08-31' ), 'no leave year before the hire date' );
css_tc_check( null === $L::cycle( '', '2026-08-31' ), 'no leave year without a hire date' );
css_tc_check( '2025-02-28' === $L::anniversary( '2024-02-29', 1 ) && '2028-02-29' === $L::anniversary( '2024-02-29', 4 ), 'Feb 29 hire: Feb 28 in other years' );
$c = $L::cycle( '2024-02-29', '2025-02-28' );
css_tc_check( 1 === $c['index'], 'Feb 29 hire reaches year two on Feb 28' );

// Allowances.
$practice = array(
	'pto_enabled'           => 1,
	'pto_hours_year'        => 40,
	'pto_first_year_hours'  => 24,
	'sick_enabled'          => 1,
	'sick_hours_year'       => 24,
	'sick_first_year_hours' => 0,
	'pto_notice_days'       => 14,
	'leave_day_hours'       => 8,
	'leave_increment'       => 60,
);
$lv = new Css_Tc_Leave( $practice, '2026-12-01' );
css_tc_check( 24 * $h === $lv->allowance_seconds( 'pto', 0 ), 'year one PTO: 3 days (24 h)' );
css_tc_check( 0 === $lv->allowance_seconds( 'sick', 0 ), 'year one sick: 0 (practice setting)' );
css_tc_check( 40 * $h === $lv->allowance_seconds( 'pto', 1 ) && 24 * $h === $lv->allowance_seconds( 'sick', 3 ), 'later years: yearly amounts' );
css_tc_check( 56 * $h === $lv->allowance_seconds( 'pto', 2, array( 'pto_year' => '56' ) ), 'per-employee yearly override' );
css_tc_check( 40 * $h === $lv->allowance_seconds( 'pto', 0, array( 'pto_first' => '40' ) ), 'per-employee first-year override' );
$generic = new Css_Tc_Leave( array_merge( $practice, array( 'sick_first_year_hours' => '' ) ), '2026-12-01' );
css_tc_check( 24 * $h === $generic->allowance_seconds( 'sick', 0 ), 'blank first-year sick = yearly amount' );
css_tc_check( 0 === ( new Css_Tc_Leave( array_merge( $practice, array( 'pto_enabled' => 0 ) ) ) )->allowance_seconds( 'pto', 1 ), 'PTO off: no allowance' );

// One bank.
$one = new Css_Tc_Leave( array_merge( $practice, array( 'sick_from_pto' => 1 ) ), '2026-12-01' );
css_tc_check( 'pto' === $one->bank_for( 'sick' ) && array( 'pto' ) === $one->banks(), 'one bank: sick draws on PTO' );
css_tc_check( 0 === $one->allowance_seconds( 'sick', 1 ), 'one bank: no separate sick allowance' );

// Tally: use it or lose it (only this year's records count).
$recs = array(
	array( 'date' => '2027-08-20', 'type' => 'pto', 'seconds' => 8 * $h, 'status' => 'approved' ), // year one
	array( 'date' => '2027-10-05', 'type' => 'pto', 'seconds' => 8 * $h, 'status' => 'approved' ),
	array( 'date' => '2027-10-06', 'type' => 'pto', 'seconds' => 4 * $h, 'status' => 'pending' ),
	array( 'date' => '2027-10-07', 'type' => 'pto', 'seconds' => 8 * $h, 'status' => 'denied' ),
	array( 'date' => '2027-10-08', 'type' => 'sick', 'seconds' => 8 * $h, 'status' => 'approved' ),
);
$adj  = array( array( 'bank' => 'pto', 'seconds' => 2 * $h, 'cycle_start' => '2027-09-01' ), array( 'bank' => 'pto', 'seconds' => 99 * $h, 'cycle_start' => '2026-09-01' ) );
$y2   = $L::cycle( '2026-09-01', '2027-10-01' );
$t    = $lv->tally( 40 * $h, $recs, $adj, $y2, 'pto' );
css_tc_check( 8 * $h === $t['used'] && 4 * $h === $t['pending'] && 2 * $h === $t['adjust'] && 30 * $h === $t['left'], 'year two PTO: 40 + 2 adj − 8 used − 4 pending = 30 (year one and denied ignored)' );
$t1 = $lv->tally( 24 * $h, $recs, $adj, $L::cycle( '2026-09-01', '2027-01-01' ), 'pto' );
css_tc_check( 16 * $h + 99 * $h === $t1['left'], 'year one keeps its own records and adjustments' );
$ts = $one->tally( 40 * $h, $recs, array(), $y2, 'pto' );
css_tc_check( 16 * $h === $ts['used'], 'one bank: sick day counted against PTO' );

// Requests.
$ctx = array(
	'hire'         => '2026-09-01',
	'usable_from'  => '2026-11-30',
	'overrides'    => array(),
	'records'      => array(),
	'adjustments'  => array(),
	'holidays'     => array( '2026-12-25' => true ),
	'period_start' => '2026-11-30',
);
css_tc_check( 'ok' === code( $lv->check_request( $ctx, array( '2026-12-21' => 8 * $h, '2026-12-22' => 8 * $h ), 'pto', false ) ), '2 days PTO 20 days ahead: ok' );
css_tc_check( 'css_tc_leave_notice' === code( $lv->check_request( $ctx, array( '2026-12-10' => 8 * $h ), 'pto', false ) ), 'PTO 9 days ahead refused (14-day notice)' );
css_tc_check( 'ok' === code( $lv->check_request( $ctx, array( '2026-12-10' => 8 * $h ), 'pto', true ) ), 'manager can skip the notice rule' );
css_tc_check( 'css_tc_leave_type' === code( ( new Css_Tc_Leave( array_merge( $practice, array( 'sick_enabled' => 0 ) ), '2026-12-01' ) )->check_request( $ctx, array( '2026-12-01' => 8 * $h ), 'sick', false ) ), 'sick off: refused' );
css_tc_check( 'css_tc_leave_balance' === code( $lv->check_request( $ctx, array( '2026-12-01' => 8 * $h ), 'sick', false ) ), 'sick in year one refused: 0 h allowance' );
css_tc_check( 'css_tc_leave_balance' === code( $lv->check_request( $ctx, array( '2026-12-21' => 8 * $h, '2026-12-22' => 8 * $h, '2026-12-23' => 8 * $h, '2026-12-24' => 1 * $h ), 'pto', false ) ), '25 h asked, 24 h year-one allowance: refused' );
css_tc_check( 'ok' === code( $lv->check_request( $ctx, array( '2026-12-21' => 8 * $h, '2026-12-22' => 8 * $h, '2026-12-23' => 8 * $h ), 'pto', false ) ), 'exactly 24 h: ok' );
$ctx_intro = array_merge( $ctx, array( 'usable_from' => '2027-01-15' ) );
css_tc_check( 'css_tc_leave_intro' === code( $lv->check_request( $ctx_intro, array( '2026-12-21' => 8 * $h ), 'pto', false ) ), 'during the introductory period: refused' );
css_tc_check( 'css_tc_leave_intro' === code( $lv->check_request( $ctx_intro, array( '2026-12-21' => 8 * $h ), 'pto', true ) ), 'managers cannot bypass the introductory period' );
css_tc_check( 'css_tc_leave_holiday' === code( $lv->check_request( $ctx, array( '2026-12-25' => 8 * $h ), 'pto', true ) ), 'a paid holiday cannot be taken as PTO' );
css_tc_check( 'css_tc_leave_hours' === code( $lv->check_request( $ctx, array( '2026-12-21' => 5400 ), 'pto', false ) ), '1.5 h refused with 1-hour steps' );
$half = new Css_Tc_Leave( array_merge( $practice, array( 'leave_increment' => 30 ) ), '2026-12-01' );
css_tc_check( 'ok' === code( $half->check_request( $ctx, array( '2026-12-21' => 5400 ), 'pto', false ) ), '1.5 h ok with 30-minute steps' );
$taken = array_merge( $ctx, array( 'records' => array( array( 'date' => '2026-12-21', 'type' => 'pto', 'seconds' => 8 * $h, 'status' => 'pending' ) ) ) );
css_tc_check( 'css_tc_leave_overlap' === code( $lv->check_request( $taken, array( '2026-12-21' => 4 * $h ), 'pto', false ) ), 'a day already requested is refused' );
css_tc_check( 'css_tc_leave_balance' === code( $lv->check_request( $taken, array( '2026-12-28' => 8 * $h, '2026-12-29' => 8 * $h, '2026-12-30' => 8 * $h ), 'pto', false ) ), 'pending hours count against the balance' );
css_tc_check( 'css_tc_leave_hire' === code( $lv->check_request( array_merge( $ctx, array( 'hire' => '' ) ), array( '2026-12-21' => 8 * $h ), 'pto', false ) ), 'no hire date: refused' );
css_tc_check( 'ok' === code( ( new Css_Tc_Leave( array_merge( $practice, array( 'leave_allow_negative' => 1 ) ), '2026-12-01' ) )->check_request( $ctx, array( '2026-12-01' => 8 * $h ), 'sick', false ) ), 'negative balance allowed: ok' );

// A request across the anniversary is split between the two years.
$late = new Css_Tc_Leave( $practice, '2027-08-01' );
$span = array_merge( $ctx, array( 'records' => array( array( 'date' => '2027-06-01', 'type' => 'pto', 'seconds' => 16 * $h, 'status' => 'approved' ) ), 'period_start' => '2027-07-26' ) );
css_tc_check( 'css_tc_leave_balance' === code( $late->check_request( $span, array( '2027-08-30' => 8 * $h, '2027-08-31' => 8 * $h, '2027-09-01' => 8 * $h ), 'pto', false ) ), 'Aug 30–Sep 1: 16 h fall in year one where only 8 h are left: refused' );
css_tc_check( 'ok' === code( $late->check_request( $span, array( '2027-08-31' => 8 * $h, '2027-09-01' => 8 * $h, '2027-09-02' => 8 * $h ), 'pto', false ) ), 'Aug 31–Sep 2: 8 h from year one, 16 h from year two: ok' );

// Employment status.
$onleave = array_merge( $ctx, array( 'status' => array( 'status' => 'leave', 'leave_from' => '2026-12-14', 'leave_to' => '2026-12-31', 'last_day' => '' ) ) );
css_tc_check( 'css_tc_leave_onleave' === code( $lv->check_request( $onleave, array( '2026-12-21' => 8 * $h ), 'pto', false ) ), 'employee on leave cannot request PTO' );
css_tc_check( 'ok' === code( $lv->check_request( $onleave, array( '2026-12-21' => 8 * $h ), 'pto', true ) ), 'manager can add PTO during leave' );
$left = array_merge( $ctx, array( 'status' => array( 'status' => 'inactive', 'leave_from' => '', 'leave_to' => '', 'last_day' => '2026-12-18' ) ) );
css_tc_check( 'css_tc_leave_inactive' === code( $lv->check_request( $left, array( '2026-12-21' => 8 * $h ), 'pto', true ) ), 'no time off after the last day, even by a manager' );
css_tc_check( 'ok' === code( $lv->check_request( $left, array( '2026-12-18' => 8 * $h ), 'pto', true ) ), 'time off on the last day is fine' );
$withhol = new Css_Tc_Leave( array_merge( $practice, array( 'holidays_enabled' => 1 ) ), '2026-12-01' );
css_tc_check( 'ok' === code( $withhol->check_request( $onleave, array( '2026-12-21' => 8 * $h, '2026-12-22' => 8 * $h, '2026-12-23' => 8 * $h, '2026-12-24' => 8 * $h ), 'holiday', true ) ), 'manager-entered holiday uses no balance (32 h > 24 h PTO is fine)' );
css_tc_check( 'css_tc_leave_type' === code( $withhol->check_request( $ctx, array( '2026-12-21' => 8 * $h ), 'holiday', false ) ), 'employees cannot request the holiday type' );
css_tc_check( 'css_tc_leave_type' === code( $lv->check_request( $ctx, array( '2026-12-21' => 8 * $h ), 'holiday', true ) ), 'holiday type off when holidays are off' );

if ( $failed ) {
	fwrite( STDERR, "{$failed} check(s) failed\n" );
	exit( 1 );
}
echo "all checks passed\n";
