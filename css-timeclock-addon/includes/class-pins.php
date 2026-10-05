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
	 * @param int    $user_id         User ID.
	 * @param string $pin             Plain PIN (will not be stored).
	 * @param bool   $verified_unique The caller has already checked no other employee uses
	 *                                this PIN (the bulk import does, to avoid one hash scan per row).
	 * @return true|WP_Error
	 */
	public function set_pin( $user_id, $pin, $verified_unique = false ) {
		$user_id = (int) $user_id;
		$pin     = $this->normalize( $pin );

		if ( ! css_tc_addon()->employees->is_employee( $user_id ) ) {
			return new WP_Error( 'css_tc_not_employee', __( 'That user is not a time-clock employee.', 'css-timeclock-addon' ) );
		}

		$valid = $this->validate_format( $pin );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! $verified_unique ) {
			$owner = $this->find_user_id_by_pin( $pin );
			if ( $owner && (int) $owner !== $user_id ) {
				return new WP_Error( 'css_tc_pin_taken', __( 'That PIN is already assigned to another employee. Choose a different PIN.', 'css-timeclock-addon' ) );
			}
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
	 * PINs in use, without logging a reveal.
	 *
	 * "known" maps each readable PIN to its employee. "legacy" lists employees
	 * whose PIN was set before PINs could be read back; those can only be
	 * compared by hash.
	 *
	 * @return array{known:array<string,int>,legacy:int[]}
	 */
	public function taken_pin_map() {
		$out   = array(
			'known'  => array(),
			'legacy' => array(),
		);
		$users = get_users(
			array(
				'meta_key'     => self::META_HASH,
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => 1000,
			)
		);
		foreach ( $users as $user ) {
			$user_id = (int) $user;
			// The sealed copy is authenticated, so a successful read is trustworthy
			// and saves one slow hash check per employee.
			$pin = $this->decrypt( (string) get_user_meta( $user_id, self::META_ENC, true ) );
			if ( '' !== $pin ) {
				$out['known'][ $pin ] = $user_id;
			} else {
				$out['legacy'][] = $user_id;
			}
		}
		return $out;
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
		if ( empty( $parsed['entries'] ) && empty( $parsed['hosts'] ) ) {
			return true;
		}

		// A hostname that has never resolved still counts as a rule, so the
		// list stays closed instead of letting every network in.
		return false !== $this->list_match( $ip, $parsed );
	}

	/**
	 * Whether an address is covered by a parsed list.
	 *
	 * @param string                                   $ip     Client address.
	 * @param array{entries:string[],hosts?:string[]} $parsed From parse_allowlist().
	 * @return string|false The matching entry or hostname, or false.
	 */
	public function list_match( $ip, $parsed ) {
		if ( '' === $this->canonical_ip( $ip ) ) {
			return false;
		}
		foreach ( (array) ( $parsed['entries'] ?? array() ) as $entry ) {
			if ( $this->ip_in_entry( $ip, $entry ) ) {
				return $entry;
			}
		}
		foreach ( (array) ( $parsed['hosts'] ?? array() ) as $host ) {
			foreach ( $this->host_ips( $host ) as $host_ip ) {
				if ( $this->ip_in_entry( $ip, $host_ip ) ) {
					return $host;
				}
			}
		}
		return false;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Hostnames (dynamic DNS such as csswilson.ddns.net)
	 * ---------------------------------------------------------------------
	 */

	/** Option holding the lookup cache. */
	const DNS_OPTION = 'css_tc_dns_cache';

	/** Re-check a name at request time when the saved answer is older than this. */
	const DNS_MAX_AGE = 600;

	/** After a failed lookup, wait this long before trying again at request time. */
	const DNS_RETRY = 60;

	/** Background refresh interval. */
	const DNS_REFRESH = 300;

	/**
	 * @var array<string,array{ips:string[],checked:int,ok:int,failed:bool}>|null
	 */
	private $dns_cache = null;

	/**
	 * @var callable|null Test resolver: fn( string $host ): string[]|false.
	 */
	private $resolver = null;

	/**
	 * @var callable|null Test clock: fn(): int.
	 */
	private $clock = null;

	/**
	 * Replace DNS and the clock (tests only).
	 *
	 * @param callable|null $resolver fn( $host ) returning addresses, or false on failure.
	 * @param callable|null $clock    fn() returning a Unix time.
	 * @return void
	 */
	public function set_dns_test_hooks( $resolver, $clock = null ) {
		$this->resolver     = $resolver;
		$this->clock        = $clock;
		$this->dns_cache    = array();
		$this->test_options = null === $resolver && null === $clock ? null : array();
	}

	/**
	 * @return int
	 */
	private function now() {
		return $this->clock ? (int) call_user_func( $this->clock ) : time();
	}

	/**
	 * Valid DNS hostname with at least one dot and a non-numeric last label.
	 *
	 * @param string $name Candidate.
	 * @return string Lower-case hostname, or '' when not a hostname.
	 */
	public function normalize_hostname( $name ) {
		$name = strtolower( rtrim( trim( (string) $name ), '.' ) );
		if ( '' === $name || strlen( $name ) > 253 || false === strpos( $name, '.' ) ) {
			return '';
		}
		$labels = explode( '.', $name );
		foreach ( $labels as $label ) {
			if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label ) ) {
				return '';
			}
		}
		if ( preg_match( '/^\d+$/', (string) end( $labels ) ) ) {
			return ''; // 10.0.0.300 is a bad address, not a hostname.
		}
		return $name;
	}

	/**
	 * @return array<string,array{ips:string[],checked:int,ok:int,failed:bool}>
	 */
	private function dns_cache() {
		if ( null === $this->dns_cache ) {
			$stored          = function_exists( 'get_option' ) ? get_option( self::DNS_OPTION, array() ) : array();
			$this->dns_cache = is_array( $stored ) ? $stored : array();
		}
		return $this->dns_cache;
	}

	/**
	 * @param string                                          $host  Hostname.
	 * @param array{ips:string[],checked:int,ok:int,failed:bool} $entry Cache row.
	 * @return void
	 */
	private function save_dns_entry( $host, $entry ) {
		$cache          = $this->dns_cache();
		$cache[ $host ] = $entry;
		// Forget names nobody has looked up for a week (removed from every list).
		foreach ( $cache as $name => $row ) {
			if ( (int) ( $row['checked'] ?? 0 ) < $this->now() - 7 * 86400 ) {
				unset( $cache[ $name ] );
			}
		}
		$this->dns_cache = $cache;
		if ( null === $this->resolver && function_exists( 'update_option' ) ) {
			update_option( self::DNS_OPTION, $cache, false );
		}
	}

	/**
	 * Addresses a hostname points to right now (A and AAAA records).
	 *
	 * @param string $host Hostname.
	 * @return string[]|false Canonical addresses, or false when the lookup failed.
	 */
	private function lookup( $host ) {
		if ( null !== $this->resolver ) {
			$found = call_user_func( $this->resolver, $host );
		} else {
			$found = array();
			$v4    = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
			if ( is_array( $v4 ) ) {
				$found = $v4;
			}
			if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
				$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				foreach ( is_array( $v6 ) ? $v6 : array() as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$found[] = $record['ipv6'];
					}
				}
			}
		}
		if ( ! is_array( $found ) ) {
			return false;
		}
		$ips = array();
		foreach ( $found as $ip ) {
			$ip = $this->canonical_ip( (string) $ip );
			if ( '' !== $ip ) {
				$ips[] = $ip;
			}
		}
		$ips = array_values( array_unique( $ips ) );
		return empty( $ips ) ? false : $ips;
	}

	/**
	 * Addresses for a hostname, from the cache when fresh enough.
	 *
	 * A failed lookup keeps the last addresses that worked. A name that has
	 * never resolved returns no addresses, so it matches nobody.
	 *
	 * @param string   $host    Hostname from parse_allowlist().
	 * @param int|null $max_age Seconds a saved answer stays good (0 = look up now).
	 * @return string[]
	 */
	public function host_ips( $host, $max_age = null ) {
		$host = $this->normalize_hostname( $host );
		if ( '' === $host ) {
			return array();
		}
		$max_age = null === $max_age ? self::DNS_MAX_AGE : (int) $max_age;
		$cache   = $this->dns_cache();
		$entry   = isset( $cache[ $host ] ) && is_array( $cache[ $host ] ) ? $cache[ $host ] : null;
		$now     = $this->now();

		$stale = null === $entry
			|| ( $now - (int) $entry['checked'] ) >= $max_age;
		if ( $stale && null !== $entry && ! empty( $entry['failed'] ) && $max_age > 0 && ( $now - (int) $entry['checked'] ) < self::DNS_RETRY ) {
			$stale = false; // Just failed; do not slow every punch retrying.
		}

		if ( $stale ) {
			$ips = $this->lookup( $host );
			if ( false === $ips ) {
				$entry = array(
					'ips'     => null !== $entry ? (array) $entry['ips'] : array(),
					'checked' => $now,
					'ok'      => null !== $entry ? (int) $entry['ok'] : 0,
					'failed'  => true,
				);
			} else {
				$entry = array(
					'ips'     => $ips,
					'checked' => $now,
					'ok'      => $now,
					'failed'  => false,
				);
			}
			$this->save_dns_entry( $host, $entry );
		}

		return (array) $entry['ips'];
	}

	/**
	 * What the screen should say about a hostname.
	 *
	 * @param string $host Hostname.
	 * @return array{ips:string[],checked:int,ok:int,failed:bool}
	 */
	public function host_status( $host ) {
		$host = $this->normalize_hostname( $host );
		$this->host_ips( $host );
		$cache = $this->dns_cache();
		return isset( $cache[ $host ] ) ? $cache[ $host ] : array( 'ips' => array(), 'checked' => 0, 'ok' => 0, 'failed' => true );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Refused kiosk requests (from #22)
	 * ---------------------------------------------------------------------
	 */

	const REFUSED_OPTION   = 'css_tc_refused_kiosk';
	const REFUSED_CAP      = 200;
	const REFUSED_INTERVAL = 60;

	/**
	 * @var array<string,mixed>|null In-memory options while testing.
	 */
	private $test_options = null;

	/**
	 * @param string $key     Option.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private function opt_get( $key, $default ) {
		if ( is_array( $this->test_options ) ) {
			return array_key_exists( $key, $this->test_options ) ? $this->test_options[ $key ] : $default;
		}
		return function_exists( 'get_option' ) ? get_option( $key, $default ) : $default;
	}

	/**
	 * @param string $key   Option.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function opt_set( $key, $value ) {
		if ( is_array( $this->test_options ) ) {
			$this->test_options[ $key ] = $value;
			return;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( $key, $value, false );
		}
	}

	/**
	 * Record a refused kiosk request: at most one row per address per
	 * minute, the last 200 kept. A PIN is never stored.
	 *
	 * @param string $ip      Client address.
	 * @param string $action  AJAX action.
	 * @param int    $user_id Employee, when the request already named one.
	 * @param string $name    Employee display name.
	 * @return bool True when a row was stored.
	 */
	public function record_refused_kiosk( $ip, $action, $user_id = 0, $name = '' ) {
		$ip = $this->canonical_ip( $ip );
		if ( '' === $ip ) {
			return false;
		}
		$now = $this->now();
		$log = $this->refused_kiosk_log();
		for ( $i = count( $log ) - 1; $i >= 0; $i-- ) {
			if ( $log[ $i ]['ip'] === $ip ) {
				if ( $now - (int) $log[ $i ]['time'] < self::REFUSED_INTERVAL ) {
					return false;
				}
				break;
			}
		}
		$action = substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $action ) ), 0, 64 );
		$name   = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $name ) ) );
		$log[]  = array(
			'ip'      => $ip,
			'time'    => $now,
			'action'  => $action,
			'user_id' => max( 0, (int) $user_id ),
			'name'    => substr( $name, 0, 80 ),
		);
		if ( count( $log ) > self::REFUSED_CAP ) {
			$log = array_slice( $log, -1 * self::REFUSED_CAP );
		}
		$this->opt_set( self::REFUSED_OPTION, $log );
		return true;
	}

	/**
	 * @return array<int,array{ip:string,time:int,action:string,user_id:int,name:string}>
	 */
	public function refused_kiosk_log() {
		$log   = $this->opt_get( self::REFUSED_OPTION, array() );
		$clean = array();
		foreach ( is_array( $log ) ? $log : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['ip'] ) ) {
				continue;
			}
			$clean[] = array(
				'ip'      => (string) $row['ip'],
				'time'    => (int) ( $row['time'] ?? 0 ),
				'action'  => (string) ( $row['action'] ?? '' ),
				'user_id' => (int) ( $row['user_id'] ?? 0 ),
				'name'    => (string) ( $row['name'] ?? '' ),
			);
		}
		return $clean;
	}

	/**
	 * Refusals in the window, one row per address, busiest first.
	 *
	 * @param int $seconds Window.
	 * @return array<int,array{ip:string,count:int,latest:int}>
	 */
	public function refused_kiosk_summary( $seconds = 3600 ) {
		$cutoff = $this->now() - (int) $seconds;
		$rows   = array();
		foreach ( $this->refused_kiosk_log() as $row ) {
			if ( $row['time'] < $cutoff ) {
				continue;
			}
			$ip = $row['ip'];
			if ( ! isset( $rows[ $ip ] ) ) {
				$rows[ $ip ] = array( 'ip' => $ip, 'count' => 0, 'latest' => 0 );
			}
			++$rows[ $ip ]['count'];
			$rows[ $ip ]['latest'] = max( $rows[ $ip ]['latest'], $row['time'] );
		}
		$rows = array_values( $rows );
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);
		return $rows;
	}

	/**
	 * Add an address to allowlist text unless a line already covers it.
	 *
	 * @param string $raw Allowlist text.
	 * @param string $ip  Address.
	 * @return string
	 */
	public function append_allowlist_ip( $raw, $ip ) {
		$ip = $this->canonical_ip( $ip );
		if ( '' === $ip || false !== $this->list_match( $ip, $this->parse_allowlist( (string) $raw ) ) ) {
			return (string) $raw;
		}
		$raw = rtrim( (string) $raw );
		return '' === $raw ? $ip : $raw . "\n" . $ip;
	}

	/**
	 * Look up every hostname used anywhere (cron and after saving).
	 *
	 * @param string[] $hosts Hostnames.
	 * @return void
	 */
	public function refresh_hosts( $hosts ) {
		foreach ( array_unique( (array) $hosts ) as $host ) {
			$this->host_ips( (string) $host, 0 );
		}
	}

	/**
	 * Split an allowlist into valid entries and rejected lines.
	 *
	 * Blank lines and lines whose first non-space character is # are comments.
	 * A # later on a line starts an inline comment. Entries are one IPv4 or
	 * IPv6 address, or CIDR, per line.
	 *
	 * A line may also be a hostname (for example an office's dynamic DNS name)
	 * when $allow_hosts is true; those go in 'hosts' and are looked up when
	 * checked.
	 *
	 * @param string $raw         Textarea contents.
	 * @param bool   $allow_hosts Accept hostnames.
	 * @return array{entries: array<int,string>, hosts: array<int,string>, invalid: array<int,string>}
	 */
	public function parse_allowlist( $raw, $allow_hosts = true ) {
		$entries = array();
		$hosts   = array();
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
				$host = $allow_hosts ? $this->normalize_hostname( $line ) : '';
				if ( '' !== $host ) {
					$hosts[] = $host;
					continue;
				}
				$invalid[] = $line;
				continue;
			}
			$entries[] = $normalized;
		}

		return array(
			'entries' => array_values( array_unique( $entries ) ),
			'hosts'   => array_values( array_unique( $hosts ) ),
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
