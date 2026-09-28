<?php
/**
 * CLI checks for the office allowlist parser and client IP helper.
 *
 * Usage: php bin/check-ip-allowlist.php
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
$office  = "This kiosk only works from the office network. Please tell your manager.";

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
css_tc_check( array( 'not-an-ip', '10.0.0.0/33' ) === $bad['invalid'], 'rejects a bare word and a bad prefix' );

$hosts = $pins->parse_allowlist( "66.76.190.146\n76.195.93.0/24\nCSSWilson.DDNS.net.\ncsswilson.ddns.net\n" );
css_tc_check(
	array( '66.76.190.146', '76.195.93.0/24', 'csswilson.ddns.net' ) === $hosts['entries'],
	'accepts an IP, a CIDR, and a hostname'
);
css_tc_check( array() === $hosts['invalid'], 'hostname sample has no invalid lines' );
css_tc_check( 'csswilson.ddns.net' === $pins->normalize_hostname( 'CSSWilson.DDNS.net.' ), 'hostname is lowercased and the trailing dot is stripped' );

$junk = $pins->parse_allowlist( "not-an-ip\ncsswilson.ddns.net/32\nbad..host\n-bad.example\nfoo_bar.example\nlocalhost\n" );
css_tc_check(
	array( 'not-an-ip', 'csswilson.ddns.net/32', 'bad..host', '-bad.example', 'foo_bar.example', 'localhost' ) === $junk['invalid'],
	'rejects junk hostnames and hostname CIDR'
);
css_tc_check( array() === $junk['entries'], 'junk hostnames are not stored' );

$proxy = $pins->parse_allowlist( "198.51.100.0/24\ncsswilson.ddns.net\n", false );
css_tc_check( array( '198.51.100.0/24' ) === $proxy['entries'], 'trusted proxies stay addresses and CIDRs' );
css_tc_check( array( 'csswilson.ddns.net' ) === $proxy['invalid'], 'trusted proxies reject a hostname' );

$calls = 0;
$pins->use_memory_store();
$pins->set_test_now( 1700000000 );
$pins->set_hostname_resolver(
	static function ( $host ) use ( &$calls ) {
		++$calls;
		if ( 'csswilson.ddns.net' === $host ) {
			return array( '216.210.87.91' );
		}
		return array();
	}
);
css_tc_check( $pins->ip_in_entry( '216.210.87.91', 'csswilson.ddns.net' ), 'resolved hostname matches' );
css_tc_check( ! $pins->ip_in_entry( '66.76.190.146', 'csswilson.ddns.net' ), 'other address misses the hostname' );
css_tc_check( 1 === $calls, 'second match uses the resolution cache' );
$status = $pins->hostname_status( 'csswilson.ddns.net' );
css_tc_check( array( '216.210.87.91' ) === $status['ips'] && empty( $status['failed'] ), 'status reads the cache without another lookup' );
css_tc_check( 1 === $calls, 'status does not query DNS' );

$pins->set_test_now( 1700000121 );
$pins->set_hostname_resolver(
	static function () {
		return array();
	}
);
css_tc_check( $pins->ip_in_entry( '216.210.87.91', 'csswilson.ddns.net' ), 'lookup failure uses last known-good' );
$failed_status = $pins->hostname_status( 'csswilson.ddns.net' );
css_tc_check( ! empty( $failed_status['failed'] ) && array( '216.210.87.91' ) === $failed_status['ips'], 'failure is flagged and still shows last known-good' );

$fresh = new Css_Tc_Pins();
$fresh->use_memory_store();
$fresh->set_test_now( 1700000000 );
$fresh->set_hostname_resolver(
	static function () {
		return array();
	}
);
css_tc_check( ! $fresh->ip_in_entry( '216.210.87.91', 'csswilson.ddns.net' ), 'unresolvable hostname with no history does not match' );
css_tc_check(
	! $fresh->ip_allowed_by_list( '216.210.87.91', true, "csswilson.ddns.net\n" ),
	'an unresolved hostname denies the client'
);
css_tc_check( $fresh->ip_allowed_by_list( '216.210.87.91', true, "# only comments\n" ), 'comments-only list still allows everyone' );
css_tc_check( $fresh->ip_allowed_by_list( '216.210.87.91', false, "csswilson.ddns.net\n" ), 'checkbox off still allows everyone' );

$logged = new Css_Tc_Pins();
$logged->use_memory_store();
$logged->set_test_now( 1700000000 );
css_tc_check( $logged->record_refused_kiosk( '203.0.113.50', 'css_tc_punch', 4, 'Two Staff' ), 'refused punch is stored' );
css_tc_check( ! $logged->record_refused_kiosk( '203.0.113.50', 'css_tc_punch', 4, '1234' ), 'same IP inside a minute is not stored again' );
css_tc_check( $logged->record_refused_kiosk( '198.51.100.8', 'css_tc_roster', 0, '' ), 'a different IP is stored' );
$rows = $logged->refused_kiosk_log();
css_tc_check( 2 === count( $rows ), 'rate limit keeps one row per IP' );
css_tc_check( 'Two Staff' === $rows[0]['name'] && 4 === $rows[0]['user_id'], 'employee name is stored when already identified' );
css_tc_check( ! isset( $rows[0]['pin'] ) && false === strpos( (string) json_encode( $rows ), '1234' ), 'refused log does not store a PIN' );
$logged->set_test_now( 1700000061 );
css_tc_check( $logged->record_refused_kiosk( '203.0.113.50', 'css_tc_punch', 0, '' ), 'same IP can be stored after a minute' );
$cap = new Css_Tc_Pins();
$cap->use_memory_store();
$cap->set_test_now( 1700000000 );
for ( $i = 0; $i < 205; $i++ ) {
	$cap->set_test_now( 1700000000 + ( $i * 61 ) );
	$cap->record_refused_kiosk( '203.0.113.' . ( $i % 200 ), 'css_tc_punch', 0, '' );
}
css_tc_check( 200 === count( $cap->refused_kiosk_log() ), 'refused log keeps the last 200' );

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
