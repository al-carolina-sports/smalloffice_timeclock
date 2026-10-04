<?php
/**
 * Hashed employee PIN storage, lookup, and failed-attempt rate limits.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PINs are stored with wp_hash_password() and checked with wp_check_password().
 * Plaintext is never written to the database.
 */
class Css_Tc_Pins {

	const META_HASH    = 'css_tc_pin_hash';
	const META_SET     = 'css_tc_pin_set_at';
	const META_ENC     = 'css_tc_pin_enc';
	const META_REVEALS = 'css_tc_pin_reveals';

	const HOST_CACHE_TTL    = 120;
	const HOST_KNOWN_OPTION = 'css_tc_host_resolve';
	const REFUSED_OPTION    = 'css_tc_refused_kiosk';
	const REFUSED_CAP       = 200;
	const REFUSED_INTERVAL  = 60;

	/**
	 * Injected DNS lookup for tests. Null uses dns_get_record.
	 *
	 * @var callable|null
	 */
	private $hostname_resolver = null;

	/**
	 * In-memory transients and options for CLI tests.
	 *
	 * @var array<string,mixed>|null
	 */
	private $memory = null;

	/**
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_has_pin( $user_id ) {
		$hash = get_user_meta( (int) $user_id, self::META_HASH, true );
		return is_string( $hash ) && '' !== $hash;
	}

	/**
	 * Normalize a submitted PIN to digits only.
	 *
	 * @param mixed $pin Raw PIN.
	 * @return string
	 */
	public function normalize( $pin ) {
		return preg_replace( '/\D+/', '', (string) $pin );
	}

	/**
	 * @param string $pin Normalized PIN.
	 * @return true|WP_Error
	 */
	public function validate_format( $pin ) {
		$settings = css_tc_addon()->get_settings();
		$min      = max( 4, (int) $settings['pin_min_length'] );
		$max      = max( $min, (int) $settings['pin_max_length'] );

		if ( strlen( $pin ) < $min || strlen( $pin ) > $max ) {
			return new WP_Error(
				'css_tc_pin_length',
				sprintf(
					/* translators: 1: minimum digits, 2: maximum digits */
					__( 'PIN must be %1$d to %2$d digits.', 'css-timeclock-addon' ),
					$min,
					$max
				)
			);
		}

		return true;
	}

	/**
	 * Store a hashed PIN for an employee. Enforces uniqueness across users.
	 *
	 * @param int    $user_id User ID.
	 * @param string $pin     Plain PIN (will not be stored).
	 * @return true|WP_Error
	 */
	public function set_pin( $user_id, $pin ) {
		$user_id = (int) $user_id;
		$pin     = $this->normalize( $pin );

		if ( ! css_tc_addon()->employees->is_employee( $user_id ) ) {
			return new WP_Error( 'css_tc_not_employee', __( 'That user is not a time-clock employee.', 'css-timeclock-addon' ) );
		}

		$valid = $this->validate_format( $pin );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$owner = $this->find_user_id_by_pin( $pin );
		if ( $owner && (int) $owner !== $user_id ) {
			return new WP_Error( 'css_tc_pin_taken', __( 'That PIN is already assigned to another employee. Choose a different PIN.', 'css-timeclock-addon' ) );
		}

		$hash = wp_hash_password( $pin );
		if ( ! is_string( $hash ) || '' === $hash ) {
			return new WP_Error( 'css_tc_pin_hash', __( 'Could not hash the PIN. Try again.', 'css-timeclock-addon' ) );
		}

		update_user_meta( $user_id, self::META_HASH, $hash );
		update_user_meta( $user_id, self::META_SET, time() );

		// Encrypted copy so managers and the employee can reveal the PIN.
		// The hash above is still what the kiosk checks.
		$enc = $this->encrypt( $pin );
		if ( '' !== $enc ) {
			update_user_meta( $user_id, self::META_ENC, $enc );
		} else {
			delete_user_meta( $user_id, self::META_ENC );
		}

		return true;
	}

	/**
	 * 32-byte key derived from this site's secret keys in wp-config.php.
	 * Changing those keys makes stored PINs unreadable (they then need a reset).
	 *
	 * @return string
	 */
	private function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|css_tc_pin', true );
	}

	/**
	 * @param string $pin Plain PIN.
	 * @return string base64(nonce . ciphertext), or '' when encryption is unavailable.
	 */
	private function encrypt( $pin ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return base64_encode( $nonce . sodium_crypto_secretbox( (string) $pin, $nonce, $this->key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * @param string $blob Stored value.
	 * @return string Plain PIN or ''.
	 */
	private function decrypt( $blob ) {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || '' === (string) $blob ) {
			return '';
		}
		$raw = base64_decode( (string) $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->key() );
		} catch ( Exception $e ) {
			return '';
		}
		return false === $plain ? '' : (string) $plain;
	}

	/**
	 * Whether this employee's PIN can be shown (set since reveal was added).
	 *
	 * @param int $user_id Employee.
	 * @return bool
	 */
	public function is_viewable( $user_id ) {
		return self::user_has_pin( $user_id ) && '' !== (string) get_user_meta( (int) $user_id, self::META_ENC, true );
	}

	/**
	 * Return the PIN and record who looked.
	 *
	 * @param int $user_id   Employee whose PIN is shown.
	 * @param int $viewer_id Who is looking.
	 * @return string|WP_Error
	 */
	public function reveal( $user_id, $viewer_id ) {
		if ( ! self::user_has_pin( $user_id ) ) {
			return new WP_Error( 'css_tc_no_pin', __( 'No PIN is set.', 'css-timeclock-addon' ) );
		}
		$pin = $this->decrypt( (string) get_user_meta( (int) $user_id, self::META_ENC, true ) );
		if ( '' === $pin || ! wp_check_password( $pin, (string) get_user_meta( (int) $user_id, self::META_HASH, true ) ) ) {
			return new WP_Error( 'css_tc_pin_hidden', __( 'This PIN was set before PINs could be shown. Set a new PIN to view it.', 'css-timeclock-addon' ) );
		}
		$log = get_user_meta( (int) $user_id, self::META_REVEALS, true );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'by' => (int) $viewer_id,
				'at' => time(),
			)
		);
		update_user_meta( (int) $user_id, self::META_REVEALS, array_slice( $log, 0, 20 ) );
		return $pin;
	}

	/**
	 * Most recent reveal: who and when.
	 *
	 * @param int $user_id Employee.
	 * @return array{by:int,at:int}|null
	 */
	public function last_reveal( $user_id ) {
		$log = get_user_meta( (int) $user_id, self::META_REVEALS, true );
		return ( is_array( $log ) && ! empty( $log[0]['at'] ) ) ? $log[0] : null;
	}

	/**
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function clear_pin( $user_id ) {
		delete_user_meta( (int) $user_id, self::META_HASH );
		delete_user_meta( (int) $user_id, self::META_SET );
		delete_user_meta( (int) $user_id, self::META_ENC );
	}

	/**
	 * Find the employee whose hashed PIN matches. Returns 0 if none.
	 *
	 * @param string $pin Normalized PIN.
	 * @return int
	 */
	public function find_user_id_by_pin( $pin ) {
		$pin = $this->normalize( $pin );
		if ( '' === $pin ) {
			return 0;
		}

		$users = get_users(
			array(
				'meta_key'     => self::META_HASH,
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => 400,
			)
		);

		foreach ( $users as $user ) {
			$user_id = (int) $user;
			$hash    = get_user_meta( $user_id, self::META_HASH, true );
			if ( ! is_string( $hash ) || '' === $hash ) {
				continue;
			}
			if ( wp_check_password( $pin, $hash, $user_id ) ) {
				return $user_id;
			}
		}

		return 0;
	}

	/**
	 * Verify a PIN against a specific employee.
	 *
	 * @param int    $user_id User ID.
	 * @param string $pin     Plain PIN.
	 * @return bool
	 */
	public function verify_for_user( $user_id, $pin ) {
		$pin  = $this->normalize( $pin );
		$hash = get_user_meta( (int) $user_id, self::META_HASH, true );
		if ( ! is_string( $hash ) || '' === $hash || '' === $pin ) {
			return false;
		}
		return wp_check_password( $pin, $hash, (int) $user_id );
	}

	/**
	 * Client address used for failed-PIN limits, the office allowlist and
	 * office (location) detection.
	 *
	 * Only proxies we trust may say who the client is:
	 *  - REMOTE_ADDR is the machine that actually connected. If it is a
	 *    public address that is not a listed trusted proxy, it IS the client
	 *    and forwarded headers are ignored (a visitor can put anything in
	 *    them).
	 *  - If REMOTE_ADDR is a trusted proxy (private, loopback or CGNAT
	 *    addresses, as used by WP Engine's internal proxies, plus any
	 *    "trusted proxies" in settings), read X-Forwarded-For from the right
	 *    and take the first address that is not a trusted proxy. Proxies
	 *    append the address they saw, so the right-most untrusted entry is
	 *    the real visitor; anything a visitor typed sits further left and is
	 *    never reached.
	 *  - Without X-Forwarded-For, X-Real-IP / True-Client-IP from the trusted
	 *    proxy are used, else REMOTE_ADDR.
	 *
	 * @return string Canonical IPv4 or IPv6, or empty when none is valid.
	 */
	public function client_ip() {
		$remote = $this->ip_from_server_value( 'REMOTE_ADDR' );
		if ( '' === $remote ) {
			return '';
		}
		if ( ! $this->is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		$chain = $this->forwarded_chain();
		for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
			if ( '' === $chain[ $i ] ) {
				// Unreadable entry: do not trust anything to its left.
				break;
			}
			if ( ! $this->is_trusted_proxy( $chain[ $i ] ) ) {
				return $chain[ $i ];
			}
		}
		if ( empty( $chain ) ) {
			foreach ( array( 'HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP' ) as $key ) {
				$ip = $this->ip_from_server_value( $key );
				if ( '' !== $ip && ! $this->is_trusted_proxy( $ip ) ) {
					return $ip;
				}
			}
		}
		return $remote;
	}

	/**
	 * X-Forwarded-For entries, left to right, canonicalized ('' when unreadable).
	 *
	 * @return string[]
	 */
	private function forwarded_chain() {
		if ( empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || ! is_string( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			return array();
		}
		$raw = function_exists( 'wp_unslash' ) ? wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) : $_SERVER['HTTP_X_FORWARDED_FOR']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$out = array();
		foreach ( explode( ',', (string) $raw ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$out[] = $this->canonical_ip( $part );
		}
		return $out;
	}

	/**
	 * Private, loopback, link-local, unique-local and carrier-grade NAT
	 * addresses (a proxy inside the hosting network), or an address listed
	 * under "Trusted proxies" in settings.
	 *
	 * @param string $ip Canonical address.
	 * @return bool
	 */
	public function is_trusted_proxy( $ip ) {
		$ip = $this->canonical_ip( $ip );
		if ( '' === $ip ) {
			return false;
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}
		if ( $this->ip_in_entry( $ip, '100.64.0.0/10' ) ) {
			return true;
		}
		foreach ( $this->extra_trusted_proxies() as $entry ) {
			if ( $this->ip_in_entry( $ip, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @var string[]|null
	 */
	private $extra_trusted = null;

	/**
	 * "Trusted proxies" from settings (for a CDN or load balancer with public
	 * addresses in front of the site).
	 *
	 * @param string[]|null $override Set the list directly (tests).
	 * @return string[]
	 */
	public function extra_trusted_proxies( $override = null ) {
		if ( null !== $override ) {
			$this->extra_trusted = $override;
		}
		if ( null === $this->extra_trusted ) {
			$raw                 = function_exists( 'css_tc_addon' ) ? (string) ( css_tc_addon()->get_settings()['trusted_proxies'] ?? '' ) : '';
			$this->extra_trusted = $this->parse_allowlist( $raw, false )['entries'];
		}
		return $this->extra_trusted;
	}

	/**
	 * What the request headers say, for the settings screen.
	 *
	 * @return array<string,string>
	 */
	public function ip_diagnostics() {
		$read = static function ( $key ) {
			return ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
		};
		return array(
			'REMOTE_ADDR'     => $read( 'REMOTE_ADDR' ),
			'X-Forwarded-For' => $read( 'HTTP_X_FORWARDED_FOR' ),
			'X-Real-IP'       => $read( 'HTTP_X_REAL_IP' ),
			'True-Client-IP'  => $read( 'HTTP_TRUE_CLIENT_IP' ),
			'Detected'        => $this->client_ip(),
		);
	}

	/**
	 * First valid IP in a server value. Comma-separated chains use the first
	 * address only (WP Engine puts the visitor first and may include proxies
	 * after it). Later addresses are not scanned, so a caller cannot skip an
	 * invalid token and substitute their own later IP.
	 *
	 * @param string $key $_SERVER key.
	 * @return string
	 */
	private function ip_from_server_value( $key ) {
		if ( empty( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			return '';
		}

		$raw = function_exists( 'wp_unslash' ) ? wp_unslash( $_SERVER[ $key ] ) : $_SERVER[ $key ];
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		$first = $raw;
		$comma = strpos( $raw, ',' );
		if ( false !== $comma ) {
			$first = substr( $raw, 0, $comma );
		}

		return $this->canonical_ip( $first );
	}

	/**
	 * @return string
	 */
	public function client_key() {
		$ip = $this->client_ip();
		return 'css_tc_' . md5( $ip . '|' . (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Whether this request may use kiosk endpoints.
	 *
	 * Allowlist off, or on with no addresses (blank or comments only), allows
	 * every client so a new sandbox is not locked out. Enforcement never
	 * includes the address in a client-facing error.
	 *
	 * @return bool
	 */
	public function is_client_allowed() {
		$settings = css_tc_addon()->get_settings();
		$raw      = isset( $settings['ip_allowlist'] ) ? (string) $settings['ip_allowlist'] : '';
		return $this->ip_allowed_by_list( $this->client_ip(), ! empty( $settings['ip_allowlist_enabled'] ), $raw );
	}

	/**
	 * @param string $ip      Candidate address.
	 * @param bool   $enabled Allowlist checkbox.
	 * @param string $raw     Textarea contents.
	 * @return bool
	 */
	public function ip_allowed_by_list( $ip, $enabled, $raw ) {
		if ( ! $enabled ) {
			return true;
		}

		$parsed = $this->parse_allowlist( $raw );
		if ( empty( $parsed['entries'] ) ) {
			return true;
		}

		if ( '' === $this->canonical_ip( $ip ) ) {
			return false;
		}

		foreach ( $parsed['entries'] as $entry ) {
			if ( $this->ip_in_entry( $ip, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split an allowlist into valid entries and rejected lines.
	 *
	 * Blank lines and lines whose first non-space character is # are comments.
	 * A # later on a line starts an inline comment. Entries are one IPv4 or
	 * IPv6 address, CIDR, or hostname (for example csswilson.ddns.net) per line.
	 * Trusted-proxy lists pass $allow_hostnames false so only addresses and
	 * CIDRs are accepted there.
	 *
	 * @param string $raw              Textarea contents.
	 * @param bool   $allow_hostnames  Accept FQDN lines.
	 * @return array{entries: array<int,string>, invalid: array<int,string>}
	 */
	public function parse_allowlist( $raw, $allow_hostnames = true ) {
		$entries = array();
		$invalid = array();
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $raw );
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}

			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = trim( substr( $line, 0, $hash ) );
			}
			if ( '' === $line ) {
				continue;
			}

			$normalized = $this->normalize_allowlist_entry( $line, $allow_hostnames );
			if ( '' === $normalized ) {
				$invalid[] = $line;
				continue;
			}
			$entries[] = $normalized;
		}

		return array(
			'entries' => array_values( array_unique( $entries ) ),
			'invalid' => $invalid,
		);
	}

	/**
	 * @param string $ip    Client address.
	 * @param string $entry Canonical address, CIDR, or hostname from parse_allowlist().
	 * @return bool
	 */
	public function ip_in_entry( $ip, $entry ) {
		$ip = $this->canonical_ip( $ip );
		if ( '' === $ip || ! is_string( $entry ) || '' === $entry ) {
			return false;
		}

		if ( $this->is_hostname_entry( $entry ) ) {
			$resolved = $this->resolve_hostname( $entry );
			$addrs    = ( isset( $resolved['ips'] ) && is_array( $resolved['ips'] ) ) ? $resolved['ips'] : array();
			foreach ( $addrs as $addr ) {
				if ( $ip === $this->canonical_ip( $addr ) ) {
					return true;
				}
			}
			return false;
		}

		$bits    = null;
		$network = $entry;
		$slash   = strpos( $entry, '/' );
		if ( false !== $slash ) {
			$network = substr( $entry, 0, $slash );
			$bits    = (int) substr( $entry, $slash + 1 );
		}

		$network = $this->canonical_ip( $network );
		if ( '' === $network ) {
			return false;
		}

		if ( null === $bits ) {
			return $ip === $network;
		}

		return $this->cidr_match( $ip, $network, $bits );
	}

	/**
	 * @param string $line             One non-comment line.
	 * @param bool   $allow_hostnames  Accept an FQDN when this is not an IP.
	 * @return string Canonical entry, or empty when invalid.
	 */
	private function normalize_allowlist_entry( $line, $allow_hostnames = true ) {
		$line  = trim( $line );
		$bits  = null;
		$ip    = $line;
		$slash = strpos( $line, '/' );
		if ( false !== $slash ) {
			$ip      = trim( substr( $line, 0, $slash ) );
			$bit_raw = trim( substr( $line, $slash + 1 ) );
			if ( '' === $bit_raw || ! preg_match( '/^\d+$/', $bit_raw ) ) {
				return '';
			}
			$bits = (int) $bit_raw;
		}

		$ip = $this->canonical_ip( $ip );
		if ( '' === $ip ) {
			if ( null !== $bits || ! $allow_hostnames ) {
				return '';
			}
			return $this->normalize_hostname( $line );
		}

		if ( null === $bits ) {
			return $ip;
		}

		$max = false !== strpos( $ip, ':' ) ? 128 : 32;
		if ( $bits < 0 || $bits > $max ) {
			return '';
		}

		return $ip . '/' . $bits;
	}

	/**
	 * Validate and canonicalize an IP. IPv4-mapped IPv6 becomes IPv4 so an
	 * office IPv4 entry matches proxies that wrap it.
	 *
	 * @param string $ip Raw address.
	 * @return string
	 */
	public function canonical_ip( $ip ) {
		$ip = trim( (string) $ip );
		if ( '' === $ip ) {
			return '';
		}

		if ( preg_match( '/^\[([^\]]+)\](?::\d+)?$/', $ip, $bracket ) ) {
			$ip = $bracket[1];
		} elseif ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $v4port ) ) {
			$ip = $v4port[1];
		}

		$zone = strpos( $ip, '%' );
		if ( false !== $zone ) {
			$ip = substr( $ip, 0, $zone );
		}

		$ip = trim( $ip );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$bin = inet_pton( $ip );
		if ( false === $bin ) {
			return '';
		}

		if ( 16 === strlen( $bin ) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr( $bin, 0, 12 ) ) {
			$v4 = inet_ntop( substr( $bin, 12, 4 ) );
			return is_string( $v4 ) ? $v4 : '';
		}

		$text = inet_ntop( $bin );
		return is_string( $text ) ? $text : '';
	}

	/**
	 * @param string $ip      Canonical client IP.
	 * @param string $network Canonical network address.
	 * @param int    $bits    Prefix length.
	 * @return bool
	 */
	private function cidr_match( $ip, $network, $bits ) {
		$ip_bin      = inet_pton( $ip );
		$network_bin = inet_pton( $network );
		if ( false === $ip_bin || false === $network_bin || strlen( $ip_bin ) !== strlen( $network_bin ) ) {
			return false;
		}

		$max = strlen( $ip_bin ) * 8;
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}

		$bytes = (int) floor( $bits / 8 );
		$rest  = $bits % 8;
		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $network_bin, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $network_bin[ $bytes ] ) & $mask );
	}

	/**
	 * @return true|WP_Error
	 */
	public function assert_not_rate_limited() {
		$settings = css_tc_addon()->get_settings();
		$key      = $this->client_key() . '_lock';
		if ( get_transient( $key ) ) {
			return new WP_Error(
				'css_tc_locked',
				__( 'Too many incorrect PIN attempts. Wait a few minutes and try again.', 'css-timeclock-addon' )
			);
		}

		$fails = (int) get_transient( $this->client_key() . '_fails' );
		if ( $fails >= (int) $settings['rate_limit_max'] ) {
			set_transient( $key, 1, (int) $settings['rate_limit_window'] );
			delete_transient( $this->client_key() . '_fails' );
			return new WP_Error(
				'css_tc_locked',
				__( 'Too many incorrect PIN attempts. Wait a few minutes and try again.', 'css-timeclock-addon' )
			);
		}

		return true;
	}

	/**
	 * @return void
	 */
	public function record_failure() {
		$settings = css_tc_addon()->get_settings();
		$key      = $this->client_key() . '_fails';
		$fails    = (int) get_transient( $key );
		++$fails;
		set_transient( $key, $fails, (int) $settings['rate_limit_window'] );

		if ( $fails >= (int) $settings['rate_limit_max'] ) {
			set_transient( $this->client_key() . '_lock', 1, (int) $settings['rate_limit_window'] );
			delete_transient( $key );
		}
	}

	/**
	 * @return void
	 */
	public function record_success() {
		delete_transient( $this->client_key() . '_fails' );
		delete_transient( $this->client_key() . '_lock' );
	}

	/**
	 * Use an in-memory transient and option store so CLI tests can exercise
	 * hostname caching without WordPress.
	 *
	 * @return void
	 */
	public function use_memory_store() {
		$this->memory = array(
			'transients' => array(),
			'options'    => array(),
			'now'        => null,
		);
	}

	/**
	 * @param callable|null $resolver function (string $host): string[]
	 * @return void
	 */
	public function set_hostname_resolver( $resolver ) {
		$this->hostname_resolver = is_callable( $resolver ) ? $resolver : null;
	}

	/**
	 * @param int $unix Unix time the memory store should report.
	 * @return void
	 */
	public function set_test_now( $unix ) {
		if ( is_array( $this->memory ) ) {
			$this->memory['now'] = (int) $unix;
		}
	}

	/**
	 * FQDN with at least one dot. Labels are 1–63 characters, the whole name
	 * is at most 253, and a trailing dot is stripped. Stored lowercase.
	 *
	 * @param string $line Raw line.
	 * @return string
	 */
	public function normalize_hostname( $line ) {
		$host = strtolower( trim( (string) $line ) );
		if ( '' === $host ) {
			return '';
		}
		if ( '.' === substr( $host, -1 ) ) {
			$host = substr( $host, 0, -1 );
		}
		$len = strlen( $host );
		if ( $len < 1 || $len > 253 || false === strpos( $host, '.' ) ) {
			return '';
		}
		if ( false !== strpos( $host, '/' ) || false !== strpos( $host, ' ' ) || false !== strpos( $host, ':' ) ) {
			return '';
		}

		$labels = explode( '.', $host );
		if ( count( $labels ) < 2 ) {
			return '';
		}
		foreach ( $labels as $label ) {
			$label_len = strlen( $label );
			if ( $label_len < 1 || $label_len > 63 ) {
				return '';
			}
			if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label ) ) {
				return '';
			}
		}

		return $host;
	}

	/**
	 * True when the entry is a hostname rather than an address or CIDR.
	 *
	 * @param string $entry Parsed allowlist entry.
	 * @return bool
	 */
	public function is_hostname_entry( $entry ) {
		$entry = (string) $entry;
		if ( '' === $entry || false !== strpos( $entry, '/' ) ) {
			return false;
		}
		if ( '' !== $this->canonical_ip( $entry ) ) {
			return false;
		}
		return $entry === $this->normalize_hostname( $entry );
	}

	/**
	 * Resolve a hostname, using a 120-second cache. A failed lookup keeps the
	 * last known-good addresses when there are any. With none, the address
	 * list is empty and the client is denied. Never fail open.
	 *
	 * @param string $host Hostname.
	 * @return array{ips: array<int,string>, checked: int, failed: bool, cached: bool}
	 */
	public function resolve_hostname( $host ) {
		$host  = $this->normalize_hostname( $host );
		$blank = array(
			'ips'     => array(),
			'checked' => 0,
			'failed'  => true,
			'cached'  => false,
		);
		if ( '' === $host ) {
			return $blank;
		}

		$cached = $this->store_get_transient( $this->host_cache_key( $host ) );
		if ( is_array( $cached ) ) {
			$cached['cached'] = true;
			if ( ! isset( $cached['ips'] ) || ! is_array( $cached['ips'] ) ) {
				$cached['ips'] = array();
			}
			return $cached;
		}

		$found = $this->dns_lookup( $host );
		$now   = $this->store_now();
		if ( ! empty( $found ) ) {
			$record = array(
				'ips'     => $found,
				'checked' => $now,
				'failed'  => false,
				'cached'  => false,
			);
			$this->store_set_transient( $this->host_cache_key( $host ), $record, self::HOST_CACHE_TTL );
			$this->host_known_set( $host, $found, $now );
			return $record;
		}

		$known = $this->host_known_get( $host );
		$ips   = ( is_array( $known ) && isset( $known['ips'] ) && is_array( $known['ips'] ) ) ? $known['ips'] : array();
		$record = array(
			'ips'     => $ips,
			'checked' => $now,
			'failed'  => true,
			'cached'  => false,
		);
		$this->store_set_transient( $this->host_cache_key( $host ), $record, self::HOST_CACHE_TTL );
		return $record;
	}

	/**
	 * Cached resolution for the settings screen. Does not query DNS.
	 *
	 * @param string $host Hostname.
	 * @return array{host: string, ips: array<int,string>, checked: int, failed: bool, pending: bool}
	 */
	public function hostname_status( $host ) {
		$host  = $this->normalize_hostname( $host );
		$empty = array(
			'host'    => $host,
			'ips'     => array(),
			'checked' => 0,
			'failed'  => false,
			'pending' => true,
		);
		if ( '' === $host ) {
			return $empty;
		}

		$cached = $this->store_get_transient( $this->host_cache_key( $host ) );
		if ( is_array( $cached ) ) {
			return array(
				'host'    => $host,
				'ips'     => ( isset( $cached['ips'] ) && is_array( $cached['ips'] ) ) ? $cached['ips'] : array(),
				'checked' => isset( $cached['checked'] ) ? (int) $cached['checked'] : 0,
				'failed'  => ! empty( $cached['failed'] ),
				'pending' => false,
			);
		}

		$known = $this->host_known_get( $host );
		if ( is_array( $known ) && ! empty( $known['ips'] ) && is_array( $known['ips'] ) ) {
			return array(
				'host'    => $host,
				'ips'     => $known['ips'],
				'checked' => isset( $known['checked'] ) ? (int) $known['checked'] : 0,
				'failed'  => false,
				'pending' => false,
			);
		}

		return $empty;
	}

	/**
	 * Resolve every hostname in an allowlist. Used when an admin saves the
	 * list, not when the settings screen is rendered.
	 *
	 * @param string $raw Allowlist text.
	 * @return void
	 */
	public function warm_hostnames( $raw ) {
		$parsed = $this->parse_allowlist( $raw );
		foreach ( $parsed['entries'] as $entry ) {
			if ( $this->is_hostname_entry( $entry ) ) {
				$this->resolve_hostname( $entry );
			}
		}
	}

	/**
	 * Append a canonical IP when no current entry already covers it.
	 *
	 * @param string $raw Allowlist text.
	 * @param string $ip  Address to add.
	 * @return string
	 */
	public function append_allowlist_ip( $raw, $ip ) {
		$ip = $this->canonical_ip( $ip );
		if ( '' === $ip ) {
			return (string) $raw;
		}
		$parsed = $this->parse_allowlist( (string) $raw );
		foreach ( $parsed['entries'] as $entry ) {
			if ( $this->ip_in_entry( $ip, $entry ) ) {
				return (string) $raw;
			}
		}
		$raw = rtrim( (string) $raw );
		if ( '' === $raw ) {
			return $ip;
		}
		return $raw . "\n" . $ip;
	}

	/**
	 * @param int $unix Unix timestamp.
	 * @return string
	 */
	public function format_eastern( $unix ) {
		$unix = (int) $unix;
		if ( $unix < 1 ) {
			return '';
		}
		try {
			$dt = ( new DateTimeImmutable( '@' . $unix ) )->setTimezone( new DateTimeZone( 'America/New_York' ) );
		} catch ( Exception $e ) {
			return '';
		}
		return $dt->format( 'M j, Y g:i a' ) . ' ET';
	}

	/**
	 * Record a refused kiosk request. One row per IP per minute, capped at
	 * the last 200. The PIN is never stored.
	 *
	 * @param string $ip      Client address.
	 * @param string $action  AJAX action.
	 * @param int    $user_id Employee, when already identified.
	 * @param string $name    Employee display name.
	 * @return bool True when a row was stored.
	 */
	public function record_refused_kiosk( $ip, $action, $user_id = 0, $name = '' ) {
		$ip = $this->canonical_ip( $ip );
		if ( '' === $ip ) {
			return false;
		}
		$bucket = 'css_tc_rf_' . md5( $ip );
		if ( $this->store_get_transient( $bucket ) ) {
			return false;
		}
		$this->store_set_transient( $bucket, 1, self::REFUSED_INTERVAL );

		$log   = $this->refused_kiosk_log();
		$log[] = array(
			'ip'      => $ip,
			'time'    => $this->store_now(),
			'action'  => $this->refused_action_label( $action ),
			'user_id' => max( 0, (int) $user_id ),
			'name'    => $this->refused_employee_label( $name ),
		);
		if ( count( $log ) > self::REFUSED_CAP ) {
			$log = array_slice( $log, -1 * self::REFUSED_CAP );
		}
		$this->store_update_option( self::REFUSED_OPTION, $log );
		return true;
	}

	/**
	 * @return array<int,array{ip:string,time:int,action:string,user_id:int,name:string}>
	 */
	public function refused_kiosk_log() {
		$log = $this->store_get_option( self::REFUSED_OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		$clean = array();
		foreach ( $log as $row ) {
			if ( ! is_array( $row ) || empty( $row['ip'] ) ) {
				continue;
			}
			$clean[] = array(
				'ip'      => (string) $row['ip'],
				'time'    => isset( $row['time'] ) ? (int) $row['time'] : 0,
				'action'  => isset( $row['action'] ) ? (string) $row['action'] : '',
				'user_id' => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
				'name'    => isset( $row['name'] ) ? (string) $row['name'] : '',
			);
		}
		return $clean;
	}

	/**
	 * @param int $seconds Window length.
	 * @return array<int,array{ip:string,time:int,action:string,user_id:int,name:string}>
	 */
	public function refused_kiosk_recent( $seconds ) {
		$cutoff = $this->store_now() - (int) $seconds;
		$out    = array();
		foreach ( $this->refused_kiosk_log() as $row ) {
			if ( (int) $row['time'] >= $cutoff ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Refusals in the window, one row per IP, busiest first.
	 *
	 * @param int $seconds Window length.
	 * @return array<int,array{ip:string,count:int,latest:int}>
	 */
	public function refused_kiosk_summary( $seconds = 3600 ) {
		$counts = array();
		$latest = array();
		foreach ( $this->refused_kiosk_recent( $seconds ) as $row ) {
			$ip = $row['ip'];
			if ( ! isset( $counts[ $ip ] ) ) {
				$counts[ $ip ] = 0;
				$latest[ $ip ] = 0;
			}
			++$counts[ $ip ];
			if ( (int) $row['time'] > $latest[ $ip ] ) {
				$latest[ $ip ] = (int) $row['time'];
			}
		}
		arsort( $counts );
		$out = array();
		foreach ( $counts as $ip => $count ) {
			$out[] = array(
				'ip'     => (string) $ip,
				'count'  => (int) $count,
				'latest' => (int) $latest[ $ip ],
			);
		}
		return $out;
	}

	/**
	 * @param string $host Normalized hostname.
	 * @return string
	 */
	private function host_cache_key( $host ) {
		return 'css_tc_h_' . md5( $host );
	}

	/**
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private function dns_lookup( $host ) {
		if ( is_callable( $this->hostname_resolver ) ) {
			$result = call_user_func( $this->hostname_resolver, $host );
			return $this->canonicalize_resolved( $result );
		}

		$ips = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$a = @dns_get_record( $host, DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $a ) ) {
				foreach ( $a as $row ) {
					if ( is_array( $row ) && ! empty( $row['ip'] ) ) {
						$ips[] = (string) $row['ip'];
					}
				}
			}
			if ( defined( 'DNS_AAAA' ) ) {
				$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $aaaa ) ) {
					foreach ( $aaaa as $row ) {
						if ( is_array( $row ) && ! empty( $row['ipv6'] ) ) {
							$ips[] = (string) $row['ipv6'];
						}
					}
				}
			}
		}
		if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
			$v4 = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $v4 ) ) {
				foreach ( $v4 as $addr ) {
					$ips[] = (string) $addr;
				}
			}
		}
		return $this->canonicalize_resolved( $ips );
	}

	/**
	 * @param mixed $ips Addresses from DNS or a test resolver.
	 * @return string[]
	 */
	private function canonicalize_resolved( $ips ) {
		if ( ! is_array( $ips ) ) {
			return array();
		}
		$out = array();
		foreach ( $ips as $ip ) {
			$canon = $this->canonical_ip( (string) $ip );
			if ( '' !== $canon ) {
				$out[] = $canon;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param string $host Hostname.
	 * @return array<string,mixed>|null
	 */
	private function host_known_get( $host ) {
		$all = $this->store_get_option( self::HOST_KNOWN_OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $host ] ) || ! is_array( $all[ $host ] ) ) {
			return null;
		}
		return $all[ $host ];
	}

	/**
	 * @param string $host    Hostname.
	 * @param string[] $ips   Canonical addresses.
	 * @param int    $checked Unix time.
	 * @return void
	 */
	private function host_known_set( $host, $ips, $checked ) {
		$all = $this->store_get_option( self::HOST_KNOWN_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $host ] = array(
			'ips'     => array_values( $ips ),
			'checked' => (int) $checked,
		);
		$this->store_update_option( self::HOST_KNOWN_OPTION, $all );
	}

	/**
	 * @param string $action Raw action.
	 * @return string
	 */
	private function refused_action_label( $action ) {
		$action = strtolower( (string) $action );
		$action = preg_replace( '/[^a-z0-9_\-]/', '', $action );
		if ( ! is_string( $action ) ) {
			return '';
		}
		return substr( $action, 0, 64 );
	}

	/**
	 * @param string $name Display name.
	 * @return string
	 */
	private function refused_employee_label( $name ) {
		$name = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $name ) ) );
		if ( strlen( $name ) > 80 ) {
			$name = substr( $name, 0, 80 );
		}
		return $name;
	}

	/**
	 * @return int
	 */
	private function store_now() {
		if ( is_array( $this->memory ) && isset( $this->memory['now'] ) && null !== $this->memory['now'] ) {
			return (int) $this->memory['now'];
		}
		return time();
	}

	/**
	 * @param string $key Transient key.
	 * @return mixed
	 */
	private function store_get_transient( $key ) {
		if ( is_array( $this->memory ) ) {
			if ( ! isset( $this->memory['transients'][ $key ] ) ) {
				return false;
			}
			$row = $this->memory['transients'][ $key ];
			if ( (int) $row['expires'] < $this->store_now() ) {
				unset( $this->memory['transients'][ $key ] );
				return false;
			}
			return $row['value'];
		}
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}
		return get_transient( $key );
	}

	/**
	 * @param string $key   Transient key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds.
	 * @return void
	 */
	private function store_set_transient( $key, $value, $ttl ) {
		if ( is_array( $this->memory ) ) {
			$this->memory['transients'][ $key ] = array(
				'value'   => $value,
				'expires' => $this->store_now() + (int) $ttl,
			);
			return;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $value, (int) $ttl );
		}
	}

	/**
	 * @param string $key     Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private function store_get_option( $key, $default ) {
		if ( is_array( $this->memory ) ) {
			return array_key_exists( $key, $this->memory['options'] ) ? $this->memory['options'][ $key ] : $default;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return $default;
		}
		return get_option( $key, $default );
	}

	/**
	 * @param string $key   Option name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function store_update_option( $key, $value ) {
		if ( is_array( $this->memory ) ) {
			$this->memory['options'][ $key ] = $value;
			return;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( $key, $value, false );
		}
	}
}
