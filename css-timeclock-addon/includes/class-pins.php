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
	 * Client address used for failed-PIN rate limits and the office allowlist.
	 *
	 * WP Engine (and a reverse proxy in front of it) puts the visitor in
	 * X-Forwarded-For; REMOTE_ADDR is often the load balancer. This helper
	 * trusts that forwarded address so both features see the same IP the
	 * tablet actually uses. Order: first address in X-Forwarded-For, then
	 * True-Client-IP, then X-Real-IP, then REMOTE_ADDR.
	 *
	 * A proxy must overwrite or append the real client. If it does not, every
	 * request looks like the proxy and an office list will not match the
	 * tablets. Direct clients that can set X-Forwarded-For themselves could
	 * spoof an allowed address — on WP Engine they reach PHP only through the
	 * platform proxy, which is what this trusts.
	 *
	 * @return string Canonical IPv4 or IPv6, or empty when none is valid.
	 */
	public function client_ip() {
		$forwarded_keys = array(
			'HTTP_X_FORWARDED_FOR',
			'HTTP_TRUE_CLIENT_IP',
			'HTTP_X_REAL_IP',
		);

		foreach ( $forwarded_keys as $key ) {
			$ip = $this->ip_from_server_value( $key );
			if ( '' !== $ip ) {
				return $ip;
			}
		}

		return $this->ip_from_server_value( 'REMOTE_ADDR' );
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
	 * IPv6 address, or CIDR, per line.
	 *
	 * @param string $raw Textarea contents.
	 * @return array{entries: array<int,string>, invalid: array<int,string>}
	 */
	public function parse_allowlist( $raw ) {
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

			$normalized = $this->normalize_allowlist_entry( $line );
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
	 * @param string $entry Canonical address or CIDR from parse_allowlist().
	 * @return bool
	 */
	public function ip_in_entry( $ip, $entry ) {
		$ip = $this->canonical_ip( $ip );
		if ( '' === $ip || ! is_string( $entry ) || '' === $entry ) {
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
	 * @param string $line One non-comment line.
	 * @return string Canonical entry, or empty when invalid.
	 */
	private function normalize_allowlist_entry( $line ) {
		$line = trim( $line );
		$bits = null;
		$ip   = $line;
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
			return '';
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
}
