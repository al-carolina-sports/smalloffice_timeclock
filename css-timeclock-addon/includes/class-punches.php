<?php
/**
 * AIO Time Clock Lite–compatible punch writes.
 *
 * AIO Lite's AJAX action `aio_time_clock_lite_js` requires a logged-in WP user
 * (`wp_ajax_` only, plus get_current_user_id()). Shared kiosks cannot call it.
 * This class writes the same `shift` posts and meta AIO's Real Time Monitoring
 * already queries: open shifts are those with employee_clock_in_time set and
 * employee_clock_out_time empty.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clock in / clock out against AIO's shift data model.
 */
class Css_Tc_Punches {

	const POST_TYPE        = 'shift';
	const ROSTER_CACHE_KEY = 'css_tc_roster_public';
	const ROSTER_CACHE_TTL = 8; // Unused: public_board() skips the transient until a cache is proven necessary.

	/**
	 * True while an AIO clock write is being stored as UTC, so the filter
	 * does not convert that second write again.
	 *
	 * @var bool
	 */
	private $normalizing_clock_meta = false;

	/**
	 * Rewrite AIO's clock-in/clock-out AJAX times from site-local to UTC.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'add_post_metadata', array( $this, 'normalize_aio_clock_meta' ), 10, 5 );
		add_filter( 'update_post_metadata', array( $this, 'normalize_aio_clock_meta' ), 10, 5 );
	}

	/**
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return mixed
	 */
	public function normalize_aio_clock_meta( $check, $object_id, $meta_key, $meta_value ) {
		if ( null !== $check || $this->normalizing_clock_meta ) {
			return $check;
		}
		if ( ! in_array( (string) $meta_key, array( 'employee_clock_in_time', 'employee_clock_out_time' ), true ) ) {
			return $check;
		}
		if ( ! $this->request_is_aio_clock_punch() ) {
			return $check;
		}

		$post = get_post( (int) $object_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return $check;
		}

		$utc = css_tc_addon()->time->site_naive_to_utc( $meta_value );
		if ( null === $utc || $utc === trim( (string) $meta_value ) ) {
			return $check;
		}

		$this->normalizing_clock_meta = true;
		update_post_meta( (int) $object_id, (string) $meta_key, $utc );
		$this->normalizing_clock_meta = false;

		return true;
	}

	/**
	 * AIO Lite's logged-in clock widget (action aio_time_clock_lite_js).
	 *
	 * @return bool
	 */
	public function request_is_aio_clock_punch() {
		$doing_ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		$action     = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$clock      = isset( $_POST['clock_action'] ) ? sanitize_key( wp_unslash( $_POST['clock_action'] ) ) : '';
		$is         = $doing_ajax && 'aio_time_clock_lite_js' === $action && in_array( $clock, array( 'clock_in', 'clock_out' ), true );

		/**
		 * Whether the current request is AIO's clock-in or clock-out AJAX.
		 *
		 * @param bool $is Detected from the request.
		 */
		return (bool) apply_filters( 'css_tc_is_aio_clock_punch', $is );
	}

	/**
	 * @param int $user_id Employee user ID.
	 * @return array{open_shift_id:int,is_clocked_in:bool,clock_in_time:?string}
	 */
	public function open_shift_for( $user_id ) {
		$user_id = (int) $user_id;
		$query   = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'author'         => $user_id,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 25,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$result = array(
			'open_shift_id' => 0,
			'is_clocked_in' => false,
			'clock_in_time' => null,
		);

		if ( ! $query->have_posts() ) {
			return $result;
		}

		foreach ( $query->posts as $post ) {
			$clock_in  = get_post_meta( $post->ID, 'employee_clock_in_time', true );
			$clock_out = get_post_meta( $post->ID, 'employee_clock_out_time', true );
			if ( ! $this->is_open_shift_meta( $clock_in, $clock_out ) ) {
				continue;
			}
			// An open shift older than the configured maximum is a missed
			// clock-out, not a current clock-in. Leave the row open so a
			// correction can close it.
			if ( $this->is_stale_open_shift( $clock_in ) ) {
				continue;
			}
			$result['open_shift_id'] = (int) $post->ID;
			$result['is_clocked_in'] = true;
			$result['clock_in_time'] = $this->format_time( (string) $clock_in );
			break;
		}

		wp_reset_postdata();

		return $result;
	}

	/**
	 * Clock the employee in. Fails if they already have an open shift.
	 *
	 * @param int    $user_id Employee user ID.
	 * @param string $source  pin_kiosk|name_kiosk.
	 * @return array<string,mixed>|WP_Error
	 */
	public function clock_in( $user_id, $source = 'pin_kiosk' ) {
		$user_id = (int) $user_id;
		$open    = $this->open_shift_for( $user_id );

		if ( $open['is_clocked_in'] ) {
			return new WP_Error( 'css_tc_already_in', __( 'You are already clocked in.', 'css-timeclock-addon' ) );
		}

		$now = $this->current_mysql_time();

		$shift_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => 'Employee Shift',
				'post_status' => 'publish',
				'post_author' => $user_id,
			),
			true
		);

		if ( is_wp_error( $shift_id ) ) {
			return $shift_id;
		}

		update_post_meta( $shift_id, 'employee_clock_in_time', $now );
		// AIO Lite also writes null here. Prefer '' so new rows are a real empty
		// string. Existing SQL NULL rows must still count as open via PHP filter.
		update_post_meta( $shift_id, 'employee_clock_out_time', '' );

		$department = css_tc_addon()->employees->department( $user_id );
		if ( '' !== $department ) {
			add_post_meta( $shift_id, 'department', $department, true );
		}

		add_post_meta( $shift_id, 'ip_address_in', $this->client_ip(), true );
		add_post_meta( $shift_id, 'css_tc_kiosk_source', sanitize_key( $source ), true );

		/**
		 * Fires after a kiosk clock-in writes an AIO-compatible shift.
		 *
		 * @param int    $shift_id Shift post ID.
		 * @param int    $user_id  Employee user ID.
		 * @param string $source   Kiosk source.
		 */
		do_action( 'css_tc_after_clock_in', $shift_id, $user_id, $source );

		$this->bust_roster_cache();

		return array(
			'action'        => 'clock_in',
			'shift_id'      => (int) $shift_id,
			'is_clocked_in' => true,
			'clock_in_time' => $this->format_time( $now ),
			'time_total'    => '',
		);
	}

	/**
	 * Clock the employee out of their open shift.
	 *
	 * @param int    $user_id Employee user ID.
	 * @param string $source  pin_kiosk|name_kiosk.
	 * @return array<string,mixed>|WP_Error
	 */
	public function clock_out( $user_id, $source = 'pin_kiosk' ) {
		$user_id = (int) $user_id;
		$open    = $this->open_shift_for( $user_id );

		if ( ! $open['is_clocked_in'] || $open['open_shift_id'] < 1 ) {
			return new WP_Error( 'css_tc_not_in', __( 'You are not clocked in.', 'css-timeclock-addon' ) );
		}

		$shift_id = (int) $open['open_shift_id'];
		$now      = $this->current_mysql_time();
		$clock_in = get_post_meta( $shift_id, 'employee_clock_in_time', true );

		update_post_meta( $shift_id, 'employee_clock_out_time', $now );
		add_post_meta( $shift_id, 'ip_address_out', $this->client_ip(), true );
		add_post_meta( $shift_id, 'css_tc_kiosk_source_out', sanitize_key( $source ), true );

		/**
		 * Fires after a kiosk clock-out closes an AIO-compatible shift.
		 *
		 * @param int    $shift_id Shift post ID.
		 * @param int    $user_id  Employee user ID.
		 * @param string $source   Kiosk source.
		 */
		do_action( 'css_tc_after_clock_out', $shift_id, $user_id, $source );

		$this->bust_roster_cache();

		return array(
			'action'         => 'clock_out',
			'shift_id'       => $shift_id,
			'is_clocked_in'  => false,
			'clock_in_time'  => $this->format_time( (string) $clock_in ),
			'clock_out_time' => $this->format_time( $now ),
			'time_total'     => $this->elapsed_label( (string) $clock_in, $now ),
		);
	}

	/**
	 * Whether clock-in / clock-out meta describes an open shift.
	 *
	 * Same rule as AIO Real Time Monitoring (aio-monitoring.php) and
	 * open_shift_for(): clock-in is set and clock-out is null or ''. PHP
	 * empty() treats '', null, and missing get_post_meta values as empty.
	 * Do not replace this with a WP_Query empty-string meta_query — a row
	 * with SQL NULL exists, so NOT EXISTS fails and meta_value = '' fails.
	 *
	 * @param mixed $clock_in  employee_clock_in_time meta.
	 * @param mixed $clock_out employee_clock_out_time meta.
	 * @return bool
	 */
	public function is_open_shift_meta( $clock_in, $clock_out ) {
		return ( ! empty( $clock_in ) && ( empty( $clock_out ) || '' === $clock_out ) );
	}

	/**
	 * Hours after which an open shift is a missed clock-out, not "working now".
	 *
	 * @return int
	 */
	public function missed_clock_out_hours() {
		$settings = css_tc_addon()->get_settings();
		$hours    = isset( $settings['missed_clock_out_hours'] ) ? (int) $settings['missed_clock_out_hours'] : 16;
		return min( 36, max( 1, $hours ) );
	}

	/**
	 * Finished shifts longer than this are flagged. Hours still count.
	 *
	 * @return int
	 */
	public function long_shift_hours() {
		$settings = css_tc_addon()->get_settings();
		$hours    = isset( $settings['long_shift_hours'] ) ? (int) $settings['long_shift_hours'] : 16;
		return min( 36, max( 1, $hours ) );
	}

	/**
	 * @param mixed $clock_in Stored clock-in.
	 * @return bool
	 */
	public function is_stale_open_shift( $clock_in ) {
		return css_tc_addon()->time->is_stale_open( (string) $clock_in, $this->missed_clock_out_hours() );
	}

	/**
	 * Open shifts keyed by employee user ID (AIO: clock-in set, clock-out empty).
	 *
	 * Loads recent shift posts (no clock-out meta_query), then filters in PHP
	 * with is_open_shift_meta() — same approach as open_shift_for() and AIO.
	 *
	 * @return array<int,array{clock_in_time:string}>
	 */
	public function open_shifts_by_author() {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$map = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$author = (int) $post->post_author;
				if ( $author < 1 || isset( $map[ $author ] ) ) {
					continue;
				}

				$clock_in  = get_post_meta( $post->ID, 'employee_clock_in_time', true );
				$clock_out = get_post_meta( $post->ID, 'employee_clock_out_time', true );
				if ( ! $this->is_open_shift_meta( $clock_in, $clock_out ) ) {
					continue;
				}
				if ( $this->is_stale_open_shift( $clock_in ) ) {
					continue;
				}

				$map[ $author ] = array(
					'clock_in_time' => $this->format_board_time( (string) $clock_in ),
				);
			}
		}

		wp_reset_postdata();

		return $map;
	}

	/**
	 * Real Time Monitoring rows. Fresh open shifts are "working". Older open
	 * shifts are missed clock-outs and are not counted as working. Times are
	 * formatted in the site timezone.
	 *
	 * @return array{working:array<int,array<string,mixed>>,missed:array<int,array<string,mixed>>,max_hours:int,timezone:string}
	 */
	public function monitoring_snapshot() {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 300,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$working = array();
		$missed  = array();
		$fresh   = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$author = (int) $post->post_author;
				if ( $author < 1 || isset( $fresh[ $author ] ) ) {
					continue;
				}

				$clock_in  = get_post_meta( $post->ID, 'employee_clock_in_time', true );
				$clock_out = get_post_meta( $post->ID, 'employee_clock_out_time', true );
				if ( ! $this->is_open_shift_meta( $clock_in, $clock_out ) ) {
					continue;
				}

				$row = $this->monitoring_row( $post, (string) $clock_in );
				if ( $this->is_stale_open_shift( $clock_in ) ) {
					if ( ! isset( $missed[ $author ] ) ) {
						$missed[ $author ] = $row;
					}
					continue;
				}

				$working[ $author ] = $row;
				$fresh[ $author ]   = true;
				unset( $missed[ $author ] );
			}
		}

		wp_reset_postdata();

		$by_name = static function ( $a, $b ) {
			return strcasecmp( (string) $a['name'], (string) $b['name'] );
		};
		$working = array_values( $working );
		$missed  = array_values( $missed );
		usort( $working, $by_name );
		usort( $missed, $by_name );

		$tz = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : css_tc_addon()->time->timezone()->getName();

		return array(
			'working'   => $working,
			'missed'    => $missed,
			'max_hours' => $this->missed_clock_out_hours(),
			'timezone'  => $tz ? $tz : 'UTC',
		);
	}

	/**
	 * @param WP_Post $post     Open shift.
	 * @param string  $clock_in Stored UTC clock-in.
	 * @return array<string,mixed>
	 */
	private function monitoring_row( $post, $clock_in ) {
		$time      = css_tc_addon()->time;
		$author    = (int) $post->post_author;
		$ip        = (string) get_post_meta( $post->ID, 'ip_address_in', true );
		$started   = $time->parse_stored( $clock_in );
		$age       = $started ? max( 0, time() - $started->getTimestamp() ) : 0;
		$work_date = $time->site_date_of( $clock_in );

		return array(
			'shift_id'     => (int) $post->ID,
			'user_id'      => $author,
			'name'         => css_tc_addon()->employees->display_name( $author ),
			'department'   => css_tc_addon()->employees->department( $author ),
			'clock_in'     => $time->format_site( $clock_in, 'F j, Y, g:i A' ),
			'elapsed'      => $time->format_duration( $age ),
			'ip'           => $ip,
			'work_date'    => $work_date,
			'timecard_url' => $this->monitoring_timecard_url( $author, $work_date ),
		);
	}

	/**
	 * SMOTC timecard for this punch. The shift post type has no editor, so
	 * get_edit_post_link() is empty and the monitoring Shift cell was blank.
	 *
	 * @param int    $user_id   Employee.
	 * @param string $work_date Site-local Y-m-d of the clock-in.
	 * @return string
	 */
	private function monitoring_timecard_url( $user_id, $work_date ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return '';
		}

		$args = array(
			'employee' => $user_id,
		);
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $work_date ) ) {
			$period = css_tc_addon()->pay_periods->period_for_date( $work_date );
			if ( $period && ! empty( $period['start'] ) ) {
				$args['period'] = (string) $period['start'];
			}
		}

		$url = Css_Tc_Admin::timecards_url( $args );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $work_date ) ) {
			$url .= '#day-' . $work_date;
		}
		return $url;
	}

	/**
	 * Public kiosk board: display names + in/out (+ clock-in time). No IDs, emails, or PINs.
	 *
	 * @return array{working:array<int,array<string,string>>,out:array<int,array<string,string>>,working_count:int,out_count:int,generated_at:string}
	 */
	public function public_board() {
		// No get_transient / set_transient until a cache is proven necessary.
		// WP Engine object cache can keep a stale empty css_tc_roster_public
		// after delete_transient(), which made punch + roster disagree.

		$employees = css_tc_addon()->employees->list_for_board();
		$open      = $this->open_shifts_by_author();
		$seen      = array();

		foreach ( $employees as $emp ) {
			$seen[ (int) $emp['id'] ] = true;
		}

		foreach ( $open as $user_id => $_shift ) {
			if ( isset( $seen[ $user_id ] ) ) {
				continue;
			}
			if ( ! css_tc_addon()->employees->is_employee( $user_id ) ) {
				continue;
			}
			$employees[]        = array(
				'id'   => (int) $user_id,
				'name' => css_tc_addon()->employees->display_name( (int) $user_id ),
			);
			$seen[ $user_id ] = true;
		}

		usort(
			$employees,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		$working = array();
		$out     = array();

		foreach ( $employees as $emp ) {
			$id  = (int) $emp['id'];
			$row = array(
				'name' => (string) $emp['name'],
			);
			if ( isset( $open[ $id ] ) ) {
				$row['clock_in_time'] = $open[ $id ]['clock_in_time'];
				$working[]            = $row;
			} else {
				$out[] = $row;
			}
		}

		$payload = array(
			'working'       => $working,
			'out'           => $out,
			'working_count' => count( $working ),
			'out_count'     => count( $out ),
			'generated_at'  => $this->format_board_now(),
		);

		/**
		 * Filter the public kiosk status board payload (names and in/out only).
		 *
		 * @param array<string,mixed> $payload Board payload.
		 */
		$payload = apply_filters( 'css_tc_public_board', $payload );

		return $payload;
	}

	/**
	 * Drop any leftover css_tc_roster_public transient from older versions.
	 *
	 * @return void
	 */
	public function bust_roster_cache() {
		delete_transient( self::ROSTER_CACHE_KEY );
	}

	/**
	 * Clock-in time for the kiosk board: time only when it is today.
	 *
	 * @param string $mysql_datetime Datetime string.
	 * @return string
	 */
	public function format_board_time( $mysql_datetime ) {
		$time = css_tc_addon()->time;
		$day  = $time->site_date_of( $mysql_datetime );
		if ( '' === $day ) {
			return (string) $mysql_datetime;
		}

		$time_format = get_option( 'time_format', 'g:i a' );
		$date_format = get_option( 'date_format', 'Y-m-d' );
		$same_day    = ( $day === $time->site_today() );
		$format      = $same_day ? $time_format : ( $date_format . ' ' . $time_format );
		$label       = $time->format_site( $mysql_datetime, $format );
		return '' !== $label ? $label : (string) $mysql_datetime;
	}

	/**
	 * @return string
	 */
	private function format_board_now() {
		$format = get_option( 'time_format', 'g:i a' );
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( $format );
		}
		return date_i18n( $format );
	}

	/**
	 * Current instant as UTC Y-m-d H:i:s.
	 *
	 * Same digits as AIO Lite 2.1's wp_date() storage while the site timezone
	 * is UTC. See Css_Tc_Time for why this stays UTC after a timezone change.
	 *
	 * @return string
	 */
	public function current_mysql_time() {
		return css_tc_addon()->time->now_stored();
	}

	/**
	 * @param string $mysql_datetime Datetime string.
	 * @return string
	 */
	public function format_time( $mysql_datetime ) {
		$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'g:i a' );
		$label  = css_tc_addon()->time->format_site( $mysql_datetime, $format );
		return '' !== $label ? $label : (string) $mysql_datetime;
	}

	/**
	 * Recent calendar days for the employee dashboard, newest first.
	 *
	 * @param int $user_id Employee user ID.
	 * @param int $days    Inclusive lookback (today counts as day 1).
	 * @return array<int,array<string,mixed>>
	 */
	public function calendar_days_for_user( $user_id, $days = 21 ) {
		$user_id = (int) $user_id;
		$days    = min( 60, max( 7, (int) $days ) );
		$today   = $this->site_date();
		$start   = $this->shift_date( $today, 1 - $days );

		$shifts  = $this->shifts_since( $user_id, $start );
		$by_date = array();

		for ( $i = 0; $i < $days; $i++ ) {
			$date             = $this->shift_date( $today, -$i );
			$by_date[ $date ] = array();
		}

		foreach ( $shifts as $shift ) {
			$date = ! empty( $shift['work_date'] )
				? (string) $shift['work_date']
				: css_tc_addon()->time->site_date_of( (string) $shift['clock_in_raw'] );
			if ( ! isset( $by_date[ $date ] ) ) {
				continue;
			}
			$by_date[ $date ][] = $shift;
		}

		$result = array();
		foreach ( $by_date as $date => $list ) {
			$result[] = array(
				'date'       => $date,
				'date_label' => $this->format_day_label( $date ),
				'weekday'    => $this->format_weekday( $date ),
				'is_today'   => ( $date === $today ),
				'shifts'     => $list,
			);
		}

		return $result;
	}

	/**
	 * Shifts for one employee whose clock-in is on or after $start_date.
	 *
	 * @param int    $user_id    Employee user ID.
	 * @param string $start_date Y-m-d.
	 * @return array<int,array<string,mixed>>
	 */
	public function shifts_since( $user_id, $start_date ) {
		$user_id    = (int) $user_id;
		$start_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ? $start_date : $this->site_date();
		$query_from = css_tc_addon()->time->shift_date( $start_date, -2 );

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'author'         => $user_id,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => 'employee_clock_in_time',
						'value'   => $query_from . ' 00:00:00',
						'compare' => '>=',
					),
				),
			)
		);

		$shifts = array();
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$row = $this->shift_row( $post );
				if ( $row ) {
					$shifts[] = $row;
				}
			}
		}

		wp_reset_postdata();

		return $shifts;
	}

	/**
	 * @param WP_Post $post Shift post.
	 * @return array<string,mixed>|null
	 */
	public function shift_row( $post ) {
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$clock_in  = (string) get_post_meta( $post->ID, 'employee_clock_in_time', true );
		$clock_out = (string) get_post_meta( $post->ID, 'employee_clock_out_time', true );
		$time      = css_tc_addon()->time;
		$has_in    = ( null !== $time->parse_stored( $clock_in ) );
		$has_out   = ( null !== $time->parse_stored( $clock_out ) );

		if ( ! $has_in && ! $has_out ) {
			return null;
		}

		$is_open       = $has_in && ! $has_out;
		$is_missing_in = ( ! $has_in && $has_out );
		$is_stale      = $is_open && $this->is_stale_open_shift( $clock_in );
		$in_day        = $has_in ? $time->site_date_of( $clock_in ) : '';
		$out_day       = $has_out ? $time->site_date_of( $clock_out ) : '';
		$work_date     = '' !== $in_day ? $in_day : $out_day;
		$seconds       = ( $has_in && $has_out ) ? $time->elapsed_seconds( $clock_in, $clock_out ) : -1;
		$is_long       = ( $seconds > ( $this->long_shift_hours() * HOUR_IN_SECONDS ) );

		return array(
			'id'              => (int) $post->ID,
			'clock_in_raw'    => $has_in ? $clock_in : '',
			'clock_out_raw'   => $has_out ? $clock_out : '',
			'clock_in'        => $has_in ? $this->format_time( $clock_in ) : '',
			'clock_out'       => $has_out ? $this->format_time( $clock_out ) : '',
			'clock_in_hm'     => $has_in ? $time->site_hm( $clock_in ) : '',
			'clock_out_hm'    => $has_out ? $time->site_hm( $clock_out ) : '',
			'clock_in_hms'    => $has_in ? $time->site_hms( $clock_in ) : '',
			'clock_out_hms'   => $has_out ? $time->site_hms( $clock_out ) : '',
			'clock_in_clock'  => $has_in ? $time->format_clock( $clock_in ) : '',
			'clock_out_clock' => $has_out ? $time->format_clock( $clock_out ) : '',
			'work_date'       => $work_date,
			'out_next_day'    => ( $has_in && $has_out && $out_day !== $in_day ),
			'time_total'      => $seconds >= 0 ? $time->format_duration( $seconds ) : '',
			'seconds'         => $seconds >= 0 ? $seconds : 0,
			'is_open'         => $is_open,
			'is_stale_open'   => $is_stale,
			'is_missing_in'   => $is_missing_in,
			'is_long'         => $is_long,
		);
	}

	/**
	 * Apply approved times to an existing AIO-compatible shift. Stores first-original audit.
	 *
	 * @param int    $shift_id  Shift post ID.
	 * @param int    $user_id   Expected author.
	 * @param string $clock_in  Y-m-d H:i:s or empty to keep.
	 * @param string $clock_out Y-m-d H:i:s or empty to keep (or clear if $clear_out).
	 * @param bool   $clear_out Whether to empty clock-out.
	 * @param array<string,mixed> $audit Audit fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public function apply_times( $shift_id, $user_id, $clock_in, $clock_out, $clear_out = false, $audit = array() ) {
		$shift_id = (int) $shift_id;
		$user_id  = (int) $user_id;
		$post     = get_post( $shift_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type || (int) $post->post_author !== $user_id ) {
			return new WP_Error( 'css_tc_bad_shift', __( 'That shift could not be updated.', 'css-timeclock-addon' ) );
		}

		$old_in  = (string) get_post_meta( $shift_id, 'employee_clock_in_time', true );
		$old_out = (string) get_post_meta( $shift_id, 'employee_clock_out_time', true );

		if ( ! get_post_meta( $shift_id, 'css_tc_original_clock_in', true ) ) {
			update_post_meta( $shift_id, 'css_tc_original_clock_in', $old_in );
			update_post_meta( $shift_id, 'css_tc_original_clock_out', $old_out );
		}

		if ( '' !== $clock_in ) {
			update_post_meta( $shift_id, 'employee_clock_in_time', $clock_in );
		}
		if ( $clear_out ) {
			update_post_meta( $shift_id, 'employee_clock_out_time', '' );
		} elseif ( '' !== $clock_out ) {
			update_post_meta( $shift_id, 'employee_clock_out_time', $clock_out );
		}

		if ( ! empty( $audit['correction_id'] ) ) {
			update_post_meta( $shift_id, 'css_tc_last_correction_id', (int) $audit['correction_id'] );
		}
		if ( ! empty( $audit['suggested_by'] ) ) {
			update_post_meta( $shift_id, 'css_tc_corrected_by', (int) $audit['suggested_by'] );
		}
		if ( ! empty( $audit['approved_by'] ) ) {
			update_post_meta( $shift_id, 'css_tc_approved_by', (int) $audit['approved_by'] );
		}
		update_post_meta( $shift_id, 'css_tc_approved_at', $this->current_mysql_time() );

		$this->bust_roster_cache();

		$fresh = get_post( $shift_id );
		$row   = $this->shift_row( $fresh );
		return $row ? $row : new WP_Error( 'css_tc_bad_shift', __( 'That shift could not be updated.', 'css-timeclock-addon' ) );
	}

	/**
	 * Create a shift from an approved missing-punch suggestion.
	 *
	 * @param int    $user_id   Employee user ID.
	 * @param string $clock_in  Y-m-d H:i:s.
	 * @param string $clock_out Y-m-d H:i:s or empty.
	 * @param array<string,mixed> $audit Audit fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_corrected_shift( $user_id, $clock_in, $clock_out = '', $audit = array() ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || '' === $clock_in ) {
			return new WP_Error( 'css_tc_bad_shift', __( 'A clock-in time is required to add a shift.', 'css-timeclock-addon' ) );
		}

		$shift_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => 'Employee Shift',
				'post_status' => 'publish',
				'post_author' => $user_id,
			),
			true
		);

		if ( is_wp_error( $shift_id ) ) {
			return $shift_id;
		}

		update_post_meta( $shift_id, 'employee_clock_in_time', $clock_in );
		update_post_meta( $shift_id, 'employee_clock_out_time', '' === $clock_out ? '' : $clock_out );
		update_post_meta( $shift_id, 'css_tc_original_clock_in', '' );
		update_post_meta( $shift_id, 'css_tc_original_clock_out', '' );
		add_post_meta( $shift_id, 'css_tc_kiosk_source', 'correction', true );
		add_post_meta( $shift_id, 'css_tc_created_from_correction', '1', true );

		$department = css_tc_addon()->employees->department( $user_id );
		if ( '' !== $department ) {
			add_post_meta( $shift_id, 'department', $department, true );
		}

		if ( ! empty( $audit['correction_id'] ) ) {
			update_post_meta( $shift_id, 'css_tc_last_correction_id', (int) $audit['correction_id'] );
		}
		if ( ! empty( $audit['suggested_by'] ) ) {
			update_post_meta( $shift_id, 'css_tc_corrected_by', (int) $audit['suggested_by'] );
		}
		if ( ! empty( $audit['approved_by'] ) ) {
			update_post_meta( $shift_id, 'css_tc_approved_by', (int) $audit['approved_by'] );
		}
		update_post_meta( $shift_id, 'css_tc_approved_at', $this->current_mysql_time() );

		$this->bust_roster_cache();

		$row = $this->shift_row( get_post( $shift_id ) );
		return $row ? $row : new WP_Error( 'css_tc_bad_shift', __( 'That shift could not be created.', 'css-timeclock-addon' ) );
	}

	/**
	 * @param string $mysql_datetime Datetime string.
	 * @return string
	 */
	public function format_hour_minute( $mysql_datetime ) {
		return css_tc_addon()->time->site_hm( $mysql_datetime );
	}

	/**
	 * @return string
	 */
	public function site_date() {
		return css_tc_addon()->time->site_today();
	}

	/**
	 * @param string $date Y-m-d.
	 * @param int    $offset_days Days to add (negative to subtract).
	 * @return string
	 */
	public function shift_date( $date, $offset_days ) {
		return css_tc_addon()->time->shift_date( $date, $offset_days );
	}

	/**
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public function format_day_label( $date ) {
		return css_tc_addon()->time->format_day_label( $date );
	}

	/**
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public function format_weekday( $date ) {
		return css_tc_addon()->time->format_weekday( $date );
	}

	/**
	 * Combine a site-local work date and time into the UTC storage string.
	 *
	 * Seconds are kept when the input includes them, or when they were omitted
	 * but match the hour and minute of $original_stored.
	 *
	 * @param string $date            Y-m-d in the site timezone.
	 * @param string $hm              H:i or H:i:s.
	 * @param bool   $next_day        Whether the time is the following calendar day.
	 * @param string $original_stored Existing stored instant, used to preserve seconds.
	 * @return string
	 */
	public function combine_day_time( $date, $hm, $next_day = false, $original_stored = '' ) {
		return css_tc_addon()->time->combine_day_time( $date, $hm, $next_day, $original_stored );
	}

	/**
	 * @param string $hm Raw hour:minute.
	 * @return string
	 */
	public function normalize_hour_minute( $hm ) {
		return css_tc_addon()->time->normalize_hour_minute( $hm );
	}

	/**
	 * @param string $start Start datetime.
	 * @param string $end   End datetime.
	 * @return string
	 */
	public function elapsed_label( $start, $end ) {
		return css_tc_addon()->time->elapsed_label( $start, $end );
	}

	/**
	 * Same address the PIN rate limit and office allowlist use, including
	 * X-Forwarded-For on WP Engine. Stored on the shift as ip_address_in/out.
	 *
	 * @return string
	 */
	private function client_ip() {
		return css_tc_addon()->pins->client_ip();
	}
}
