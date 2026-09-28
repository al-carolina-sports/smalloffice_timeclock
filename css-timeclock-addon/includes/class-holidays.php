<?php
/**
 * Paid holidays: which days the office observes, how many hours each pays,
 * and who is eligible (hire date and introductory period).
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holiday calendar and holiday-pay eligibility.
 */
class Css_Tc_Holidays {

	const META_HIRE       = 'css_tc_hire_date';
	const META_INTRO_DAYS = 'css_tc_intro_days';

	/** Introductory period lengths offered on the profile. */
	const INTRO_CHOICES = array( 30, 60, 90 );

	/**
	 * Built-in holidays. 'rule' is either MM-DD (a fixed date) or a PHP
	 * relative date phrase with %d for the year. 'default' marks the ones
	 * checked on a new install.
	 *
	 * @return array<string,array{label:string,when:string,rule:string,fixed:bool,eve:bool,default:bool}>
	 */
	public static function catalog() {
		return array(
			'new_year'      => array( 'label' => __( "New Year's Day", 'css-timeclock-addon' ), 'when' => __( 'January 1', 'css-timeclock-addon' ), 'rule' => '01-01', 'fixed' => true, 'eve' => false, 'default' => true ),
			'mlk'           => array( 'label' => __( 'Martin Luther King Jr. Day', 'css-timeclock-addon' ), 'when' => __( 'Third Monday in January', 'css-timeclock-addon' ), 'rule' => 'third monday of january %d', 'fixed' => false, 'eve' => false, 'default' => false ),
			'presidents'    => array( 'label' => __( "Presidents' Day", 'css-timeclock-addon' ), 'when' => __( 'Third Monday in February', 'css-timeclock-addon' ), 'rule' => 'third monday of february %d', 'fixed' => false, 'eve' => false, 'default' => false ),
			'good_friday'   => array( 'label' => __( 'Good Friday', 'css-timeclock-addon' ), 'when' => __( 'Friday before Easter Sunday', 'css-timeclock-addon' ), 'rule' => 'easter-2', 'fixed' => false, 'eve' => false, 'default' => false ),
			'memorial'      => array( 'label' => __( 'Memorial Day', 'css-timeclock-addon' ), 'when' => __( 'Last Monday in May', 'css-timeclock-addon' ), 'rule' => 'last monday of may %d', 'fixed' => false, 'eve' => false, 'default' => true ),
			'juneteenth'    => array( 'label' => __( 'Juneteenth', 'css-timeclock-addon' ), 'when' => __( 'June 19', 'css-timeclock-addon' ), 'rule' => '06-19', 'fixed' => true, 'eve' => false, 'default' => false ),
			'independence'  => array( 'label' => __( 'Independence Day', 'css-timeclock-addon' ), 'when' => __( 'July 4', 'css-timeclock-addon' ), 'rule' => '07-04', 'fixed' => true, 'eve' => false, 'default' => true ),
			'labor'         => array( 'label' => __( 'Labor Day', 'css-timeclock-addon' ), 'when' => __( 'First Monday in September', 'css-timeclock-addon' ), 'rule' => 'first monday of september %d', 'fixed' => false, 'eve' => false, 'default' => true ),
			'columbus'      => array( 'label' => __( "Columbus Day / Indigenous Peoples' Day", 'css-timeclock-addon' ), 'when' => __( 'Second Monday in October', 'css-timeclock-addon' ), 'rule' => 'second monday of october %d', 'fixed' => false, 'eve' => false, 'default' => false ),
			'veterans'      => array( 'label' => __( 'Veterans Day', 'css-timeclock-addon' ), 'when' => __( 'November 11', 'css-timeclock-addon' ), 'rule' => '11-11', 'fixed' => true, 'eve' => false, 'default' => false ),
			'thanksgiving'  => array( 'label' => __( 'Thanksgiving Day', 'css-timeclock-addon' ), 'when' => __( 'Fourth Thursday in November', 'css-timeclock-addon' ), 'rule' => 'fourth thursday of november %d', 'fixed' => false, 'eve' => false, 'default' => true ),
			'thanksgiving2' => array( 'label' => __( 'Day after Thanksgiving', 'css-timeclock-addon' ), 'when' => __( 'Friday after Thanksgiving', 'css-timeclock-addon' ), 'rule' => 'fourth thursday of november %d +1 day', 'fixed' => false, 'eve' => false, 'default' => false ),
			'christmas_eve' => array( 'label' => __( 'Christmas Eve', 'css-timeclock-addon' ), 'when' => __( 'December 24', 'css-timeclock-addon' ), 'rule' => '12-24', 'fixed' => true, 'eve' => true, 'default' => false ),
			'christmas'     => array( 'label' => __( 'Christmas Day', 'css-timeclock-addon' ), 'when' => __( 'December 25', 'css-timeclock-addon' ), 'rule' => '12-25', 'fixed' => true, 'eve' => false, 'default' => true ),
			'new_years_eve' => array( 'label' => __( "New Year's Eve", 'css-timeclock-addon' ), 'when' => __( 'December 31', 'css-timeclock-addon' ), 'rule' => '12-31', 'fixed' => true, 'eve' => true, 'default' => false ),
		);
	}

	/**
	 * Keys checked on a new install.
	 *
	 * @return string[]
	 */
	public static function default_observed() {
		// Kept in sync with 'default' in catalog(); no translation calls so
		// settings can be read before init.
		return array( 'new_year', 'memorial', 'independence', 'labor', 'thanksgiving', 'christmas' );
	}

	const PROFILE_NONCE = 'css_tc_employment';

	/**
	 * Profile fields: hire date and introductory period.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'show_user_profile', array( $this, 'render_profile' ), 5 );
		add_action( 'edit_user_profile', array( $this, 'render_profile' ), 5 );
		add_action( 'user_profile_update_errors', array( $this, 'validate_profile' ), 10, 3 );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
		add_action( 'admin_post_css_tc_holiday_settings', array( $this, 'save_settings' ) );
	}

	/**
	 * TC-Config → Holidays form.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			wp_die( esc_html__( 'Only time clock managers can do that.', 'css-timeclock-addon' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'css_tc_holiday_settings' );
		$url  = Css_Tc_Admin::settings_url( 'holidays' );
		$back = static function ( $message, $error ) use ( $url ) {
			set_transient( 'css_tc_leave_notice_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), 60 );
			wp_safe_redirect( $url );
			exit;
		};
		$s     = css_tc_addon()->get_settings();
		$hours = isset( $_POST['holiday_hours'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['holiday_hours'] ) ) ) : '8';
		if ( ! is_numeric( $hours ) || (float) $hours < 0 || (float) $hours > 24 ) {
			$back( __( 'Hours paid per holiday must be between 0 and 24.', 'css-timeclock-addon' ), true );
		}
		$custom = isset( $_POST['holidays_custom'] ) ? sanitize_textarea_field( str_replace( array( "\r\n", "\r" ), "\n", (string) wp_unslash( $_POST['holidays_custom'] ) ) ) : '';
		if ( strlen( $custom ) > 3000 ) {
			$back( __( 'The other holidays list is too long.', 'css-timeclock-addon' ), true );
		}
		$parsed = self::parse_custom( $custom );
		if ( ! empty( $parsed['invalid'] ) ) {
			/* translators: %s: lines that could not be read */
			$back( sprintf( __( 'Other holidays: use MM-DD Name or YYYY-MM-DD Name. Could not read: %s', 'css-timeclock-addon' ), implode( ', ', array_slice( $parsed['invalid'], 0, 5 ) ) ), true );
		}
		$observed                   = isset( $_POST['holidays_observed'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['holidays_observed'] ) ) : array();
		$s['holidays_enabled']      = empty( $_POST['holidays_enabled'] ) ? 0 : 1;
		$s['holiday_hours']         = round( (float) $hours, 2 );
		$s['holidays_observed']     = array_values( array_intersect( array_keys( self::catalog() ), $observed ) );
		$s['holidays_custom']       = $custom;
		$s['holiday_weekend_shift'] = empty( $_POST['holiday_weekend_shift'] ) ? 0 : 1;
		css_tc_addon()->update_settings( $s );
		$back( __( 'Holidays saved.', 'css-timeclock-addon' ), false );
	}

	/**
	 * @param WP_User $user Profile user.
	 * @return void
	 */
	public function render_profile( $user ) {
		if ( ! Css_Tc_Plugin::user_can_manage() || ! css_tc_addon()->employees->is_employee( (int) $user->ID ) ) {
			return;
		}
		$hire     = $this->hire_date( (int) $user->ID );
		$intro    = $this->intro_days( (int) $user->ID );
		$from     = $this->eligible_from( (int) $user->ID );
		$holidays = $this->enabled();
		include CSS_TC_ADDON_DIR . 'admin/views/profile-employment.php';
	}

	/**
	 * Read and check the posted fields.
	 *
	 * @return array{hire:string,intro:int,error:string}
	 */
	private function posted() {
		$hire  = isset( $_POST['css_tc_hire_date'] ) ? sanitize_text_field( wp_unslash( $_POST['css_tc_hire_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$on    = ! empty( $_POST['css_tc_intro_on'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$days  = isset( $_POST['css_tc_intro_days'] ) ? absint( $_POST['css_tc_intro_days'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$error = '';
		if ( '' !== $hire && ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $hire, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) ) {
			$error = __( 'Hire date must be a valid date.', 'css-timeclock-addon' );
		}
		$intro = $on ? $days : 0;
		if ( $on && ! in_array( $days, self::INTRO_CHOICES, true ) ) {
			$error = __( 'Choose an introductory period of 30, 60 or 90 days.', 'css-timeclock-addon' );
		}
		if ( $on && '' === $hire ) {
			$error = __( 'An introductory period needs a hire date.', 'css-timeclock-addon' );
		}
		return array( 'hire' => $hire, 'intro' => $intro, 'error' => $error );
	}

	/**
	 * Stop the profile save with a message when the fields are wrong.
	 *
	 * @param WP_Error $errors Errors.
	 * @param bool     $update Updating an existing user.
	 * @param object   $user   User being saved.
	 * @return void
	 */
	public function validate_profile( $errors, $update, $user ) {
		unset( $update );
		if ( ! isset( $_POST['css_tc_employment_present'] ) || ! Css_Tc_Plugin::user_can_manage() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$posted = $this->posted();
		if ( '' !== $posted['error'] ) {
			$errors->add( 'css_tc_employment', $posted['error'] );
		}
	}

	/**
	 * @param int $user_id Profile user.
	 * @return void
	 */
	public function save_profile( $user_id ) {
		if ( ! Css_Tc_Plugin::user_can_manage() || ! isset( $_POST['css_tc_employment_present'] ) ) {
			return;
		}
		check_admin_referer( self::PROFILE_NONCE, 'css_tc_employment_nonce' );
		$posted = $this->posted();
		if ( '' !== $posted['error'] ) {
			return; // validate_profile already stopped the save with a message.
		}
		if ( '' === $posted['hire'] ) {
			delete_user_meta( (int) $user_id, self::META_HIRE );
		} else {
			update_user_meta( (int) $user_id, self::META_HIRE, $posted['hire'] );
		}
		if ( $posted['intro'] > 0 ) {
			update_user_meta( (int) $user_id, self::META_INTRO_DAYS, $posted['intro'] );
		} else {
			delete_user_meta( (int) $user_id, self::META_INTRO_DAYS );
		}
	}

	/**
	 * @var array<string,mixed>|null Settings override (tests).
	 */
	private $settings = null;

	/**
	 * @param array<string,mixed>|null $settings Use these settings instead of the saved ones (tests).
	 */
	public function __construct( $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function settings() {
		if ( null !== $this->settings ) {
			return $this->settings;
		}
		return css_tc_addon()->get_settings();
	}

	/**
	 * @return bool
	 */
	public function enabled() {
		return ! empty( $this->settings()['holidays_enabled'] );
	}

	/**
	 * Paid hours per holiday, in seconds.
	 *
	 * @return int
	 */
	public function seconds_per_holiday() {
		$hours = isset( $this->settings()['holiday_hours'] ) ? (float) $this->settings()['holiday_hours'] : 8.0;
		return (int) round( max( 0, min( 24, $hours ) ) * 3600 );
	}

	/**
	 * @return string[]
	 */
	public function observed_keys() {
		$keys = isset( $this->settings()['holidays_observed'] ) ? (array) $this->settings()['holidays_observed'] : self::default_observed();
		return array_values( array_intersect( array_keys( self::catalog() ), array_map( 'strval', $keys ) ) );
	}

	/**
	 * Plain-language rule for a custom line ("Every year on Dec 24").
	 *
	 * @param array{md:string,date:string,name:string} $row Parsed line.
	 * @return string
	 */
	public static function describe_custom( $row ) {
		if ( '' !== $row['md'] ) {
			$d = DateTimeImmutable::createFromFormat( '!Y-m-d', '2024-' . $row['md'], new DateTimeZone( 'UTC' ) );
			/* translators: %s: month and day */
			return sprintf( __( 'Every year on %s', 'css-timeclock-addon' ), $d ? $d->format( 'F j' ) : $row['md'] );
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $row['date'], new DateTimeZone( 'UTC' ) );
		/* translators: %s: date */
		return sprintf( __( 'One day only: %s', 'css-timeclock-addon' ), $d ? $d->format( 'F j, Y' ) : $row['date'] );
	}

	/**
	 * Parse "Custom holidays" lines: "MM-DD Name" (every year) or
	 * "YYYY-MM-DD Name" (one date). # starts a comment.
	 *
	 * @param string $raw Textarea.
	 * @return array{rows: array<int,array{md:string,date:string,name:string}>, invalid: string[]}
	 */
	public static function parse_custom( $raw ) {
		$rows    = array();
		$invalid = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})\s*(.*)$/', $line, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				$rows[] = array( 'md' => '', 'date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'name' => '' !== trim( $m[4] ) ? trim( $m[4] ) : __( 'Holiday', 'css-timeclock-addon' ) );
				continue;
			}
			if ( preg_match( '/^(\d{2})-(\d{2})\s*(.*)$/', $line, $m ) && checkdate( (int) $m[1], (int) $m[2], 2024 ) ) {
				$rows[] = array( 'md' => "{$m[1]}-{$m[2]}", 'date' => '', 'name' => '' !== trim( $m[3] ) ? trim( $m[3] ) : __( 'Holiday', 'css-timeclock-addon' ) );
				continue;
			}
			$invalid[] = $line;
		}
		return array( 'rows' => $rows, 'invalid' => $invalid );
	}

	/**
	 * Date arithmetic in UTC so the server timezone never moves a day.
	 *
	 * @param string $expr Anything DateTimeImmutable understands.
	 * @return string Y-m-d or ''.
	 */
	private static function ymd( $expr ) {
		try {
			return ( new DateTimeImmutable( $expr, new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Date a holiday falls on in a year, before any weekend shift.
	 *
	 * @param string $rule Catalog rule.
	 * @param int    $year Year.
	 * @return string Y-m-d or ''.
	 */
	public static function actual_date( $rule, $year ) {
		$year = (int) $year;
		if ( preg_match( '/^\d{2}-\d{2}$/', $rule ) ) {
			return sprintf( '%04d-%s', $year, $rule );
		}
		if ( 'easter-2' === $rule ) {
			return self::ymd( self::easter( $year ) . ' -2 days' );
		}
		return self::ymd( sprintf( $rule, $year ) );
	}

	/**
	 * Western Easter Sunday (anonymous Gregorian algorithm).
	 *
	 * @param int $year Year.
	 * @return string Y-m-d.
	 */
	private static function easter( $year ) {
		$a = $year % 19;
		$b = intdiv( $year, 100 );
		$c = $year % 100;
		$d = intdiv( $b, 4 );
		$e = $b % 4;
		$f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 );
		$k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;
		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Weekday a weekend holiday is paid on: Saturday → Friday, Sunday → Monday.
	 * Christmas Eve and New Year's Eve move back to Friday either way so they
	 * never land on the holiday after them.
	 *
	 * @param string $date Y-m-d.
	 * @param bool   $eve  Eve-type holiday.
	 * @return string
	 */
	public static function weekend_shift( $date, $eve = false ) {
		$dow = (int) ( new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) ) )->format( 'N' );
		if ( 6 === $dow ) {
			return self::ymd( $date . ' -1 day' );
		}
		if ( 7 === $dow ) {
			return self::ymd( $date . ( $eve ? ' -2 days' : ' +1 day' ) );
		}
		return $date;
	}

	/**
	 * Observed holidays whose paid date is between two dates (inclusive).
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array<string,string[]> Paid date => holiday names.
	 */
	public function between( $start, $end ) {
		if ( ! $this->enabled() ) {
			return array();
		}
		$shift   = ! isset( $this->settings()['holiday_weekend_shift'] ) || ! empty( $this->settings()['holiday_weekend_shift'] );
		$catalog = self::catalog();
		$out     = array();
		$add     = static function ( $date, $name ) use ( &$out, $start, $end ) {
			if ( '' === $date || $date < $start || $date > $end ) {
				return;
			}
			if ( ! isset( $out[ $date ] ) || ! in_array( $name, $out[ $date ], true ) ) {
				$out[ $date ][] = $name;
			}
		};
		// A holiday on Jan 1 can be paid on Dec 31 of the year before.
		for ( $year = (int) substr( $start, 0, 4 ) - 1; $year <= (int) substr( $end, 0, 4 ) + 1; $year++ ) {
			foreach ( $this->observed_keys() as $key ) {
				$def  = $catalog[ $key ];
				$date = self::actual_date( $def['rule'], $year );
				if ( $shift && $def['fixed'] && '' !== $date ) {
					$date = self::weekend_shift( $date, $def['eve'] );
				}
				$add( $date, $def['label'] );
			}
			foreach ( self::parse_custom( (string) ( $this->settings()['holidays_custom'] ?? '' ) )['rows'] as $row ) {
				if ( '' !== $row['md'] ) {
					$date = sprintf( '%04d-%s', $year, $row['md'] );
					$add( $shift ? self::weekend_shift( $date ) : $date, $row['name'] );
				} elseif ( (int) substr( $row['date'], 0, 4 ) === $year ) {
					$add( $row['date'], $row['name'] ); // A one-off date is paid as typed.
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param int $user_id Employee.
	 * @return string Y-m-d or ''.
	 */
	public function hire_date( $user_id ) {
		$raw = (string) get_user_meta( (int) $user_id, self::META_HIRE, true );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
	}

	/**
	 * @param int $user_id Employee.
	 * @return int Days (0 = no introductory period).
	 */
	public function intro_days( $user_id ) {
		$days = (int) get_user_meta( (int) $user_id, self::META_INTRO_DAYS, true );
		return in_array( $days, self::INTRO_CHOICES, true ) ? $days : 0;
	}

	/**
	 * First day holiday pay applies: the hire date, plus the introductory
	 * period when one is set. '' when there is no hire date (always eligible).
	 *
	 * @param string $hire  Y-m-d or ''.
	 * @param int    $intro Days.
	 * @return string
	 */
	public static function eligible_from_dates( $hire, $intro ) {
		if ( '' === $hire ) {
			return '';
		}
		return $intro > 0 ? self::ymd( $hire . ' +' . (int) $intro . ' days' ) : $hire;
	}

	/**
	 * @param int $user_id Employee.
	 * @return string Y-m-d or ''.
	 */
	public function eligible_from( $user_id ) {
		return self::eligible_from_dates( $this->hire_date( $user_id ), $this->intro_days( $user_id ) );
	}

	/**
	 * Holidays an employee is paid for between two dates.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @param string $from  First eligible day, or '' for always.
	 * @param array<string,string>|null $status Employment status record (no automatic holiday pay on leave or after the last day).
	 * Before the first eligible day the holiday is listed with 0 seconds so
	 * the timecard can say why it is unpaid.
	 *
	 * @return array<string,array{names:string[],seconds:int,eligible:bool}>
	 */
	public function for_range( $start, $end, $from, $status = null ) {
		$out = array();
		$sec = $this->seconds_per_holiday();
		foreach ( $this->between( $start, $end ) as $date => $names ) {
			$eligible = ( '' === $from || $date >= $from );
			$blocked  = '';
			if ( $eligible && is_array( $status ) ) {
				$on = Css_Tc_Status::status_on( $status, $date );
				if ( Css_Tc_Status::LEAVE === $on ) {
					$blocked = 'leave';
				} elseif ( Css_Tc_Status::INACTIVE === $on ) {
					$blocked = 'inactive';
				}
				$eligible = '' === $blocked;
			}
			$out[ $date ] = array(
				'names'    => $names,
				'seconds'  => $eligible ? $sec : 0, // One paid holiday per day.
				'eligible' => $eligible,
				'blocked'  => $blocked, // '' | leave | inactive (status); intro/hire otherwise.
			);
		}
		return $out;
	}

	/**
	 * @param int    $user_id Employee.
	 * @param string $start   Y-m-d.
	 * @param string $end     Y-m-d.
	 * @return array<string,array{names:string[],seconds:int,eligible:bool}>
	 */
	public function for_employee( $user_id, $start, $end ) {
		return $this->for_range( $start, $end, $this->eligible_from( $user_id ), css_tc_addon()->status->record( $user_id ) );
	}
}
