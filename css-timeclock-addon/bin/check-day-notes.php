<?php
/**
 * Day notes and the duplicate-save guards. Run: php bin/check-day-notes.php
 *
 * These checks do not boot WordPress. A manual pass on a site is listed in
 * the 1.10.4 pull request.
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
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function sanitize_textarea_field( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		return trim( strip_tags( $text ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-day-notes.php';
require_once dirname( __DIR__ ) . '/includes/class-corrections.php';
require_once dirname( __DIR__ ) . '/includes/class-time.php';
require_once dirname( __DIR__ ) . '/includes/class-pay-codes.php';
require_once dirname( __DIR__ ) . '/includes/class-reports.php';

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

$N = 'Css_Tc_Day_Notes';
$C = 'Css_Tc_Corrections';

css_tc_check( $N::may_edit( true, 2, 5, true, false ), 'manager may note an employee' );
css_tc_check( ! $N::may_edit( false, 5, 5, true, false ), 'employee cannot edit a note' );
css_tc_check( ! $N::may_edit( true, 1, 9, true, true ), 'administrator target is refused' );
css_tc_check( ! $N::may_edit( true, 1, 9, false, false ), 'non-employee target is refused' );
css_tc_check( ! $N::may_edit( true, 0, 5, true, false ), 'logged-out actor is refused' );
css_tc_check( $N::presets() === array( 'Out sick', 'Personal day', 'No call/no show', 'Excused' ), 'preset buttons' );

$long = str_repeat( 'a', 600 );
css_tc_check( 500 === strlen( $N::sanitize_text( $long ) ), 'note is capped at 500 characters' );
css_tc_check( 'hello' === $N::sanitize_text( " <b>hello</b> \n" ), 'note is sanitized as textarea text' );

$added = $N::apply_change( array(), '2026-10-22', 'Out sick', 7, 1000 );
css_tc_check( $added['changed'] && 'Out sick' === $added['map']['2026-10-22']['text'], 'manager can add a note' );
css_tc_check( 7 === $added['map']['2026-10-22']['by'] && 1000 === $added['map']['2026-10-22']['at'], 'first save records who and when' );
css_tc_check(
	isset( $added['audit']['date'], $added['audit']['before'], $added['audit']['after'], $added['audit']['by'], $added['audit']['at'] )
	&& '' === $added['audit']['before']
	&& 'Out sick' === $added['audit']['after']
	&& 7 === $added['audit']['by'],
	'audit row is date, before, after, by, at'
);

$edited = $N::apply_change( $added['map'], '2026-10-22', 'Personal day', 8, 2000 );
css_tc_check( 7 === $edited['map']['2026-10-22']['by'] && 1000 === $edited['map']['2026-10-22']['at'], 'edit keeps the original author' );
css_tc_check( 8 === $edited['map']['2026-10-22']['updated_by'] && 2000 === $edited['map']['2026-10-22']['updated_at'], 'edit records who updated it' );
css_tc_check( 'Out sick' === $edited['audit']['before'] && 'Personal day' === $edited['audit']['after'], 'audit stores before and after' );

$same = $N::apply_change( $edited['map'], '2026-10-22', 'Personal day', 8, 3000 );
css_tc_check( ! $same['changed'] && null === $same['audit'], 'saving the same text does not add an audit row' );

$cleared = $N::apply_change( $edited['map'], '2026-10-22', '   ', 8, 4000 );
css_tc_check( $cleared['changed'] && ! isset( $cleared['map']['2026-10-22'] ) && '' === $cleared['audit']['after'], 'clearing the text deletes the note' );

$log = array();
for ( $i = 0; $i < 205; $i++ ) {
	$log = $N::append_audit(
		$log,
		array(
			'date'   => '2026-10-22',
			'before' => (string) $i,
			'after'  => 'n',
			'by'     => 1,
			'at'     => $i,
		)
	);
}
css_tc_check( 200 === count( $log ) && 5 === (int) $log[0]['before'] && 204 === (int) $log[199]['at'], 'audit keeps the last 200' );

$notes = array(
	'2026-10-01' => array( 'text' => 'Out sick', 'by' => 1, 'at' => 1, 'updated_by' => 1, 'updated_at' => 1 ),
	'2026-09-01' => array( 'text' => 'Old', 'by' => 1, 'at' => 1, 'updated_by' => 1, 'updated_at' => 1 ),
	'2026-10-03' => array( 'text' => 'Excused', 'by' => 1, 'at' => 1, 'updated_by' => 1, 'updated_at' => 1 ),
);
css_tc_check(
	'2026-10-01: Out sick; 2026-10-03: Excused' === $N::format_period_notes( $notes, '2026-10-01', '2026-10-15' ),
	'period notes stay in date order and skip other periods'
);

css_tc_check( ! $N::day_has_content( array( 'shifts' => array(), 'has_time' => false, 'holiday' => '', 'leave' => array(), 'note' => null ) ), 'a blank day is empty' );
css_tc_check( $N::day_has_content( array( 'shifts' => array(), 'has_time' => false, 'holiday' => '', 'leave' => array(), 'note' => array( 'text' => 'Out sick' ) ) ), 'a note counts as content on a day with no shifts' );
css_tc_check( $N::day_has_content( array( 'shifts' => array( array( 'id' => 1 ) ), 'has_time' => true ) ), 'a shift still counts as content' );

$lines = array(
	array( 'shift_id' => 4, 'proposed_in' => '09:00', 'delete' => '' ),
	array( 'shift_id' => 0, 'proposed_in' => '13:00', 'proposed_out' => '17:00' ),
	array( 'shift_id' => 9, 'delete' => '1' ),
);
$only = $C::isolate_deletes( $lines );
css_tc_check( 1 === count( $only ) && 9 === (int) $only[0]['shift_id'], 'delete drops unsaved add-punch rows' );
css_tc_check( 2 === count( $C::isolate_deletes( array( $lines[0], $lines[1] ) ) ), 'a save with no delete still includes new rows' );

css_tc_check(
	$C::is_duplicate_add( array( array( 'in' => '2026-10-22 13:00:00', 'out' => '2026-10-22 21:00:00' ) ), '2026-10-22 13:00:00', '2026-10-22 21:00:00' ),
	'duplicate add matches the same clock-in and clock-out'
);
css_tc_check(
	! $C::is_duplicate_add( array( array( 'in' => '2026-10-22 13:00:00', 'out' => '2026-10-22 21:00:00' ) ), '2026-10-22 13:00:00', '2026-10-22 18:00:00' ),
	'a different clock-out is not a duplicate'
);
css_tc_check( 'css_tc_day_save_5_2026-10-22' === $C::day_lock_key( 5, '2026-10-22' ), 'day save lock is a short per-employee transient key' );

$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-day-notes.php' );
css_tc_check( false === strpos( $src, 'wp_insert_post' ) && false === strpos( $src, 'css_tc_correction' ), 'saving a note does not create a shift or a correction' );
$sheet = file_get_contents( dirname( __DIR__ ) . '/public/views/timecard-sheet.php' );
css_tc_check( false !== strpos( $sheet, 'css-tc-day__note' ) && false !== strpos( $sheet, 'by %1$s, %2$s' ), 'timecard prints the note with by Manager, date' );
css_tc_check( false !== strpos( $sheet, 'Css_Tc_Day_Notes::day_has_content' ), 'empty-day check counts a note' );
css_tc_check( false === strpos( $sheet, 'css-tc-day__note css-tc-no-print' ), 'the note is not marked hidden in print' );
$editor = file_get_contents( dirname( __DIR__ ) . '/admin/views/manager-day-edit.php' );
css_tc_check( false !== strpos( $editor, 'value="css_tc_day_note"' ) && false !== strpos( $editor, 'Day note' ), 'day note has its own save' );
$js = file_get_contents( dirname( __DIR__ ) . '/public/js/timecard.js' );
css_tc_check( false !== strpos( $js, 'data-css-tc-saving' ) && false !== strpos( $js, 'field.disabled = true' ), 'save buttons lock and delete omits other rows' );

$weeks = array(
	0 => array( 'label' => 'Week 1', 'range' => '10/06-10/12' ),
);
$header = Css_Tc_Reports::summary_csv_header( false, $weeks, array( 'Holiday' ) );
css_tc_check( 'Day notes' === $header[ count( $header ) - 1 ] && 'Overtime (hours)' === $header[4], 'summary CSV keeps overtime in place and adds Day notes last' );
css_tc_check( 'Day notes' === Css_Tc_Reports::shift_csv_header()[12], 'shift CSV has a Day notes column' );
css_tc_check( 'Day notes' === Css_Tc_Reports::by_employee_csv_header()[11], 'by-employee CSV has a Day notes column' );

$row = array(
	'name'       => 'Ada',
	'department' => 'Front',
	'shifts'     => 1,
	'codes'      => array( 'regular' => 8 * 3600 ),
	'total'      => 8 * 3600,
	'weeks'      => array( 0 => 8 * 3600 ),
	'attention'  => array(),
	'day_notes'  => '2026-10-22: Out sick',
);
$summary = array(
	'use_org' => false,
	'rule'    => array( 'enabled' => false ),
	'codes'   => array( 'regular' => array( 'label' => 'Regular' ) ),
	'weeks'   => $weeks,
	'rows'    => array( $row ),
);
$method = new ReflectionMethod( 'Css_Tc_Reports', 'summary_csv_lines' );
$method->setAccessible( true );
$csv = $method->invoke( new Css_Tc_Reports(), $summary );
css_tc_check( '8.00' === $csv[1][3] && '' === $csv[1][4] && '2026-10-22: Out sick' === $csv[1][ count( $csv[1] ) - 1 ], 'day notes do not move the hours columns' );

if ( $failed > 0 ) {
	fwrite( STDERR, "{$failed} check(s) failed\n" );
	exit( 1 );
}
echo "all checks passed\n";
exit( 0 );
