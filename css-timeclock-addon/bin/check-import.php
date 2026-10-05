<?php
/**
 * Bulk employee import rules. Run: php bin/check-import.php
 *
 * @package CssTimeclockAddon
 */

if ( PHP_SAPI !== 'cli' ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/class-import.php';

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

$I = 'Css_Tc_Import';

// ---- Headings --------------------------------------------------------
css_tc_check( 'first_name' === $I::canonical_header( 'First Name' ) && 'first_name' === $I::canonical_header( 'first_name' ), 'first name headings' );
css_tc_check( 'pin' === $I::canonical_header( 'Badge No.' ) && 'pin' === $I::canonical_header( 'PIN' ), 'Badge No. and PIN both mean the PIN' );
css_tc_check( 'hire_date' === $I::canonical_header( 'Hire Date' ), 'hire date heading' );
css_tc_check( '' === $I::canonical_header( 'Favorite color' ), 'unknown heading ignored' );

// ---- Parsing ---------------------------------------------------------
$p = $I::parse_csv( "First Name,Last Name,Email,Notes\nJamie,Rivera,jamie@example.com,hi\n" );
css_tc_check( $p['ok'] && 1 === count( $p['rows'] ) && 'Jamie' === $p['rows'][0]['cells']['first_name'], 'basic file parses' );
css_tc_check( array( 'Notes' ) === $p['ignored'], 'unknown column reported as ignored' );
css_tc_check( 2 === $p['rows'][0]['line'], 'first data row is row 2' );

$p = $I::parse_csv( "\xEF\xBB\xBFfirst_name,last_name,email\r\nA,B,a@example.com\r\nC,D,c@example.com\r\n" );
css_tc_check( $p['ok'] && 2 === count( $p['rows'] ), 'BOM and Windows line endings' );

$p = $I::parse_csv( "first_name;last_name;email\nA;B;a@example.com\n" );
css_tc_check( $p['ok'] && 'a@example.com' === $p['rows'][0]['cells']['email'], 'semicolon-separated file' );

$p = $I::parse_csv( "first_name,last_name,email\n\"O'Neil, Jr\",\"Smith\",x@example.com\n" );
css_tc_check( $p['ok'] && "O'Neil, Jr" === $p['rows'][0]['cells']['first_name'], 'quoted cell with a comma' );

$p = $I::parse_csv( "first_name,last_name\nA,B\n" );
css_tc_check( ! $p['ok'] && false !== strpos( $p['error'], 'email' ), 'missing email column refused' );
$p = $I::parse_csv( '' );
css_tc_check( ! $p['ok'], 'empty file refused' );

// ---- Dates -----------------------------------------------------------
css_tc_check( '2026-03-02' === $I::normalize_date( '2026-03-02' ), 'date Y-m-d' );
css_tc_check( '2026-03-02' === $I::normalize_date( '3/2/2026' ), 'date m/d/Y' );
css_tc_check( '2026-03-02' === $I::normalize_date( '03/02/26' ), 'date m/d/yy' );
css_tc_check( '' === $I::normalize_date( '' ), 'blank date is fine' );
css_tc_check( false === $I::normalize_date( '2026-02-30' ), 'impossible date refused' );
css_tc_check( false === $I::normalize_date( 'March 2' ), 'unreadable date refused' );
css_tc_check( false === $I::normalize_date( '13/01/2026' ), 'month 13 refused' );

// ---- Spreadsheet formulas -------------------------------------------
css_tc_check( "'=SUM(A1)" === $I::neutralize( '=SUM(A1)' ) && "'@x" === $I::neutralize( '@x' ) && "'-1" === $I::neutralize( '-1' ), 'formula characters neutralized' );
css_tc_check( 'Jamie' === $I::neutralize( 'Jamie' ), 'normal text unchanged' );
css_tc_check( false !== strpos( $I::csv_line( array( 'a', '=cmd', 'b,c' ) ), "'=cmd" ), 'results line is neutralized' );

// ---- Rows ------------------------------------------------------------
$users  = array( 'taken@example.com' => array( 'id' => 7, 'is_employee' => true ), 'boss@example.com' => array( 'id' => 9, 'is_employee' => false ) );
$logins = array( 'taken' => true );
$pins   = array( '1111' => 7 );
$env    = array(
	'roles'           => array( 'employee', 'manager' ),
	'role_aliases'    => array( 'staff member' => 'employee' ),
	'default_role'    => 'employee',
	'update_existing' => false,
	'pin_min'         => 4,
	'pin_max'         => 8,
	'email_user'      => static function ( $e ) use ( $users ) {
		return isset( $users[ $e ] ) ? $users[ $e ] : null;
	},
	'login_exists'    => static function ( $l ) use ( $logins ) {
		return isset( $logins[ $l ] );
	},
	'pin_owner'       => static function ( $p ) use ( $pins ) {
		return isset( $pins[ $p ] ) ? $pins[ $p ] : 0;
	},
);
$cells = static function ( $over = array() ) {
	return array_merge(
		array(
			'first_name' => 'Jamie',
			'last_name'  => 'Rivera',
			'email'      => 'jamie@example.com',
			'username'   => '',
			'password'   => '',
			'role'       => '',
			'hire_date'  => '',
			'pin'        => '',
		),
		$over
	);
};

$seen = array();
$r    = $I::plan_row( $cells(), 2, $env, $seen );
css_tc_check( 'create' === $r['status'], 'minimal row (name and email only) creates' );
css_tc_check( 'jamie@example.com' === $r['username'], 'username defaults to the email' );
css_tc_check( 'employee' === $r['role'], 'role defaults to the chosen role' );
css_tc_check( true === $r['pin_generate'] && '' === $r['pin'], 'blank PIN is generated' );
css_tc_check( '' === $r['hire_date'] && '' === $r['password'], 'hire date and password stay blank' );

$seen = array();
css_tc_check( null === $I::plan_row( $cells( array( 'first_name' => '', 'last_name' => '', 'email' => '' ) ), 3, $env, $seen ), 'blank row ignored' );

$seen = array();
$r    = $I::plan_row( $cells( array( 'email' => '  Jamie@Example.COM ' ) ), 2, $env, $seen );
css_tc_check( 'jamie@example.com' === $r['email'], 'email trimmed and lower-cased' );

foreach ( array( 'first_name', 'last_name', 'email' ) as $need ) {
	$seen = array();
	$r    = $I::plan_row( $cells( array( $need => '' ) ), 2, $env, $seen );
	css_tc_check( 'error' === $r['status'], "missing $need is an error" );
}
$seen = array();
$r    = $I::plan_row( $cells( array( 'email' => 'not-an-email' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'], 'bad email is an error' );

// Same email twice in one file.
$seen = array();
$I::plan_row( $cells(), 2, $env, $seen );
$r = $I::plan_row( $cells(), 3, $env, $seen );
css_tc_check( 'error' === $r['status'] && false !== strpos( $r['message'], 'row 2' ), 'repeated email in the file is an error' );

// Existing account.
$seen = array();
$r    = $I::plan_row( $cells( array( 'email' => 'taken@example.com' ) ), 2, $env, $seen );
css_tc_check( 'skip' === $r['status'], 'existing email is skipped by default' );
$upd  = $env;
$upd['update_existing'] = true;
$seen = array();
$r    = $I::plan_row( $cells( array( 'email' => 'taken@example.com', 'pin' => '1111' ) ), 2, $upd, $seen );
css_tc_check( 'update' === $r['status'] && 7 === $r['user_id'], 'existing email updates when asked' );
css_tc_check( '1111' === $r['pin'], 'an employee may keep their own PIN' );
css_tc_check( '' === $r['role'] && '' === $r['username'], 'update leaves role and username alone unless given' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'email' => 'boss@example.com' ) ), 2, $upd, $seen );
css_tc_check( 'error' === $r['status'], 'update never touches an account that is not a time clock employee' );

// Username.
$seen = array();
$r    = $I::plan_row( $cells( array( 'username' => 'taken' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'], 'username already taken' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'username' => 'bad;name' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'], 'username with odd characters refused' );
$seen = array();
$I::plan_row( $cells( array( 'username' => 'jr' ) ), 2, $env, $seen );
$r = $I::plan_row( $cells( array( 'email' => 'other@example.com', 'username' => 'JR' ) ), 3, $env, $seen );
css_tc_check( 'error' === $r['status'], 'same username twice in the file (any case)' );

// Password.
$seen = array();
$r    = $I::plan_row( $cells( array( 'password' => 'short' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'], 'password under 8 characters refused' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'password' => 'ChangeMe-2026' ) ), 2, $env, $seen );
css_tc_check( 'create' === $r['status'] && 'ChangeMe-2026' === $r['password'], 'password from the file is kept' );

// Role.
$seen = array();
$r    = $I::plan_row( $cells( array( 'role' => 'Manager' ) ), 2, $env, $seen );
css_tc_check( 'manager' === $r['role'], 'role by slug, any case' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'role' => 'Staff Member' ) ), 2, $env, $seen );
css_tc_check( 'employee' === $r['role'], 'role by its display name' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'role' => 'administrator' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'], 'administrator is never allowed' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'role' => 'editor' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'], 'a non-time-clock role is refused' );

// Hire date.
$seen = array();
$r    = $I::plan_row( $cells( array( 'hire_date' => '3/2/2026' ) ), 2, $env, $seen );
css_tc_check( 'create' === $r['status'] && '2026-03-02' === $r['hire_date'], 'hire date converted' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'hire_date' => 'soon' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'], 'bad hire date is an error, not a guess' );

// PIN.
$seen = array();
$r    = $I::plan_row( $cells( array( 'pin' => '4827' ) ), 2, $env, $seen );
css_tc_check( '4827' === $r['pin'] && false === $r['pin_generate'], 'PIN from the file is used' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'pin' => '1111' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'], 'PIN used by another employee is an error' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'pin' => '12' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'], 'PIN too short (dropped leading zero) is an error' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'pin' => 'abcd' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'], 'PIN with no digits is an error' );
$seen = array();
$r    = $I::plan_row( $cells( array( 'pin' => '48-27' ) ), 2, $env, $seen );
css_tc_check( '4827' === $r['pin'], 'PIN punctuation is stripped' );
$seen = array();
$I::plan_row( $cells( array( 'pin' => '5555' ) ), 2, $env, $seen );
$r = $I::plan_row( $cells( array( 'email' => 'other@example.com', 'pin' => '5555' ) ), 3, $env, $seen );
css_tc_check( 'error' === $r['status'] && false !== strpos( $r['message'], 'row 2' ), 'same PIN twice in the file' );

// Several problems on one row are all reported.
$seen = array();
$r    = $I::plan_row( $cells( array( 'hire_date' => 'x', 'pin' => '12', 'password' => 'abc' ) ), 2, $env, $seen );
css_tc_check( 'error' === $r['status'] && substr_count( $r['message'], '.' ) >= 3, 'all problems on a row are listed' );

// A bad row does not stop the good ones.
$rows = array();
$seen = array();
foreach ( array(
	$cells( array( 'email' => 'a@example.com' ) ),
	$cells( array( 'email' => 'bad' ) ),
	$cells( array( 'email' => 'taken@example.com' ) ),
	$cells( array( 'email' => 'c@example.com' ) ),
) as $i => $c ) {
	$rows[] = $I::plan_row( $c, $i + 2, $env, $seen );
}
$n = $I::counts( $rows );
css_tc_check( 2 === $n['create'] && 1 === $n['error'] && 1 === $n['skip'], 'counts: 2 create, 1 error, 1 skip' );

// ---- Generated PINs --------------------------------------------------
$taken = array();
$ok    = true;
for ( $i = 0; $i < 300; $i++ ) {
	$pin = $I::random_pin( 4, $taken );
	if ( '' === $pin || isset( $taken[ $pin ] ) || ! preg_match( '/^\d{4}$/', $pin ) ) {
		$ok = false;
		break;
	}
	$taken[ $pin ] = true;
}
css_tc_check( $ok, '300 generated PINs are 4 digits and all different' );
$full = array();
for ( $i = 0; $i < 10000; $i++ ) {
	$full[ sprintf( '%04d', $i ) ] = true;
}
css_tc_check( '' === $I::random_pin( 4, $full ), 'no free PIN left returns blank' );

// ---- Template ---------------------------------------------------------
$t = $I::parse_csv( $I::template_csv() );
css_tc_check( $t['ok'] && 2 === count( $t['rows'] ), 'the template parses back' );

if ( $failed ) {
	fwrite( STDERR, "{$failed} check(s) failed\n" );
	exit( 1 );
}
echo "all checks passed\n";
