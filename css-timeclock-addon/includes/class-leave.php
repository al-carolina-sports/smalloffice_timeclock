<?php
/**
 * Paid time off and sick time: yearly allowances from the hire-date
 * anniversary, requests, approvals and balances.
 *
 * Policy (see plans/pto-sick-leave.md):
 *  - The whole year's hours are available on each hire anniversary.
 *  - Use it or lose it: nothing carries over.
 *  - Year one has its own amount (default 3 days PTO), usable once the
 *    introductory period ends.
 *  - Optional one bank: sick time is taken from PTO.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leave records and balance maths.
 */
class Css_Tc_Leave {

	const POST_TYPE      = 'css_tc_leave';
	const META_ALLOW     = 'css_tc_leave_allow';
	const META_ADJUST    = 'css_tc_leave_adjust';
	const TYPES          = array( 'pto', 'sick' );
	const STATUSES       = array( 'pending', 'approved', 'denied', 'cancelled' );
	const EMPLOYEE_NONCE = 'css_tc_employee';
	const ADMIN_ACTION   = 'css_tc_leave';

	/**
	 * @var array<string,mixed>|null Settings override (tests).
	 */
	private $settings = null;

	/**
	 * @var string|null Today override (tests).
	 */
	private $today = null;

	/**
	 * @param array<string,mixed>|null $settings Settings override (tests).
	 * @param string|null              $today    Y-m-d override (tests).
	 */
	public function __construct( $settings = null, $today = null ) {
		$this->settings = $settings;
		$this->today    = $today;
	}

	/**
	 * Setting defaults, merged into the plugin's defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings() {
		return array(
			'pto_enabled'          => 0,
			'pto_hours_year'       => 40,
			'pto_first_year_hours' => 24,
			'sick_enabled'         => 0,
			'sick_hours_year'      => 24,
			'sick_first_year_hours' => '', // '' = same as the yearly amount.
			'sick_from_pto'        => 0,
			'pto_notice_days'      => 14,
			'leave_day_hours'      => 8,
			'leave_increment'      => 60, // Minutes.
			'leave_allow_negative' => 0,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function settings() {
		$base = null !== $this->settings ? $this->settings : css_tc_addon()->get_settings();
		return array_merge( self::default_settings(), is_array( $base ) ? $base : array() );
	}

	/**
	 * @return string Y-m-d in the site timezone.
	 */
	public function today() {
		if ( null !== $this->today ) {
			return $this->today;
		}
		return css_tc_addon()->time->site_today();
	}

	/*
	 * ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------
	 */

	/**
	 * @param string $type pto|sick.
	 * @return bool
	 */
	public function type_enabled( $type ) {
		$s = $this->settings();
		if ( 'pto' === $type ) {
			return ! empty( $s['pto_enabled'] );
		}
		if ( 'sick' === $type ) {
			return ! empty( $s['sick_enabled'] );
		}
		return false;
	}

	/**
	 * @return bool
	 */
	public function any_enabled() {
		return $this->type_enabled( 'pto' ) || $this->type_enabled( 'sick' );
	}

	/**
	 * Sick time is taken from the PTO balance.
	 *
	 * @return bool
	 */
	public function one_bank() {
		return ! empty( $this->settings()['sick_from_pto'] ) && $this->type_enabled( 'pto' );
	}

	/**
	 * The balance a type draws from.
	 *
	 * @param string $type pto|sick.
	 * @return string pto|sick.
	 */
	public function bank_for( $type ) {
		return ( 'sick' === $type && $this->one_bank() ) ? 'pto' : $type;
	}

	/**
	 * Balances to show: PTO, and sick unless it shares PTO's bank.
	 *
	 * @return string[]
	 */
	public function banks() {
		$out = array();
		if ( $this->type_enabled( 'pto' ) ) {
			$out[] = 'pto';
		}
		if ( $this->type_enabled( 'sick' ) && ! $this->one_bank() ) {
			$out[] = 'sick';
		}
		return $out;
	}

	/**
	 * @return int Seconds in a full day off.
	 */
	public function day_seconds() {
		$h = (float) $this->settings()['leave_day_hours'];
		return (int) round( max( 1, min( 24, $h ) ) * 3600 );
	}

	/**
	 * @return int Smallest request step in seconds.
	 */
	public function increment_seconds() {
		$m = (int) $this->settings()['leave_increment'];
		return in_array( $m, array( 15, 30, 60 ), true ) ? $m * 60 : 3600;
	}

	/**
	 * @return int
	 */
	public function notice_days() {
		return max( 0, min( 365, (int) $this->settings()['pto_notice_days'] ) );
	}

	/**
	 * First date an employee may request PTO for.
	 *
	 * @return string Y-m-d.
	 */
	public function notice_cutoff() {
		return self::add_days( $this->today(), $this->notice_days() );
	}

	/**
	 * @return bool
	 */
	public function allow_negative() {
		return ! empty( $this->settings()['leave_allow_negative'] );
	}

	/**
	 * @param string $type pto|sick.
	 * @return string
	 */
	public static function label( $type ) {
		return 'sick' === $type ? __( 'Sick', 'css-timeclock-addon' ) : __( 'PTO', 'css-timeclock-addon' );
	}

	/*
	 * ------------------------------------------------------------------
	 * Pure date and balance maths (tested in bin/check-leave.php)
	 * ------------------------------------------------------------------
	 */

	/**
	 * @param string $date Y-m-d.
	 * @param int    $days Days to add (may be negative).
	 * @return string
	 */
	public static function add_days( $date, $days ) {
		try {
			return ( new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) ) )->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			return $date;
		}
	}

	/**
	 * The hire anniversary n years after the hire date. Feb 29 hires have
	 * their anniversary on Feb 28 in other years.
	 *
	 * @param string $hire Y-m-d.
	 * @param int    $n    Years.
	 * @return string
	 */
	public static function anniversary( $hire, $n ) {
		$y = (int) substr( $hire, 0, 4 ) + (int) $n;
		$m = (int) substr( $hire, 5, 2 );
		$d = (int) substr( $hire, 8, 2 );
		if ( 2 === $m && 29 === $d && ! checkdate( 2, 29, $y ) ) {
			$d = 28;
		}
		return sprintf( '%04d-%02d-%02d', $y, $m, $d );
	}

	/**
	 * The leave year a date falls in.
	 *
	 * @param string $hire Y-m-d hire date.
	 * @param string $date Y-m-d.
	 * @return array{index:int,start:string,end:string}|null Null before the hire date.
	 */
	public static function cycle( $hire, $date ) {
		if ( '' === $hire || $date < $hire ) {
			return null;
		}
		$n = (int) substr( $date, 0, 4 ) - (int) substr( $hire, 0, 4 );
		if ( self::anniversary( $hire, $n ) > $date ) {
			--$n;
		}
		return array(
			'index' => $n,
			'start' => self::anniversary( $hire, $n ),
			'end'   => self::add_days( self::anniversary( $hire, $n + 1 ), -1 ),
		);
	}

	/**
	 * Allowance in seconds for a bank in a leave year.
	 *
	 * @param string              $bank      pto|sick.
	 * @param int                 $index     Leave year (0 = first year).
	 * @param array<string,mixed> $overrides Per-employee hours ('' = default).
	 * @return int
	 */
	public function allowance_seconds( $bank, $index, $overrides = array() ) {
		if ( ! $this->type_enabled( $bank ) ) {
			return 0;
		}
		if ( 'sick' === $bank && $this->one_bank() ) {
			return 0;
		}
		$s     = $this->settings();
		$year  = self::pick( $overrides, $bank . '_year', $s[ $bank . '_hours_year' ] );
		$first = self::pick( $overrides, $bank . '_first', $s[ $bank . '_first_year_hours' ] );
		if ( '' === $first || null === $first ) {
			$first = $year;
		}
		$hours = 0 === (int) $index ? (float) $first : (float) $year;
		return (int) round( max( 0, $hours ) * 3600 );
	}

	/**
	 * @param array<string,mixed> $overrides Per-employee values.
	 * @param string              $key       Key.
	 * @param mixed               $fallback  Default.
	 * @return mixed
	 */
	private static function pick( $overrides, $key, $fallback ) {
		if ( isset( $overrides[ $key ] ) && '' !== $overrides[ $key ] && null !== $overrides[ $key ] ) {
			return $overrides[ $key ];
		}
		return $fallback;
	}

	/**
	 * Balance for one bank in one leave year from raw records.
	 *
	 * @param int                               $allowance   Seconds.
	 * @param array<int,array<string,mixed>>    $records     Rows with date, type, seconds, status.
	 * @param array<int,array<string,mixed>>    $adjustments Rows with bank, seconds, cycle_start.
	 * @param array{start:string,end:string}    $cycle       Leave year.
	 * @param string                            $bank        pto|sick.
	 * @return array{allowance:int,adjust:int,used:int,pending:int,left:int}
	 */
	public function tally( $allowance, $records, $adjustments, $cycle, $bank ) {
		$used    = 0;
		$pending = 0;
		foreach ( $records as $row ) {
			if ( $row['date'] < $cycle['start'] || $row['date'] > $cycle['end'] || $this->bank_for( (string) $row['type'] ) !== $bank ) {
				continue;
			}
			if ( 'approved' === $row['status'] ) {
				$used += (int) $row['seconds'];
			} elseif ( 'pending' === $row['status'] ) {
				$pending += (int) $row['seconds'];
			}
		}
		$adjust = 0;
		foreach ( $adjustments as $a ) {
			if ( ( $a['bank'] ?? '' ) === $bank && ( $a['cycle_start'] ?? '' ) === $cycle['start'] ) {
				$adjust += (int) $a['seconds'];
			}
		}
		return array(
			'allowance' => (int) $allowance,
			'adjust'    => $adjust,
			'used'      => $used,
			'pending'   => $pending,
			'left'      => (int) $allowance + $adjust - $used - $pending,
		);
	}

	/**
	 * Check a new request against the rules and the balance.
	 *
	 * @param array<string,mixed>            $ctx     hire, usable_from, overrides, records, adjustments, holidays (date=>true), period_start (open period).
	 * @param array<string,int>              $days    Date => seconds.
	 * @param string                         $type    pto|sick.
	 * @param bool                           $manager Entered by a manager (no notice rule, any date).
	 * @return true|WP_Error
	 */
	public function check_request( $ctx, $days, $type, $manager ) {
		if ( ! in_array( $type, self::TYPES, true ) || ! $this->type_enabled( $type ) ) {
			return new WP_Error( 'css_tc_leave_type', __( 'That kind of time off is not turned on.', 'css-timeclock-addon' ) );
		}
		if ( empty( $days ) ) {
			return new WP_Error( 'css_tc_leave_days', __( 'Choose at least one day.', 'css-timeclock-addon' ) );
		}
		if ( count( $days ) > 60 ) {
			return new WP_Error( 'css_tc_leave_days', __( 'Choose 60 days or fewer at a time.', 'css-timeclock-addon' ) );
		}
		if ( '' === (string) $ctx['hire'] ) {
			return new WP_Error( 'css_tc_leave_hire', __( 'A hire date is needed before time off can be used. Ask a manager to set it.', 'css-timeclock-addon' ) );
		}
		$step = $this->increment_seconds();
		$taken = array();
		foreach ( $ctx['records'] as $row ) {
			if ( in_array( $row['status'], array( 'pending', 'approved' ), true ) ) {
				$taken[ $row['date'] ] = true;
			}
		}
		$cutoff = $this->notice_cutoff();
		foreach ( $days as $date => $seconds ) {
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return new WP_Error( 'css_tc_leave_date', __( 'One of the dates could not be read.', 'css-timeclock-addon' ) );
			}
			$nice = css_tc_leave_date_label( $date );
			if ( $seconds < $step || $seconds > 24 * 3600 || 0 !== $seconds % $step ) {
				/* translators: %s: date */
				return new WP_Error( 'css_tc_leave_hours', sprintf( __( 'Hours for %s must be in steps of the smallest amount allowed.', 'css-timeclock-addon' ), $nice ) );
			}
			if ( $date < $ctx['hire'] ) {
				/* translators: %s: date */
				return new WP_Error( 'css_tc_leave_before', sprintf( __( '%s is before the hire date.', 'css-timeclock-addon' ), $nice ) );
			}
			if ( '' !== (string) $ctx['usable_from'] && $date < $ctx['usable_from'] ) {
				/* translators: %s: date time off can be used from */
				return new WP_Error( 'css_tc_leave_intro', sprintf( __( 'Time off can be used from %s, after the introductory period.', 'css-timeclock-addon' ), css_tc_leave_date_label( $ctx['usable_from'] ) ) );
			}
			if ( isset( $taken[ $date ] ) ) {
				/* translators: %s: date */
				return new WP_Error( 'css_tc_leave_overlap', sprintf( __( 'There is already time off on %s.', 'css-timeclock-addon' ), $nice ) );
			}
			if ( isset( $ctx['holidays'][ $date ] ) ) {
				/* translators: %s: date */
				return new WP_Error( 'css_tc_leave_holiday', sprintf( __( '%s is a paid holiday, so no time off is needed.', 'css-timeclock-addon' ), $nice ) );
			}
			if ( ! $manager ) {
				if ( 'pto' === $type && $date < $cutoff ) {
					return new WP_Error(
						'css_tc_leave_notice',
						/* translators: 1: days, 2: earliest date */
						sprintf( __( 'PTO has to be requested %1$d days ahead. The earliest day you can ask for is %2$s.', 'css-timeclock-addon' ), $this->notice_days(), css_tc_leave_date_label( $cutoff ) )
					);
				}
				if ( '' !== (string) $ctx['period_start'] && $date < $ctx['period_start'] ) {
					/* translators: %s: date */
					return new WP_Error( 'css_tc_leave_closed', sprintf( __( '%s is in a closed pay period. Ask a manager.', 'css-timeclock-addon' ), $nice ) );
				}
			}
		}

		// Balance per leave year the days fall in.
		if ( ! $this->allow_negative() ) {
			$bank   = $this->bank_for( $type );
			$years  = array();
			foreach ( $days as $date => $seconds ) {
				$c = self::cycle( $ctx['hire'], $date );
				$years[ $c['start'] ]['cycle'] = $c;
				$years[ $c['start'] ]['ask']   = ( $years[ $c['start'] ]['ask'] ?? 0 ) + $seconds;
			}
			foreach ( $years as $y ) {
				$t = $this->tally( $this->allowance_seconds( $bank, $y['cycle']['index'], $ctx['overrides'] ), $ctx['records'], $ctx['adjustments'], $y['cycle'], $bank );
				if ( $y['ask'] > $t['left'] ) {
					return new WP_Error(
						'css_tc_leave_balance',
						sprintf(
							/* translators: 1: kind of balance, 2: hours asked, 3: hours left, 4: leave year */
							__( 'Not enough %1$s: this asks for %2$s but %3$s is left for %4$s (counting pending requests).', 'css-timeclock-addon' ),
							'pto' === $bank ? __( 'PTO', 'css-timeclock-addon' ) : __( 'sick time', 'css-timeclock-addon' ),
							self::hours( $y['ask'] ),
							self::hours( max( 0, $t['left'] ) ),
							css_tc_leave_date_label( $y['cycle']['start'] ) . ' – ' . css_tc_leave_date_label( $y['cycle']['end'] )
						)
					);
				}
			}
		}
		return true;
	}

	/**
	 * "8 h" / "7.5 h".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function hours( $seconds ) {
		$h = round( $seconds / 3600, 2 );
		return rtrim( rtrim( number_format( $h, 2, '.', '' ), '0' ), '.' ) . ' h';
	}

	/*
	 * ------------------------------------------------------------------
	 * Records (WordPress)
	 * ------------------------------------------------------------------
	 */

	/**
	 * @return void
	 */
	public function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Time off', 'css-timeclock-addon' ),
					'singular_name' => __( 'Time off', 'css-timeclock-addon' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'author' ),
				'capability_type'     => 'post',
			)
		);
	}

	/**
	 * @param WP_Post $post Leave post.
	 * @return array<string,mixed>
	 */
	public function row( $post ) {
		$get = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, 'css_tc_leave_' . $key, true );
		};
		return array(
			'id'           => (int) $post->ID,
			'user_id'      => (int) $post->post_author,
			'date'         => (string) $get( 'date' ),
			'type'         => (string) $get( 'type' ),
			'seconds'      => (int) $get( 'seconds' ),
			'status'       => (string) $get( 'status' ),
			'group'        => (string) $get( 'group' ),
			'source'       => (string) $get( 'source' ),
			'note'         => (string) $get( 'note' ),
			'requested_by' => (int) $get( 'requested_by' ),
			'requested_at' => (int) $get( 'requested_at' ),
			'decided_by'   => (int) $get( 'decided_by' ),
			'decided_at'   => (int) $get( 'decided_at' ),
			'reason'       => (string) $get( 'reason' ),
			'agreed'       => (bool) $get( 'agreed' ),
		);
	}

	/**
	 * Leave rows, optionally for one employee, a date range and statuses.
	 *
	 * @param int      $user_id  0 = everyone.
	 * @param string   $from     Y-m-d or ''.
	 * @param string   $to       Y-m-d or ''.
	 * @param string[] $statuses Statuses ([] = all).
	 * @return array<int,array<string,mixed>>
	 */
	public function records( $user_id = 0, $from = '', $to = '', $statuses = array() ) {
		$meta = array( 'relation' => 'AND' );
		if ( '' !== $from && '' !== $to ) {
			$meta[] = array( 'key' => 'css_tc_leave_date', 'value' => array( $from, $to ), 'compare' => 'BETWEEN', 'type' => 'CHAR' );
		} elseif ( '' !== $from ) {
			$meta[] = array( 'key' => 'css_tc_leave_date', 'value' => $from, 'compare' => '>=', 'type' => 'CHAR' );
		}
		if ( ! empty( $statuses ) ) {
			$meta[] = array( 'key' => 'css_tc_leave_status', 'value' => array_values( $statuses ), 'compare' => 'IN' );
		}
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 2000,
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		if ( $user_id > 0 ) {
			$args['author'] = (int) $user_id;
		}
		$rows = array();
		foreach ( get_posts( $args ) as $post ) {
			$rows[] = $this->row( $post );
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] ) ?: ( $a['id'] - $b['id'] );
			}
		);
		return $rows;
	}

	/**
	 * @param int $user_id Employee.
	 * @return array<string,string>
	 */
	public function overrides( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_ALLOW, true );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param int $user_id Employee.
	 * @return array<int,array<string,mixed>>
	 */
	public function adjustments( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_ADJUST, true );
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	/**
	 * Context for check_request() and balances.
	 *
	 * @param int $user_id Employee.
	 * @return array<string,mixed>
	 */
	public function context( $user_id ) {
		$hol    = css_tc_addon()->holidays;
		$hire   = $hol->hire_date( $user_id );
		$period = css_tc_addon()->pay_periods->current_period();
		$from   = '' !== $hire ? $hire : $this->today();
		$hdays  = array();
		foreach ( $hol->for_employee( $user_id, $this->today() > $from ? self::add_days( $this->today(), -60 ) : $from, self::add_days( $this->today(), 400 ) ) as $d => $info ) {
			if ( $info['eligible'] ) {
				$hdays[ $d ] = true;
			}
		}
		return array(
			'hire'         => $hire,
			'usable_from'  => $hol->eligible_from( $user_id ),
			'overrides'    => $this->overrides( $user_id ),
			'records'      => '' !== $hire ? $this->records( $user_id, $hire, '' ) : array(),
			'adjustments'  => $this->adjustments( $user_id ),
			'holidays'     => $hdays,
			'period_start' => $period ? (string) $period['start'] : '',
		);
	}

	/**
	 * Balances for the leave year that contains a date (today by default).
	 *
	 * @param int                      $user_id Employee.
	 * @param string                   $date    Y-m-d or ''.
	 * @param array<string,mixed>|null $ctx     Pre-built context.
	 * @return array<string,mixed> cycle, usable_from, banks => tally.
	 */
	public function balances( $user_id, $date = '', $ctx = null ) {
		$ctx   = $ctx ? $ctx : $this->context( $user_id );
		$date  = '' !== $date ? $date : $this->today();
		$cycle = self::cycle( $ctx['hire'], max( $date, (string) $ctx['hire'] ) );
		$out   = array(
			'hire'        => $ctx['hire'],
			'usable_from' => $ctx['usable_from'],
			'cycle'       => $cycle,
			'banks'       => array(),
		);
		if ( ! $cycle ) {
			return $out;
		}
		foreach ( $this->banks() as $bank ) {
			$out['banks'][ $bank ] = $this->tally( $this->allowance_seconds( $bank, $cycle['index'], $ctx['overrides'] ), $ctx['records'], $ctx['adjustments'], $cycle, $bank );
		}
		return $out;
	}

	/**
	 * Create a request (employee) or approved time off (manager).
	 *
	 * @param int               $user_id Employee.
	 * @param array<string,int> $days    Date => seconds.
	 * @param string            $type    pto|sick.
	 * @param string            $note    Note.
	 * @param int               $actor   User entering it.
	 * @param bool              $manager Manager entry (approved, needs agreement).
	 * @param bool              $agreed  Employee agreed (manager entry).
	 * @return string|WP_Error Group key.
	 */
	public function create( $user_id, $days, $type, $note, $actor, $manager = false, $agreed = false ) {
		$note = sanitize_textarea_field( (string) $note );
		if ( $manager && ( ! $agreed || '' === trim( $note ) ) ) {
			return new WP_Error( 'css_tc_leave_agreed', __( 'Tick "Employee agreed" and add a note saying how they agreed.', 'css-timeclock-addon' ) );
		}
		if ( strlen( $note ) > 500 ) {
			return new WP_Error( 'css_tc_leave_note', __( 'Keep the note under 500 characters.', 'css-timeclock-addon' ) );
		}
		ksort( $days );
		$check = $this->check_request( $this->context( $user_id ), $days, $type, $manager );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$group = wp_generate_password( 12, false );
		$now   = time();
		foreach ( $days as $date => $seconds ) {
			$id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'private',
					'post_author' => (int) $user_id,
					'post_title'  => sprintf( '%s %s %s', self::label( $type ), $date, css_tc_addon()->employees->display_name( $user_id ) ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$meta = array(
				'date'         => $date,
				'type'         => $type,
				'seconds'      => (int) $seconds,
				'status'       => $manager ? 'approved' : 'pending',
				'group'        => $group,
				'source'       => $manager ? 'manager' : 'employee',
				'note'         => $note,
				'requested_by' => (int) $actor,
				'requested_at' => $now,
				'agreed'       => $manager && $agreed ? 1 : 0,
			);
			if ( $manager ) {
				$meta['decided_by'] = (int) $actor;
				$meta['decided_at'] = $now;
			}
			foreach ( $meta as $k => $v ) {
				update_post_meta( (int) $id, 'css_tc_leave_' . $k, $v );
			}
		}
		return $group;
	}

	/**
	 * Rows in a request group.
	 *
	 * @param string $group Group key.
	 * @return array<int,array<string,mixed>>
	 */
	public function group_rows( $group ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'meta_key'       => 'css_tc_leave_group', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => sanitize_key( $group ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$rows = array();
		foreach ( $posts as $post ) {
			$rows[] = $this->row( $post );
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		return $rows;
	}

	/**
	 * Approve, deny or cancel a group.
	 *
	 * @param string $group  Group key.
	 * @param string $status approved|denied|cancelled.
	 * @param int    $actor  Who.
	 * @param string $reason Reason (required to deny).
	 * @return true|WP_Error
	 */
	public function decide( $group, $status, $actor, $reason = '' ) {
		$rows = $this->group_rows( $group );
		if ( empty( $rows ) ) {
			return new WP_Error( 'css_tc_leave_missing', __( 'That request was not found.', 'css-timeclock-addon' ) );
		}
		$reason = sanitize_textarea_field( (string) $reason );
		if ( 'denied' === $status && '' === trim( $reason ) ) {
			return new WP_Error( 'css_tc_leave_reason', __( 'Give a reason for denying the request.', 'css-timeclock-addon' ) );
		}
		if ( 'approved' === $status ) {
			foreach ( $rows as $r ) {
				if ( 'pending' !== $r['status'] ) {
					return new WP_Error( 'css_tc_leave_state', __( 'Only pending requests can be approved.', 'css-timeclock-addon' ) );
				}
			}
		}
		foreach ( $rows as $r ) {
			if ( in_array( $r['status'], array( 'denied', 'cancelled' ), true ) ) {
				continue;
			}
			update_post_meta( $r['id'], 'css_tc_leave_status', $status );
			update_post_meta( $r['id'], 'css_tc_leave_decided_by', (int) $actor );
			update_post_meta( $r['id'], 'css_tc_leave_decided_at', time() );
			if ( '' !== $reason ) {
				update_post_meta( $r['id'], 'css_tc_leave_reason', $reason );
			}
		}
		return true;
	}

	/**
	 * Employee cancels their own request: pending, or approved days still
	 * in the future.
	 *
	 * @param string $group   Group key.
	 * @param int    $user_id Employee.
	 * @return true|WP_Error
	 */
	public function cancel_own( $group, $user_id ) {
		$rows = $this->group_rows( $group );
		if ( empty( $rows ) || (int) $rows[0]['user_id'] !== (int) $user_id ) {
			return new WP_Error( 'css_tc_leave_missing', __( 'That request was not found.', 'css-timeclock-addon' ) );
		}
		$today = $this->today();
		$done  = 0;
		foreach ( $rows as $r ) {
			$ok = 'pending' === $r['status'] || ( 'approved' === $r['status'] && $r['date'] > $today );
			if ( ! $ok ) {
				continue;
			}
			update_post_meta( $r['id'], 'css_tc_leave_status', 'cancelled' );
			update_post_meta( $r['id'], 'css_tc_leave_decided_by', (int) $user_id );
			update_post_meta( $r['id'], 'css_tc_leave_decided_at', time() );
			++$done;
		}
		if ( 0 === $done ) {
			return new WP_Error( 'css_tc_leave_past', __( 'Time off that has already started can only be changed by a manager.', 'css-timeclock-addon' ) );
		}
		return true;
	}

	/**
	 * Add or remove hours from a balance for the current leave year.
	 *
	 * @param int    $user_id Employee.
	 * @param string $bank    pto|sick.
	 * @param int    $seconds Signed seconds.
	 * @param string $reason  Reason (required).
	 * @param int    $actor   Who.
	 * @return true|WP_Error
	 */
	public function adjust( $user_id, $bank, $seconds, $reason, $actor ) {
		$reason = sanitize_text_field( (string) $reason );
		if ( 0 === (int) $seconds ) {
			return true;
		}
		if ( '' === $reason ) {
			return new WP_Error( 'css_tc_leave_adjust', __( 'Give a reason for the balance adjustment.', 'css-timeclock-addon' ) );
		}
		if ( ! in_array( $bank, $this->banks(), true ) ) {
			return new WP_Error( 'css_tc_leave_adjust', __( 'That balance is not in use.', 'css-timeclock-addon' ) );
		}
		$cycle = self::cycle( css_tc_addon()->holidays->hire_date( $user_id ), $this->today() );
		if ( ! $cycle ) {
			return new WP_Error( 'css_tc_leave_adjust', __( 'Set a hire date first.', 'css-timeclock-addon' ) );
		}
		$list   = $this->adjustments( $user_id );
		$list[] = array(
			'bank'        => $bank,
			'seconds'     => (int) $seconds,
			'reason'      => $reason,
			'by'          => (int) $actor,
			'at'          => time(),
			'cycle_start' => $cycle['start'],
		);
		update_user_meta( (int) $user_id, self::META_ADJUST, array_slice( $list, -200 ) );
		return true;
	}

	/**
	 * Approved (and pending) leave by date for a timecard.
	 *
	 * @param int    $user_id Employee.
	 * @param string $start   Y-m-d.
	 * @param string $end     Y-m-d.
	 * @return array<string,array<int,array<string,mixed>>> Date => rows.
	 */
	public function by_date( $user_id, $start, $end ) {
		$out = array();
		foreach ( $this->records( $user_id, $start, $end, array( 'pending', 'approved' ) ) as $row ) {
			$out[ $row['date'] ][] = $row;
		}
		return $out;
	}

	/**
	 * Pending request groups for the manager badge.
	 *
	 * @return int
	 */
	public function pending_count() {
		if ( ! $this->any_enabled() ) {
			return 0;
		}
		$groups = array();
		foreach ( $this->records( 0, '', '', array( 'pending' ) ) as $row ) {
			$groups[ $row['group'] ] = true;
		}
		return count( $groups );
	}

	/**
	 * Rows grouped by request, newest request first.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function group( $rows ) {
		$groups = array();
		foreach ( $rows as $r ) {
			$g = $r['group'];
			if ( ! isset( $groups[ $g ] ) ) {
				$groups[ $g ] = array(
					'group'        => $g,
					'user_id'      => $r['user_id'],
					'type'         => $r['type'],
					'status'       => $r['status'],
					'source'       => $r['source'],
					'note'         => $r['note'],
					'reason'       => $r['reason'],
					'agreed'       => $r['agreed'],
					'requested_by' => $r['requested_by'],
					'requested_at' => $r['requested_at'],
					'decided_by'   => $r['decided_by'],
					'decided_at'   => $r['decided_at'],
					'days'         => array(),
					'seconds'      => 0,
				);
			}
			$groups[ $g ]['days'][ $r['date'] ] = $r['seconds'];
			$groups[ $g ]['seconds']           += $r['seconds'];
			if ( 'pending' === $r['status'] ) {
				$groups[ $g ]['status'] = 'pending';
			}
		}
		$out = array_values( $groups );
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['requested_at'] - $a['requested_at'];
			}
		);
		return $out;
	}

	/**
	 * "Mon Oct 12 – Wed Oct 14" style range from a date list.
	 *
	 * @param string[] $dates Y-m-d list.
	 * @return string
	 */
	public static function describe_days( $dates ) {
		sort( $dates );
		if ( empty( $dates ) ) {
			return '';
		}
		$first = reset( $dates );
		$last  = end( $dates );
		if ( $first === $last ) {
			return css_tc_leave_date_label( $first );
		}
		$contiguous = true;
		for ( $i = 1; $i < count( $dates ); $i++ ) {
			if ( self::add_days( $dates[ $i - 1 ], 1 ) !== $dates[ $i ] ) {
				$contiguous = false;
				break;
			}
		}
		if ( $contiguous ) {
			return css_tc_leave_date_label( $first ) . ' – ' . css_tc_leave_date_label( $last );
		}
		return implode( ', ', array_map( 'css_tc_leave_date_label', $dates ) );
	}
}

if ( ! function_exists( 'css_tc_leave_date_label' ) ) {
	/**
	 * Short date for messages ("Mon, Oct 12, 2026").
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	function css_tc_leave_date_label( $date ) {
		try {
			$d = new DateTimeImmutable( (string) $date, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return (string) $date;
		}
		return function_exists( 'wp_date' ) ? wp_date( 'D, M j, Y', $d->getTimestamp() + 43200, new DateTimeZone( 'UTC' ) ) : $d->format( 'D, M j, Y' );
	}
}
