<?php
/**
 * Bulk employee import from a CSV file.
 *
 * The parsing and row checking are plain PHP (no WordPress calls) so
 * bin/check-import.php can test them. The WordPress part, which creates the
 * accounts, is further down.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV → preview → confirm → WordPress users with time clock details.
 */
class Css_Tc_Import {

	const ACTION_PREVIEW  = 'css_tc_import_preview';
	const ACTION_COMMIT   = 'css_tc_import_commit';
	const ACTION_TEMPLATE = 'css_tc_import_template';
	const ACTION_RESULTS  = 'css_tc_import_results';
	const NONCE           = 'css_tc_import';
	const LOG_OPTION      = 'css_tc_import_log';
	const MAX_ROWS        = 200;
	const MAX_BYTES       = 524288;
	const MIN_PASSWORD    = 8;
	const MAX_PASSWORD    = 150;
	const TTL             = 900;

	// ------------------------------------------------------------------
	// Pure helpers (no WordPress)
	// ------------------------------------------------------------------

	/**
	 * Canonical column keys, in template order.
	 *
	 * @return string[]
	 */
	public static function columns() {
		return array( 'first_name', 'last_name', 'email', 'username', 'password', 'role', 'hire_date', 'pin' );
	}

	/**
	 * Map a header cell to a canonical column key ('' if not recognised).
	 *
	 * @param string $raw Header text.
	 * @return string
	 */
	public static function canonical_header( $raw ) {
		$key = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', (string) $raw ) );
		$map = array(
			'firstname'    => 'first_name',
			'first'        => 'first_name',
			'fname'        => 'first_name',
			'givenname'    => 'first_name',
			'lastname'     => 'last_name',
			'last'         => 'last_name',
			'lname'        => 'last_name',
			'surname'      => 'last_name',
			'familyname'   => 'last_name',
			'email'        => 'email',
			'emailaddress' => 'email',
			'workemail'    => 'email',
			'username'     => 'username',
			'login'        => 'username',
			'userlogin'    => 'username',
			'password'     => 'password',
			'pass'         => 'password',
			'role'         => 'role',
			'hiredate'     => 'hire_date',
			'hired'        => 'hire_date',
			'startdate'    => 'hire_date',
			'dateofhire'   => 'hire_date',
			'pin'          => 'pin',
			'badge'        => 'pin',
			'badgeno'      => 'pin',
			'badgenumber'  => 'pin',
			'badgeid'      => 'pin',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * Parse CSV text.
	 *
	 * @param string $text File contents.
	 * @return array{ok:bool,error:string,ignored:string[],rows:array<int,array{line:int,cells:array<string,string>}>}
	 */
	public static function parse_csv( $text ) {
		$out  = array(
			'ok'      => false,
			'error'   => '',
			'ignored' => array(),
			'rows'    => array(),
		);
		$text = (string) $text;
		if ( 0 === strncmp( $text, "\xEF\xBB\xBF", 3 ) ) {
			$text = substr( $text, 3 );
		}
		if ( '' === trim( $text ) ) {
			$out['error'] = 'The file is empty.';
			return $out;
		}

		// Delimiter: whichever of , ; tab is most common on the first line.
		$first = strtok( str_replace( "\r", "\n", $text ), "\n" );
		$best  = ',';
		$most  = -1;
		foreach ( array( ',', ';', "\t" ) as $d ) {
			$n = substr_count( (string) $first, $d );
			if ( $n > $most ) {
				$most = $n;
				$best = $d;
			}
		}

		$h = fopen( 'php://memory', 'r+' );
		if ( false === $h ) {
			$out['error'] = 'Could not read the file.';
			return $out;
		}
		fwrite( $h, $text );
		rewind( $h );

		$records = array();
		while ( false !== ( $rec = fgetcsv( $h, 0, $best, '"', '' ) ) ) {
			$records[] = $rec;
		}
		fclose( $h );

		if ( empty( $records ) ) {
			$out['error'] = 'The file is empty.';
			return $out;
		}

		$header = array_shift( $records );
		$index  = array();
		foreach ( (array) $header as $i => $cell ) {
			$cell = trim( (string) $cell );
			if ( '' === $cell ) {
				continue;
			}
			$key = self::canonical_header( $cell );
			if ( '' === $key ) {
				$out['ignored'][] = $cell;
			} elseif ( isset( $index[ $key ] ) ) {
				$out['ignored'][] = $cell . ' (repeated)';
			} else {
				$index[ $key ] = (int) $i;
			}
		}
		$missing = array();
		foreach ( array( 'first_name', 'last_name', 'email' ) as $need ) {
			if ( ! isset( $index[ $need ] ) ) {
				$missing[] = $need;
			}
		}
		if ( ! empty( $missing ) ) {
			$out['error'] = 'The first row must have these column headings: first_name, last_name, email. Missing: ' . implode( ', ', $missing ) . '.';
			return $out;
		}

		foreach ( $records as $n => $rec ) {
			$cells = array();
			foreach ( self::columns() as $key ) {
				$cells[ $key ] = ( isset( $index[ $key ] ) && isset( $rec[ $index[ $key ] ] ) ) ? trim( (string) $rec[ $index[ $key ] ] ) : '';
			}
			$out['rows'][] = array(
				'line'  => $n + 2, // Row 1 is the heading row.
				'cells' => $cells,
			);
		}

		$out['ok'] = true;
		return $out;
	}

	/**
	 * Y-m-d, m/d/Y or m/d/yy → Y-m-d.
	 *
	 * @param string $raw Cell text.
	 * @return string|false '' when blank, false when not a real date.
	 */
	public static function normalize_date( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$#', $raw, $m ) ) {
			$y = (int) $m[1];
			$mo = (int) $m[2];
			$d = (int) $m[3];
		} elseif ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4}|\d{2})$#', $raw, $m ) ) {
			$mo = (int) $m[1];
			$d  = (int) $m[2];
			$y  = (int) $m[3];
			if ( $y < 100 ) {
				$y += 2000;
			}
		} else {
			return false;
		}
		if ( ! checkdate( $mo, $d, $y ) || $y < 1950 || $y > 2100 ) {
			return false;
		}
		return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
	}

	/**
	 * Stop a spreadsheet running a cell that starts like a formula.
	 *
	 * @param string $s Cell.
	 * @return string
	 */
	public static function neutralize( $s ) {
		$s = (string) $s;
		return ( '' !== $s && preg_match( '/^[=+\-@\t\r]/', $s ) ) ? "'" . $s : $s;
	}

	/**
	 * One CSV line from a list of cells (formula characters neutralized).
	 *
	 * @param array<int,mixed> $cells Cells.
	 * @return string
	 */
	public static function csv_line( $cells ) {
		$h = fopen( 'php://temp', 'r+' );
		if ( false === $h ) {
			return '';
		}
		fputcsv( $h, array_map( array( __CLASS__, 'neutralize' ), array_map( 'strval', (array) $cells ) ), ',', '"', '' );
		rewind( $h );
		$line = (string) stream_get_contents( $h );
		fclose( $h );
		return $line;
	}

	/**
	 * Check one parsed row.
	 *
	 * $env keys: roles (slug[]), role_aliases (lowercase slug or label => slug),
	 * default_role, update_existing (bool), pin_min, pin_max,
	 * email_user (callable email → array{id:int,is_employee:bool}|null),
	 * login_exists (callable login → bool), pin_owner (callable pin → user id or 0).
	 *
	 * @param array<string,string> $cells Row cells.
	 * @param int                  $line  Row number.
	 * @param array<string,mixed>  $env   Lookups and options.
	 * @param array<string,array>  $seen  Values already used in this file (by reference).
	 * @return array<string,mixed>|null Null for a blank row.
	 */
	public static function plan_row( $cells, $line, $env, &$seen ) {
		foreach ( array( 'email', 'username', 'pin' ) as $k ) {
			if ( ! isset( $seen[ $k ] ) ) {
				$seen[ $k ] = array();
			}
		}
		$cells = array_map( 'trim', array_map( 'strval', $cells ) );
		if ( '' === implode( '', $cells ) ) {
			return null;
		}

		$row = array(
			'line'         => (int) $line,
			'status'       => 'create',
			'message'      => '',
			'first_name'   => $cells['first_name'],
			'last_name'    => $cells['last_name'],
			'email'        => strtolower( $cells['email'] ),
			'username'     => '',
			'password'     => '',
			'role'         => '',
			'hire_date'    => '',
			'pin'          => '',
			'pin_generate' => false,
			'user_id'      => 0,
		);

		$errors = array();
		foreach ( array(
			'first_name' => 'First name',
			'last_name'  => 'Last name',
			'email'      => 'Email',
		) as $k => $label ) {
			if ( '' === $cells[ $k ] ) {
				$errors[] = $label . ' is required.';
			}
		}
		if ( '' !== $cells['email'] && ( strlen( $cells['email'] ) > 100 || ! filter_var( $cells['email'], FILTER_VALIDATE_EMAIL ) ) ) {
			$errors[] = 'Email is not valid.';
		}
		if ( strlen( $cells['first_name'] ) > 100 || strlen( $cells['last_name'] ) > 100 ) {
			$errors[] = 'A name is too long (100 characters at most).';
		}

		$existing = null;
		if ( '' !== $row['email'] && empty( $errors ) ) {
			if ( isset( $seen['email'][ $row['email'] ] ) ) {
				$errors[] = 'This email is also on row ' . $seen['email'][ $row['email'] ] . '.';
			} else {
				$seen['email'][ $row['email'] ] = (int) $line;
				$existing                       = call_user_func( $env['email_user'], $row['email'] );
			}
		}

		if ( ! empty( $errors ) ) {
			$row['status']  = 'error';
			$row['message'] = implode( ' ', $errors );
			return $row;
		}

		// Existing account.
		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$row['user_id'] = (int) $existing['id'];
			if ( empty( $env['update_existing'] ) ) {
				$row['status']  = 'skip';
				$row['message'] = 'Already has an account, so it is left alone.';
				return $row;
			}
			if ( empty( $existing['is_employee'] ) ) {
				$row['status']  = 'error';
				$row['message'] = 'This email belongs to an account that is not a time clock employee, so the import will not change it.';
				return $row;
			}
			$row['status'] = 'update';
		}

		// Username (new accounts only).
		if ( 'create' === $row['status'] ) {
			$login = '' !== $cells['username'] ? $cells['username'] : $cells['email'];
			if ( ! preg_match( '/^[A-Za-z0-9 _.\-@]{1,60}$/', $login ) ) {
				$errors[] = 'Username can use letters, numbers, spaces and _ . - @ (60 characters at most).';
			} elseif ( isset( $seen['username'][ strtolower( $login ) ] ) ) {
				$errors[] = 'Username is also on row ' . $seen['username'][ strtolower( $login ) ] . '.';
			} elseif ( call_user_func( $env['login_exists'], $login ) ) {
				$errors[] = 'Username is already taken.';
			} else {
				$seen['username'][ strtolower( $login ) ] = (int) $line;
			}
			$row['username'] = $login;
		}

		// Password.
		if ( '' !== $cells['password'] ) {
			$len = strlen( $cells['password'] );
			if ( $len < self::MIN_PASSWORD ) {
				$errors[] = 'Password must be at least ' . self::MIN_PASSWORD . ' characters.';
			} elseif ( $len > self::MAX_PASSWORD ) {
				$errors[] = 'Password is too long.';
			} else {
				$row['password'] = $cells['password'];
			}
		}

		// Role.
		if ( '' !== $cells['role'] ) {
			$want = strtolower( $cells['role'] );
			if ( in_array( $want, (array) $env['roles'], true ) ) {
				$row['role'] = $want;
			} elseif ( isset( $env['role_aliases'][ $want ] ) ) {
				$row['role'] = (string) $env['role_aliases'][ $want ];
			} else {
				$errors[] = 'Role "' . $cells['role'] . '" is not an allowed time clock role.';
			}
		} elseif ( 'create' === $row['status'] ) {
			$row['role'] = (string) $env['default_role'];
		}

		// Hire date.
		$date = self::normalize_date( $cells['hire_date'] );
		if ( false === $date ) {
			$errors[] = 'Hire date must look like 2026-03-02 or 3/2/2026.';
		} else {
			$row['hire_date'] = $date;
		}

		// PIN.
		if ( '' !== $cells['pin'] ) {
			$pin = (string) preg_replace( '/\D+/', '', $cells['pin'] );
			if ( '' === $pin ) {
				$errors[] = 'PIN must be digits.';
			} elseif ( strlen( $pin ) < (int) $env['pin_min'] || strlen( $pin ) > (int) $env['pin_max'] ) {
				$errors[] = 'PIN must be ' . (int) $env['pin_min'] . ' to ' . (int) $env['pin_max'] . ' digits (a spreadsheet may have dropped a leading zero).';
			} elseif ( isset( $seen['pin'][ $pin ] ) ) {
				$errors[] = 'PIN is also on row ' . $seen['pin'][ $pin ] . '.';
			} else {
				$owner = (int) call_user_func( $env['pin_owner'], $pin );
				if ( $owner > 0 && $owner !== (int) $row['user_id'] ) {
					$errors[] = 'PIN is already used by another employee.';
				} else {
					$seen['pin'][ $pin ] = (int) $line;
					$row['pin']          = $pin;
				}
			}
		} elseif ( 'create' === $row['status'] ) {
			$row['pin_generate'] = true;
		}

		if ( ! empty( $errors ) ) {
			$row['status']  = 'error';
			$row['message'] = implode( ' ', $errors );
		}
		return $row;
	}

	/**
	 * Count rows by status.
	 *
	 * @param array<int,array<string,mixed>> $rows Planned rows.
	 * @return array{create:int,update:int,skip:int,error:int}
	 */
	public static function counts( $rows ) {
		$c = array(
			'create' => 0,
			'update' => 0,
			'skip'   => 0,
			'error'  => 0,
		);
		foreach ( $rows as $r ) {
			if ( isset( $c[ $r['status'] ] ) ) {
				++$c[ $r['status'] ];
			}
		}
		return $c;
	}

	/**
	 * A random PIN of the given length that is not in $taken.
	 *
	 * @param int                $length Digits.
	 * @param array<string,bool> $taken  PINs in use (keys).
	 * @return string '' if none could be found.
	 */
	public static function random_pin( $length, $taken ) {
		$length = max( 4, (int) $length );
		for ( $i = 0; $i < 200; $i++ ) {
			$pin = '';
			for ( $j = 0; $j < $length; $j++ ) {
				$pin .= (string) random_int( 0, 9 );
			}
			if ( ! isset( $taken[ $pin ] ) ) {
				return $pin;
			}
		}
		return '';
	}

	/**
	 * The template file text.
	 *
	 * @return string
	 */
	public static function template_csv() {
		return self::csv_line( self::columns() )
			. self::csv_line( array( 'Jamie', 'Rivera', 'jamie.rivera@example.com', '', 'ChangeMe-2026', 'employee', '2026-03-02', '' ) )
			. self::csv_line( array( 'Sam', 'Okafor', 'sam.okafor@example.com', '', '', '', '', '' ) );
	}

	// ------------------------------------------------------------------
	// WordPress side
	// ------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_' . self::ACTION_PREVIEW, array( $this, 'handle_preview' ) );
		add_action( 'admin_post_' . self::ACTION_COMMIT, array( $this, 'handle_commit' ) );
		add_action( 'admin_post_' . self::ACTION_TEMPLATE, array( $this, 'handle_template' ) );
		add_action( 'admin_post_' . self::ACTION_RESULTS, array( $this, 'handle_results' ) );
	}

	/**
	 * Roles an import may give: the time clock employee roles that exist,
	 * never administrator.
	 *
	 * @return array<string,string> slug => label.
	 */
	public function allowed_roles() {
		$out   = array();
		$roles = wp_roles();
		foreach ( css_tc_addon()->employees->aio_roles() as $slug ) {
			if ( 'administrator' === $slug || ! $roles->is_role( $slug ) ) {
				continue;
			}
			$out[ $slug ] = translate_user_role( $roles->roles[ $slug ]['name'] );
		}
		return $out;
	}

	/**
	 * @return string Default role slug ('' if no time clock role exists).
	 */
	public function default_role() {
		$roles = array_keys( $this->allowed_roles() );
		if ( in_array( 'employee', $roles, true ) ) {
			return 'employee';
		}
		return empty( $roles ) ? '' : $roles[0];
	}

	/**
	 * Back to the Import tab with a message or a saved-state token.
	 *
	 * @param array<string,string> $args Query args.
	 * @return void
	 */
	private function back( $args ) {
		$url = add_query_arg( array_merge( array( 'page' => 'css-tc-addon', 'tab' => 'import' ), $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @return void
	 */
	private function require_manager() {
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to import employees.', 'css-timeclock-addon' ), 403 );
		}
	}

	/**
	 * @return void
	 */
	public function handle_template() {
		$this->require_manager();
		check_admin_referer( self::NONCE );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="employee-import-template.csv"' );
		echo self::template_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Upload + check. Nothing is saved to users yet.
	 *
	 * @return void
	 */
	public function handle_preview() {
		$this->require_manager();
		check_admin_referer( self::NONCE );

		$file = isset( $_FILES['css_tc_import_file'] ) ? $_FILES['css_tc_import_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->back( array( 'css_tc_imp_err' => rawurlencode( __( 'Choose a CSV file to upload.', 'css-timeclock-addon' ) ) ) );
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			$this->back( array( 'css_tc_imp_err' => rawurlencode( __( 'That file is too large for an import (500 KB at most).', 'css-timeclock-addon' ) ) ) );
		}
		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		if ( ! preg_match( '/\.(csv|txt)$/i', $name ) ) {
			$this->back( array( 'css_tc_imp_err' => rawurlencode( __( 'Upload a .csv file (in Excel or Google Sheets: File → Save as / Download → CSV).', 'css-timeclock-addon' ) ) ) );
		}
		$text = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $text ) {
			$this->back( array( 'css_tc_imp_err' => rawurlencode( __( 'Could not read the file.', 'css-timeclock-addon' ) ) ) );
		}
		if ( ! function_exists( 'mb_check_encoding' ) || mb_check_encoding( $text, 'UTF-8' ) ) {
			$clean = $text;
		} else {
			$clean = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' ) : $text;
		}

		$parsed = self::parse_csv( $clean );
		if ( ! $parsed['ok'] ) {
			$this->back( array( 'css_tc_imp_err' => rawurlencode( $parsed['error'] ) ) );
		}
		if ( count( $parsed['rows'] ) > self::MAX_ROWS ) {
			$this->back( array( 'css_tc_imp_err' => rawurlencode( sprintf( /* translators: %d: row limit */ __( 'That file has more than %d rows. Split it into smaller files.', 'css-timeclock-addon' ), self::MAX_ROWS ) ) ) );
		}

		$roles = $this->allowed_roles();
		$role  = isset( $_POST['css_tc_import_role'] ) ? sanitize_key( wp_unslash( $_POST['css_tc_import_role'] ) ) : '';
		if ( ! isset( $roles[ $role ] ) ) {
			$role = $this->default_role();
		}
		if ( '' === $role ) {
			$this->back( array( 'css_tc_imp_err' => rawurlencode( __( 'No time clock employee role exists on this site, so new accounts cannot be created.', 'css-timeclock-addon' ) ) ) );
		}

		$org  = css_tc_addon()->organization;
		$dept = isset( $_POST['css_tc_import_dept'] ) ? absint( $_POST['css_tc_import_dept'] ) : 0;
		if ( $dept > 0 && ( ! $org->enabled() || ! $org->department( $dept ) ) ) {
			$dept = 0;
		}

		$opts = array(
			'role'            => $role,
			'dept'            => $dept,
			'update_existing' => ! empty( $_POST['css_tc_import_update'] ),
			'send_email'      => ! empty( $_POST['css_tc_import_email'] ),
			'file'            => $name,
		);

		$env  = $this->env( $opts );
		$seen = array();
		$rows = array();
		foreach ( $parsed['rows'] as $r ) {
			$planned = self::plan_row( $r['cells'], $r['line'], $env, $seen );
			if ( null !== $planned ) {
				$rows[] = $planned;
			}
		}
		if ( empty( $rows ) ) {
			$this->back( array( 'css_tc_imp_err' => rawurlencode( __( 'The file has a heading row but no employees.', 'css-timeclock-addon' ) ) ) );
		}

		$token = $this->store(
			array(
				'kind'    => 'preview',
				'opts'    => $opts,
				'rows'    => $rows,
				'ignored' => $parsed['ignored'],
			)
		);
		if ( '' === $token ) {
			$this->back( array( 'css_tc_imp_err' => rawurlencode( __( 'This server cannot hold the preview securely (the sodium library is missing).', 'css-timeclock-addon' ) ) ) );
		}
		$this->back( array( 'css_tc_import' => $token ) );
	}

	/**
	 * Lookups for plan_row().
	 *
	 * @param array<string,mixed> $opts Import options.
	 * @return array<string,mixed>
	 */
	private function env( $opts ) {
		$settings = css_tc_addon()->get_settings();
		$min      = max( 4, (int) $settings['pin_min_length'] );
		$max      = max( $min, (int) $settings['pin_max_length'] );
		$roles    = $this->allowed_roles();
		$alias    = array();
		foreach ( $roles as $slug => $label ) {
			$alias[ strtolower( $label ) ] = $slug;
		}
		$pin_map = css_tc_addon()->pins->taken_pin_map();
		return array(
			'roles'           => array_keys( $roles ),
			'role_aliases'    => $alias,
			'default_role'    => $opts['role'],
			'update_existing' => ! empty( $opts['update_existing'] ),
			'pin_min'         => $min,
			'pin_max'         => $max,
			'email_user'      => static function ( $email ) {
				$user = get_user_by( 'email', $email );
				if ( ! $user ) {
					return null;
				}
				return array(
					'id'          => (int) $user->ID,
					'is_employee' => css_tc_addon()->employees->is_employee( (int) $user->ID ),
				);
			},
			'login_exists'    => static function ( $login ) {
				return (bool) username_exists( $login );
			},
			'pin_owner'       => static function ( $pin ) use ( $pin_map ) {
				if ( isset( $pin_map['known'][ $pin ] ) ) {
					return (int) $pin_map['known'][ $pin ];
				}
				// Older PINs that cannot be read back: compare their hashes.
				foreach ( $pin_map['legacy'] as $uid ) {
					$hash = get_user_meta( (int) $uid, Css_Tc_Pins::META_HASH, true );
					if ( is_string( $hash ) && '' !== $hash && wp_check_password( $pin, $hash, (int) $uid ) ) {
						return (int) $uid;
					}
				}
				return 0;
			},
		);
	}

	/**
	 * Create or update the accounts from a stored preview.
	 *
	 * @return void
	 */
	public function handle_commit() {
		$this->require_manager();
		check_admin_referer( self::NONCE );
		$token = isset( $_POST['css_tc_import_token'] ) ? sanitize_key( wp_unslash( $_POST['css_tc_import_token'] ) ) : '';
		$data  = $this->load( $token );
		if ( ! $data || 'preview' !== $data['kind'] ) {
			$this->back( array( 'css_tc_imp_err' => rawurlencode( __( 'That preview expired. Upload the file again.', 'css-timeclock-addon' ) ) ) );
		}
		// One use only.
		delete_transient( $this->key( $token ) );

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		$opts    = $data['opts'];
		$org     = css_tc_addon()->organization;
		$pins    = css_tc_addon()->pins;
		$pin_map = $pins->taken_pin_map();
		$taken   = array();
		foreach ( $pin_map['known'] as $p => $uid ) {
			$taken[ (string) $p ] = true;
		}
		$settings = css_tc_addon()->get_settings();
		$pin_len  = max( 4, (int) $settings['pin_min_length'] );

		$results = array();
		foreach ( $data['rows'] as $row ) {
			$res = array(
				'line'         => $row['line'],
				'result'       => 'skipped',
				'message'      => $row['message'],
				'email'        => $row['email'],
				'username'     => $row['username'],
				'first_name'   => $row['first_name'],
				'last_name'    => $row['last_name'],
				'role'         => $row['role'],
				'hire_date'    => $row['hire_date'],
				'pin_new'      => '',
				'warnings'     => array(),
			);
			if ( 'error' === $row['status'] ) {
				$res['result'] = 'error';
				$results[]     = $res;
				continue;
			}
			if ( 'skip' === $row['status'] ) {
				$results[] = $res;
				continue;
			}

			$is_new = ( 'create' === $row['status'] );
			if ( $is_new ) {
				$password = '' !== $row['password'] ? $row['password'] : wp_generate_password( 24, true, true );
				$user_id  = wp_insert_user(
					array(
						'user_login'   => $row['username'],
						'user_email'   => $row['email'],
						'user_pass'    => $password,
						'first_name'   => $row['first_name'],
						'last_name'    => $row['last_name'],
						'display_name' => trim( $row['first_name'] . ' ' . $row['last_name'] ),
						'nickname'     => trim( $row['first_name'] . ' ' . $row['last_name'] ),
						'role'         => $row['role'],
					)
				);
				if ( is_wp_error( $user_id ) ) {
					$res['result']  = 'error';
					$res['message'] = $user_id->get_error_message();
					$results[]      = $res;
					continue;
				}
				$res['result']  = 'created';
				$res['message'] = '';
			} else {
				$user_id = (int) $row['user_id'];
				$fields  = array(
					'ID'           => $user_id,
					'first_name'   => $row['first_name'],
					'last_name'    => $row['last_name'],
					'display_name' => trim( $row['first_name'] . ' ' . $row['last_name'] ),
				);
				if ( '' !== $row['password'] ) {
					$fields['user_pass'] = $row['password'];
				}
				$updated = wp_update_user( $fields );
				if ( is_wp_error( $updated ) ) {
					$res['result']  = 'error';
					$res['message'] = $updated->get_error_message();
					$results[]      = $res;
					continue;
				}
				if ( '' !== $row['role'] ) {
					$user = get_userdata( $user_id );
					if ( $user && ! in_array( $row['role'], (array) $user->roles, true ) ) {
						$user->set_role( $row['role'] );
					}
				}
				$res['result']  = 'updated';
				$res['message'] = '';
			}

			if ( '' !== $row['hire_date'] ) {
				update_user_meta( $user_id, Css_Tc_Holidays::META_HIRE, $row['hire_date'] );
			}

			// PIN: the one given, or a fresh one for a new account.
			$pin = $row['pin'];
			if ( '' === $pin && ! empty( $row['pin_generate'] ) ) {
				$pin = self::random_pin( $pin_len, $taken );
				if ( '' === $pin ) {
					$res['warnings'][] = __( 'Could not generate a free PIN. Set one on the Employee tab.', 'css-timeclock-addon' );
				} else {
					$res['pin_new'] = $pin;
				}
			}
			if ( '' !== $pin ) {
				$set = $pins->set_pin( $user_id, $pin, true );
				if ( is_wp_error( $set ) ) {
					$res['pin_new']    = '';
					$res['warnings'][] = sprintf( /* translators: %s: reason */ __( 'PIN not set: %s', 'css-timeclock-addon' ), $set->get_error_message() );
				} else {
					$taken[ $pin ] = true;
				}
			}

			// Default department (only where the employee has none yet).
			if ( ! empty( $opts['dept'] ) && $org->department( (int) $opts['dept'] ) && empty( $org->assigned( $user_id ) ) ) {
				$org->set_assignments( $user_id, array( (int) $opts['dept'] ), (int) $opts['dept'] );
			}

			if ( $is_new && ! empty( $opts['send_email'] ) ) {
				wp_new_user_notification( $user_id, null, 'user' );
			}
			$results[] = $res;
		}

		$tally = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'error'   => 0,
		);
		foreach ( $results as $r ) {
			if ( isset( $tally[ $r['result'] ] ) ) {
				++$tally[ $r['result'] ];
			}
		}
		$this->log( $opts, $tally );

		$out = $this->store(
			array(
				'kind'    => 'results',
				'opts'    => array( 'file' => $opts['file'] ),
				'results' => $results,
				'tally'   => $tally,
			)
		);
		$this->back( '' !== $out ? array( 'css_tc_import_done' => $out ) : array( 'css_tc_imp_msg' => rawurlencode( __( 'Import finished.', 'css-timeclock-addon' ) ) ) );
	}

	/**
	 * Results CSV (generated PINs included, passwords never).
	 *
	 * @return void
	 */
	public function handle_results() {
		$this->require_manager();
		check_admin_referer( self::NONCE );
		$token = isset( $_GET['css_tc_import_done'] ) ? sanitize_key( wp_unslash( $_GET['css_tc_import_done'] ) ) : '';
		$data  = $this->load( $token );
		if ( ! $data || 'results' !== $data['kind'] ) {
			wp_die( esc_html__( 'Those results have expired.', 'css-timeclock-addon' ), 410 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="employee-import-results.csv"' );
		$out = self::csv_line( array( 'row', 'result', 'message', 'email', 'username', 'first_name', 'last_name', 'role', 'hire_date', 'pin_generated', 'notes' ) );
		foreach ( $data['results'] as $r ) {
			$out .= self::csv_line(
				array(
					$r['line'],
					$r['result'],
					$r['message'],
					$r['email'],
					$r['username'],
					$r['first_name'],
					$r['last_name'],
					$r['role'],
					$r['hire_date'],
					$r['pin_new'],
					implode( ' ', (array) $r['warnings'] ),
				)
			);
		}
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Keep the most recent 50 imports.
	 *
	 * @param array<string,mixed> $opts  Options.
	 * @param array<string,int>   $tally Counts.
	 * @return void
	 */
	private function log( $opts, $tally ) {
		$log   = get_option( self::LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'by'      => get_current_user_id(),
			'at'      => time(),
			'file'    => (string) $opts['file'],
			'created' => (int) $tally['created'],
			'updated' => (int) $tally['updated'],
			'skipped' => (int) $tally['skipped'],
			'error'   => (int) $tally['error'],
		);
		update_option( self::LOG_OPTION, array_slice( $log, -50 ), false );
	}

	/**
	 * @return array<int,array<string,mixed>> Newest first.
	 */
	public function recent_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? array_slice( array_reverse( $log ), 0, 10 ) : array();
	}

	// ------------------------------------------------------------------
	// Short-lived encrypted hold for the preview and the results
	// ------------------------------------------------------------------

	/**
	 * @param string $token Token.
	 * @return string
	 */
	private function key( $token ) {
		return 'css_tc_imp_' . get_current_user_id() . '_' . $token;
	}

	/**
	 * @return string
	 */
	private function secret() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|css_tc_import', true );
	}

	/**
	 * The preview holds passwords from the file, so it is encrypted and
	 * expires after 15 minutes.
	 *
	 * @param array<string,mixed> $data Payload.
	 * @return string Token, or '' when it could not be stored.
	 */
	private function store( $data ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$blob  = base64_encode( $nonce . sodium_crypto_secretbox( (string) wp_json_encode( $data ), $nonce, $this->secret() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( Exception $e ) {
			return '';
		}
		$token = strtolower( wp_generate_password( 24, false, false ) );
		set_transient( $this->key( $token ), $blob, self::TTL );
		return $token;
	}

	/**
	 * @param string $token Token.
	 * @return array<string,mixed>|null
	 */
	public function load( $token ) {
		if ( '' === $token || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		$blob = get_transient( $this->key( $token ) );
		if ( ! is_string( $blob ) ) {
			return null;
		}
		$raw = base64_decode( $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->secret() );
		} catch ( Exception $e ) {
			return null;
		}
		$data = false === $plain ? null : json_decode( (string) $plain, true );
		return is_array( $data ) && isset( $data['kind'] ) ? $data : null;
	}
}
