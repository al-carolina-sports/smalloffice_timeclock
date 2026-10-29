<?php
/**
 * Daily quote selection. Run: php bin/check-quotes.php
 *
 * Does not boot WordPress and does not touch punch math.
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

require_once dirname( __DIR__ ) . '/includes/class-quotes.php';

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

$defaults = Css_Tc_Quotes::defaults();
css_tc_check( 0 === (int) $defaults['quote_enabled'] && 'builtin' === $defaults['quote_source'], 'the daily quote is off until turned on' );

$builtin = Css_Tc_Quotes::builtin();
css_tc_check( count( $builtin ) >= 100 && count( $builtin ) <= 110, 'the built-in list is about 100 quotes' );
$banned = '/\b(jesus|christ|bible|quran|koran|allah|mosque|church|prayer|pray|god|lord|trump|biden|democrat|republican|liberal|conservative|maga)\b/i';
$clean  = true;
$dupes  = array();
foreach ( $builtin as $quote ) {
	$line = $quote['text'] . ' ' . $quote['author'];
	if ( '' === $quote['text'] || '' === $quote['author'] || preg_match( $banned, $line ) ) {
		$clean = false;
	}
	$key = strtolower( $quote['text'] );
	if ( isset( $dupes[ $key ] ) ) {
		$clean = false;
	}
	$dupes[ $key ] = true;
}
css_tc_check( $clean, 'built-in quotes are short lines with an author and no religious or political wording' );

$parsed = Css_Tc_Quotes::parse_line( 'Start before you feel ready. — Ada Lovelace' );
css_tc_check( $parsed && 'Start before you feel ready.' === $parsed['text'] && 'Ada Lovelace' === $parsed['author'], 'a custom line is quote — author' );
$hyphen = Css_Tc_Quotes::parse_line( 'Keep going - Marie Curie' );
css_tc_check( $hyphen && 'Marie Curie' === $hyphen['author'], 'a spaced hyphen is accepted as the separator' );
css_tc_check( null === Css_Tc_Quotes::parse_line( 'No author on this line' ), 'a line without an author is not a quote' );

$block = Css_Tc_Quotes::parse_lines( "First thought. — Grace Hopper\n\n# comment\nnot a quote\nSecond thought. — Katherine Johnson\n" );
css_tc_check( 2 === count( $block['quotes'] ) && array( 'not a quote' ) === $block['invalid'], 'blank lines and comments are skipped and a bad line is reported' );

$saved = Css_Tc_Quotes::sanitize_input(
	array(
		'quote_enabled' => 1,
		'quote_source'  => 'both',
		'quote_custom'  => "Keep the desk clear. — Office lead\nbad line",
	)
);
css_tc_check( 1 === (int) $saved['settings']['quote_enabled'] && 'both' === $saved['settings']['quote_source'], 'the quote settings can be turned on' );
css_tc_check( array( 'bad line' ) === $saved['invalid'], 'a bad custom line blocks a quiet save' );

$on = array(
	'quote_enabled' => 1,
	'quote_source'  => 'builtin',
	'quote_custom'  => '',
);
css_tc_check( null === Css_Tc_Quotes::present( array( 'quote_enabled' => 0 ), 0 ), 'a turned-off quote is not shown' );

$late  = strtotime( '2026-10-29 03:30:00 UTC' );
$early = strtotime( '2026-10-29 04:30:00 UTC' );
css_tc_check( '2026-10-28' === Css_Tc_Quotes::eastern_date( $late ), 'just before Eastern midnight is still the previous day' );
css_tc_check( '2026-10-29' === Css_Tc_Quotes::eastern_date( $early ), 'Eastern midnight starts the new quote day' );

$one = Css_Tc_Quotes::present( $on, $late );
$two = Css_Tc_Quotes::present( $on, strtotime( '2026-10-29 02:00:00 UTC' ) );
$next = Css_Tc_Quotes::present( $on, $early );
css_tc_check( $one && $two && $one['text'] === $two['text'] && $one['author'] === $two['author'], 'everyone gets the same quote on the same Eastern day' );
css_tc_check( $one && $next && $one['date'] !== $next['date'], 'the quote day changes at Eastern midnight' );

$seen = array();
$cursor = strtotime( '2026-01-01 17:00:00 UTC' );
for ( $i = 0; $i < 40; $i++ ) {
	$quote = Css_Tc_Quotes::present( $on, $cursor );
	$seen[ $quote['text'] ] = true;
	$cursor += 86400;
}
css_tc_check( count( $seen ) > 1, 'different Eastern days can show different quotes' );

$custom_only = array(
	'quote_enabled' => 1,
	'quote_source'  => 'custom',
	'quote_custom'  => "Bring a pen. — Front desk\n",
);
$only = Css_Tc_Quotes::present( $custom_only, $late );
css_tc_check( $only && 'Bring a pen.' === $only['text'] && 'Front desk' === $only['author'], 'custom quotes replace the built-in list when that choice is saved' );
css_tc_check( null === Css_Tc_Quotes::present( array( 'quote_enabled' => 1, 'quote_source' => 'custom', 'quote_custom' => '' ), $late ), 'custom mode with an empty list shows nothing' );

$both = Css_Tc_Quotes::pool( Css_Tc_Quotes::rules_from( array( 'quote_source' => 'both', 'quote_custom' => "Extra line. — Coach\n" ) ) );
css_tc_check( count( $both ) === count( $builtin ) + 1 && 'Extra line.' === $both[ count( $builtin ) ]['text'], 'both uses the built-in list and then the custom lines' );

$again = Css_Tc_Quotes::index_for_date( count( $builtin ), '2026-10-28' );
css_tc_check( $again === Css_Tc_Quotes::index_for_date( count( $builtin ), '2026-10-28' ), 'the same date always picks the same spot in the list' );

$punches = file_get_contents( dirname( __DIR__ ) . '/includes/class-punches.php' );
css_tc_check( false === strpos( $punches, 'Css_Tc_Quotes' ), 'punch handling does not call the quote picker' );
$kiosk = file_get_contents( dirname( __DIR__ ) . '/public/js/kiosk.js' );
css_tc_check( false !== strpos( $kiosk, 'data.action === "clock_in"' ) && false !== strpos( $kiosk, 'success-quote' ), 'the clock-in confirmation can show the quote' );
$view = file_get_contents( dirname( __DIR__ ) . '/public/views/pin-kiosk.php' );
css_tc_check( false !== strpos( $view, 'data-role="daily-quote"' ) && false !== strpos( $view, 'data-role="success-quote"' ), 'the PIN kiosk has a quote card and a confirmation spot' );
$settings_view = file_get_contents( dirname( __DIR__ ) . '/admin/views/settings-page.php' );
css_tc_check( false !== strpos( $settings_view, 'Daily quote' ) && false !== strpos( $settings_view, 'quote_custom' ) && false !== strpos( $settings_view, 'value="both"' ), 'TC-Config can turn the quote on and choose built-in, custom, or both' );

if ( $failed > 0 ) {
	echo "{$failed} failed\n";
	exit( 1 );
}
echo "all ok\n";
exit( 0 );
