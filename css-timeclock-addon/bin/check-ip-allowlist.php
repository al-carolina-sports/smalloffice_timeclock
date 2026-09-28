<?php
/**
 * CLI checks for the office allowlist parser and client IP helper.
 *
 * Usage: php bin/check-ip-allowlist.php
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-pins.php';

$pins    = new Css_Tc_Pins();
$failed  = 0;
$office  = "This kiosk only works from the office network.";

/**
 * @param bool   $cond Condition.
 * @param string $label Label.
 * @return void
 */
function css_tc_check( $cond, $label ) {
	global $failed;
	if ( $cond ) {
		echo "ok  {$label}\n";
		return;
	}
	echo "FAIL {$label}\n";
	++$failed;
}

$raw = "# Rocky Mount\n\n203.0.113.10\n203.0.113.0/24  # clinic wifi\n2001:db8::1\n2001:db8::/32\n";
$parsed = $pins->parse_allowlist( $raw );
css_tc_check( array( '203.0.113.10', '203.0.113.0/24', '2001:db8::1', '2001:db8::/32' ) === $parsed['entries'], 'parses addresses, CIDR, and comments' );
css_tc_check( array() === $parsed['invalid'], 'commented sample has no invalid lines' );

$comments_only = $pins->parse_allowlist( "# just a note\n\n# another\n" );
css_tc_check( array() === $comments_only['entries'], 'comments-only list is empty' );

$bad = $pins->parse_allowlist( "203.0.113.10\nnot-an-ip\n10.0.0.0/33\n" );
css_tc_check( array( '203.0.113.10' ) === $bad['entries'], 'keeps the valid line' );
css_tc_check( array( 'not-an-ip', '10.0.0.0/33' ) === $bad['invalid'], 'rejects hostname and bad prefix' );

css_tc_check( $pins->ip_in_entry( '203.0.113.10', '203.0.113.10' ), 'exact IPv4' );
css_tc_check( ! $pins->ip_in_entry( '203.0.113.11', '203.0.113.10' ), 'different IPv4 misses' );
css_tc_check( $pins->ip_in_entry( '203.0.113.50', '203.0.113.0/24' ), 'IPv4 inside /24' );
css_tc_check( $pins->ip_in_entry( '203.0.113.15', '203.0.113.0/28' ), 'last address of /28' );
css_tc_check( ! $pins->ip_in_entry( '203.0.113.16', '203.0.113.0/28' ), 'first address outside /28' );
css_tc_check( ! $pins->ip_in_entry( '203.0.114.1', '203.0.113.0/24' ), 'next /24 misses' );
css_tc_check( $pins->ip_in_entry( '2001:db8::1', '2001:db8::/32' ), 'IPv6 inside /32' );
css_tc_check( $pins->ip_in_entry( '2001:0db8:0000::1', '2001:db8::1' ), 'IPv6 canonical equality' );
css_tc_check( ! $pins->ip_in_entry( '2001:db8::1', '203.0.113.0/24' ), 'IPv6 does not match IPv4 CIDR' );
css_tc_check( $pins->ip_in_entry( '::ffff:203.0.113.10', '203.0.113.10' ), 'IPv4-mapped IPv6 matches IPv4 entry' );
css_tc_check( '203.0.113.10' === $pins->canonical_ip( '203.0.113.10:443' ), 'strips an IPv4 port' );

$list = "203.0.113.0/24\n# note\n";
css_tc_check( $pins->ip_allowed_by_list( '198.51.100.4', false, $list ), 'checkbox off allows everyone' );
css_tc_check( $pins->ip_allowed_by_list( '198.51.100.4', true, "# only comments\n" ), 'empty list allows everyone' );
css_tc_check( $pins->ip_allowed_by_list( '198.51.100.4', true, '' ), 'blank list allows everyone' );
css_tc_check( $pins->ip_allowed_by_list( '203.0.113.10', true, $list ), 'listed network allowed' );
css_tc_check( ! $pins->ip_allowed_by_list( '198.51.100.4', true, $list ), 'other network refused' );
css_tc_check( ! $pins->ip_allowed_by_list( '', true, $list ), 'unknown client refused when list is active' );

$pins->extra_trusted_proxies( array() );
$reset = static function () {
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_TRUE_CLIENT_IP'], $_SERVER['HTTP_X_REAL_IP'] );
};

$reset();
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.10';
$_SERVER['REMOTE_ADDR']          = '10.1.2.3';
css_tc_check( '203.0.113.10' === $pins->client_ip(), 'private proxy: forwarded client used' );

$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.10, 198.51.100.77';
$_SERVER['REMOTE_ADDR']          = '10.1.2.3';
css_tc_check( '198.51.100.77' === $pins->client_ip(), 'spoofed left X-Forwarded-For entry is ignored' );

$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.10, 198.51.100.77, 10.9.9.9';
css_tc_check( '198.51.100.77' === $pins->client_ip(), 'internal hops on the right are skipped' );

$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.10';
$_SERVER['REMOTE_ADDR']          = '198.51.100.8';
css_tc_check( '198.51.100.8' === $pins->client_ip(), 'public REMOTE_ADDR ignores forwarded headers' );

$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, not-an-ip';
$_SERVER['REMOTE_ADDR']          = '10.1.2.3';
css_tc_check( '10.1.2.3' === $pins->client_ip(), 'unreadable entry stops the walk' );

$reset();
$_SERVER['HTTP_TRUE_CLIENT_IP'] = '203.0.113.20';
$_SERVER['REMOTE_ADDR']         = '10.0.0.4';
css_tc_check( '203.0.113.20' === $pins->client_ip(), 'True-Client-IP from a private proxy' );

$_SERVER['REMOTE_ADDR'] = '198.51.100.3';
css_tc_check( '198.51.100.3' === $pins->client_ip(), 'True-Client-IP ignored from a public client' );

$reset();
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
css_tc_check( '198.51.100.9' === $pins->client_ip(), 'REMOTE_ADDR fallback' );

$_SERVER['REMOTE_ADDR'] = '192.168.1.20';
css_tc_check( '192.168.1.20' === $pins->client_ip(), 'LAN client without headers' );

$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '::ffff:203.0.113.10';
css_tc_check( '203.0.113.10' === $pins->client_ip(), 'forwarded IPv4-mapped address canonicalizes' );

$reset();
$pins->extra_trusted_proxies( array( '198.51.100.0/24' ) );
$_SERVER['REMOTE_ADDR']          = '198.51.100.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
css_tc_check( '203.0.113.99' === $pins->client_ip(), 'listed public proxy is trusted' );
$pins->extra_trusted_proxies( array() );
css_tc_check( '198.51.100.5' === $pins->client_ip(), 'unlisted public proxy is the client' );
css_tc_check( $pins->is_trusted_proxy( '100.64.3.4' ), 'CGNAT range counts as internal' );
$reset();

// --- Hostnames (dynamic DNS) -------------------------------------------
$h = $pins->parse_allowlist( "66.76.190.146\nCSSWilson.DDNS.net.  # Wilson\n10.0.0.300\nbad_host.example\nlocalhost\n" );
css_tc_check( array( '66.76.190.146' ) === $h['entries'], 'hostname list keeps addresses' );
css_tc_check( array( 'csswilson.ddns.net' ) === $h['hosts'], 'hostname lower-cased, trailing dot and comment removed' );
css_tc_check( array( '10.0.0.300', 'bad_host.example', 'localhost' ) === $h['invalid'], 'bad address, underscore and dotless names rejected' );
css_tc_check( array( 'csswilson.ddns.net' ) === $pins->parse_allowlist( 'csswilson.ddns.net', false )['invalid'], 'hostnames refused where not allowed (trusted proxies)' );

$dns_now    = 1000000;
$dns_answer = array( '216.210.87.91' );
$dns_calls  = 0;
$pins->set_dns_test_hooks(
	static function ( $host ) use ( &$dns_answer, &$dns_calls ) {
		++$dns_calls;
		return 'csswilson.ddns.net' === $host ? $dns_answer : false;
	},
	static function () use ( &$dns_now ) {
		return $dns_now;
	}
);
$wilson = "76.195.93.124\ncsswilson.ddns.net\n";
css_tc_check( $pins->ip_allowed_by_list( '216.210.87.91', true, $wilson ), 'address behind the hostname is allowed' );
css_tc_check( ! $pins->ip_allowed_by_list( '198.51.100.4', true, $wilson ), 'other address still refused' );
css_tc_check( 'csswilson.ddns.net' === $pins->list_match( '216.210.87.91', $pins->parse_allowlist( $wilson ) ), 'match reports the hostname' );
$calls_before = $dns_calls;
$pins->ip_allowed_by_list( '216.210.87.91', true, $wilson );
css_tc_check( $calls_before === $dns_calls, 'fresh answer comes from the cache' );

$dns_now   += 700;
$dns_answer = array( '216.210.87.99' );
css_tc_check( $pins->ip_allowed_by_list( '216.210.87.99', true, $wilson ), 'new address picked up after the cache ages out' );
css_tc_check( ! $pins->ip_allowed_by_list( '216.210.87.91', true, $wilson ), 'old address no longer allowed' );

$dns_now   += 700;
$dns_answer = false;
css_tc_check( $pins->ip_allowed_by_list( '216.210.87.99', true, $wilson ), 'failed lookup keeps the last address that worked' );
$st = $pins->host_status( 'csswilson.ddns.net' );
css_tc_check( ! empty( $st['failed'] ) && array( '216.210.87.99' ) === $st['ips'], 'status shows the failure and kept address' );
$calls_before = $dns_calls;
$dns_now     += 10;
$pins->ip_allowed_by_list( '216.210.87.99', true, $wilson );
css_tc_check( $calls_before === $dns_calls, 'no retry within a minute of a failure' );

css_tc_check( ! $pins->ip_allowed_by_list( '198.51.100.4', true, "never.example.test\n" ), 'unresolvable-only list stays closed, not open to all' );
css_tc_check( array() === $pins->host_ips( 'never.example.test' ), 'unresolvable name has no addresses' );

$dns_answer = array( '::ffff:216.210.87.50', '2001:db8::5' );
$pins->refresh_hosts( array( 'csswilson.ddns.net' ) );
css_tc_check( array( '216.210.87.50', '2001:db8::5' ) === $pins->host_ips( 'csswilson.ddns.net' ), 'refresh looks up now; mapped IPv4 canonicalized, IPv6 kept' );
$pins->set_dns_test_hooks( null );

$ajax = file_get_contents( dirname( __DIR__ ) . '/includes/class-ajax.php' );
css_tc_check( false !== strpos( $ajax, $office ), 'kiosk error string is present' );
css_tc_check( false === strpos( $ajax, "office network.' )," ) || false === strpos( $ajax, 'client_ip()' ), 'office error is a fixed string' );
$office_fn = '';
if ( preg_match( '/function assert_office_network\(\) \{.*?\n\t\}/s', $ajax, $m ) ) {
	$office_fn = $m[0];
}
css_tc_check( '' !== $office_fn && false === strpos( $office_fn, 'client_ip' ), 'office error does not read the client IP' );

if ( $failed > 0 ) {
	fwrite( STDERR, "{$failed} check(s) failed\n" );
	exit( 1 );
}

echo "all checks passed\n";
exit( 0 );
