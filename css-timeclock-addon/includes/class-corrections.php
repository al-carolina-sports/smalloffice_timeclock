<?php
/**
 * Employee punch-correction suggestions and supervisor review.
 *
 * Suggestions are stored as a private CPT so AIO Lite shift files stay
 * untouched. Approving writes AIO-compatible shift meta and keeps an audit.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Correction requests: pending until a time-clock admin reviews them.
 */
class Css_Tc_Corrections {

	const POST_TYPE     = 'css_tc_correction';
	const EMPLOYEE_NONCE = 'css_tc_employee';
	const MANAGER_NONCE  = 'css_tc_manager_day';
	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';

	/**
	 * Option that records the correction meta schema. Bumping it does not rewrite posts.
	 */
	const SCHEMA_OPTION = 'css_tc_correction_schema';

	/**
	 * Schema 2 adds review-time hour snapshot meta. Older reviewed posts are left alone.
	 * Schema 3 drops day flags that remained after a correction was already reviewed.
	 * It does not rewrite correction posts.
	 */
	const SCHEMA_VERSION = 3;

	const SNAP_DAY_TOTAL    = 'css_tc_snap_day_total';
	const SNAP_DAY_ORIGINAL = 'css_tc_snap_day_original';
	const SNAP_SHIFT_HOURS  = 'css_tc_snap_shift_hours';
	const SNAP_SHIFT_ORIGINAL = 'css_tc_snap_shift_original';

	/**
	 * Shifts for a user and pay period, reused while building the review list.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private $day_shift_cache = array();

	/**
	 * @return void
	 */
	public function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Time corrections', 'css-timeclock-addon' ),
					'singular_name' => __( 'Time correction', 'css-timeclock-addon' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
			)
		);
		$this->register_snapshot_meta();
	}

	/**
	 * Declare snapshot meta and record the schema version. Does not read or write correction posts.
	 *
	 * @return void
	 */
	public function maybe_upgrade_schema() {
		$this->register_snapshot_meta();
		$current = (int) get_option( self::SCHEMA_OPTION, 0 );
		if ( $current >= self::SCHEMA_VERSION ) {
			return;
		}
		if ( $current > 0 && $current < 3 ) {
			$this->clear_stale_day_flags();
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * @return void
	 */
	private function register_snapshot_meta() {
		$keys = array(
			self::SNAP_DAY_TOTAL,
			self::SNAP_DAY_ORIGINAL,
			self::SNAP_SHIFT_HOURS,
			self::SNAP_SHIFT_ORIGINAL,
		);
		foreach ( $keys as $key ) {
			if ( registered_meta_key_exists( 'post', $key, self::POST_TYPE ) ) {
				continue;
			}
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}
	}

	/**
	 * Dashboard payload: recent days + this employee's suggestions only.
	 *
	 * @param int $user_id Employee user ID.
	 * @return array<string,mixed>
	 */
	public function dashboard_for_user( $user_id ) {
		$user_id  = (int) $user_id;
		$settings = css_tc_addon()->get_settings();
		$lookback = isset( $settings['times_lookback_days'] ) ? (int) $settings['times_lookback_days'] : 21;
		$days     = css_tc_addon()->punches->calendar_days_for_user( $user_id, $lookback );
		$by_date  = $this->suggestions_by_date( $user_id );

		foreach ( $days as &$day ) {
			$day['suggestion'] = isset( $by_date[ $day['date'] ] ) ? $by_date[ $day['date'] ] : null;
		}
		unset( $day );

		return array(
			'days'     => $days,
			'lookback' => $lookback,
			'name'     => css_tc_addon()->employees->greeting_name( $user_id ),
		);
	}

	/**
	 * @param int $user_id Employee user ID.
	 * @return array<string,array<string,mixed>>
	 */
	public function suggestions_by_date( $user_id ) {
		$items = $this->query_posts(
			array(
				'author'         => (int) $user_id,
				'posts_per_page' => 80,
				'post_status'    => array( 'pending', 'private', 'draft' ),
			)
		);

		$map = array();
		foreach ( $items as $post ) {
			$row = $this->to_public_row( $post, false );
			if ( ! $row ) {
				continue;
			}
			$date = $row['work_date'];
			if ( ! isset( $map[ $date ] ) || $this->is_newer_status( $row, $map[ $date ] ) ) {
				$map[ $date ] = $row;
			}
		}

		return $map;
	}

	/**
	 * Prefer a pending suggestion, else the most recently reviewed.
	 *
	 * @param array<string,mixed> $candidate New row.
	 * @param array<string,mixed> $current   Stored row.
	 * @return bool
	 */
	private function is_newer_status( $candidate, $current ) {
		if ( self::STATUS_PENDING === $candidate['status'] && self::STATUS_PENDING !== $current['status'] ) {
			return true;
		}
		if ( self::STATUS_PENDING === $current['status'] && self::STATUS_PENDING !== $candidate['status'] ) {
			return false;
		}
		return (int) $candidate['id'] > (int) $current['id'];
	}

	/**
	 * Create or replace a pending suggestion for one work day.
	 *
	 * @param int                  $user_id Employee user ID.
	 * @param array<string,mixed>  $input   Sanitized form fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public function submit( $user_id, $input ) {
		$user_id = (int) $user_id;
		$parsed  = $this->parse_submission( $user_id, $input );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$limited = $this->assert_not_rate_limited( $user_id );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		return $this->store_parsed( $user_id, $parsed );
	}

	/**
	 * Write one already-validated suggestion. Does not rate-limit.
	 *
	 * @param int                 $user_id Employee.
	 * @param array<string,mixed> $parsed  Result of parse_submission().
	 * @return array<string,mixed>|WP_Error
	 */
	private function store_parsed( $user_id, $parsed ) {
		$existing = $this->pending_match( $user_id, $parsed );
		$title    = sprintf(
			/* translators: 1: employee name, 2: work date */
			__( 'Correction: %1$s — %2$s', 'css-timeclock-addon' ),
			css_tc_addon()->employees->display_name( $user_id ),
			$parsed['work_date']
		);

		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => $title,
			'post_status' => 'pending',
			'post_author' => $user_id,
		);

		if ( $existing ) {
			$postarr['ID'] = (int) $existing->ID;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		foreach ( $parsed['meta'] as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		delete_post_meta( $post_id, 'css_tc_reviewer_id' );
		delete_post_meta( $post_id, 'css_tc_reviewed_at' );
		delete_post_meta( $post_id, 'css_tc_review_note' );
		delete_post_meta( $post_id, 'css_tc_applied_shift_id' );

		$post = get_post( $post_id );
		return $this->to_public_row( $post, false );
	}

	/**
	 * Pending suggestion for the same shift, or a specific pending post when
	 * the line is a new shift being edited again.
	 *
	 * @param int                 $user_id Employee.
	 * @param array<string,mixed> $parsed  Parsed submission.
	 * @return WP_Post|null
	 */
	private function pending_match( $user_id, $parsed ) {
		$shift_id = isset( $parsed['meta']['css_tc_shift_id'] ) ? (int) $parsed['meta']['css_tc_shift_id'] : 0;
		if ( $shift_id > 0 ) {
			return $this->pending_for_shift( $user_id, $shift_id );
		}

		$correction_id = isset( $parsed['correction_id'] ) ? (int) $parsed['correction_id'] : 0;
		if ( $correction_id < 1 ) {
			return null;
		}

		$post = get_post( $correction_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'pending' !== $post->post_status ) {
			return null;
		}
		if ( (int) $post->post_author !== (int) $user_id ) {
			return null;
		}
		if ( (int) get_post_meta( $post->ID, 'css_tc_shift_id', true ) > 0 ) {
			return null;
		}
		return $post;
	}

	/**
	 * @param int $user_id  Employee user ID.
	 * @param int $shift_id Shift post ID.
	 * @return WP_Post|null
	 */
	public function pending_for_shift( $user_id, $shift_id ) {
		$items = $this->query_posts(
			array(
				'author'         => (int) $user_id,
				'posts_per_page' => 5,
				'post_status'    => 'pending',
				'meta_key'       => 'css_tc_shift_id',
				'meta_value'     => (string) (int) $shift_id,
			)
		);

		return ! empty( $items ) ? $items[0] : null;
	}

	/**
	 * Pending suggestions for one employee, grouped by work date.
	 *
	 * @param int    $user_id Employee.
	 * @param string $start   Y-m-d inclusive.
	 * @param string $end     Y-m-d inclusive.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public function pending_by_date( $user_id, $start, $end ) {
		$items = $this->query_posts(
			array(
				'author'         => (int) $user_id,
				'posts_per_page' => 100,
				'post_status'    => 'pending',
			)
		);

		$map = array();
		foreach ( $items as $post ) {
			$row = $this->to_public_row( $post, false );
			if ( ! $row ) {
				continue;
			}
			$date = (string) $row['work_date'];
			if ( $date < $start || $date > $end ) {
				continue;
			}
			if ( ! isset( $map[ $date ] ) ) {
				$map[ $date ] = array();
			}
			$map[ $date ][] = $row;
		}

		return $map;
	}

	/**
	 * Work dates in the period that have a correction in one of the given statuses.
	 *
	 * @param int          $user_id   Employee.
	 * @param string       $start     Y-m-d inclusive.
	 * @param string       $end       Y-m-d inclusive.
	 * @param string|array $statuses  WP post statuses.
	 * @return array<string,bool>
	 */
	public function dates_with_status( $user_id, $start, $end, $statuses ) {
		$items = $this->query_posts(
			array(
				'author'         => (int) $user_id,
				'posts_per_page' => 100,
				'post_status'    => $statuses,
			)
		);

		$dates = array();
		foreach ( $items as $post ) {
			$date = (string) get_post_meta( $post->ID, 'css_tc_work_date', true );
			if ( '' === $date || $date < $start || $date > $end ) {
				continue;
			}
			$dates[ $date ] = true;
		}

		return $dates;
	}

	/**
	 * Submit every changed line in the current pay period.
	 *
	 * Validates the whole set before writing. Unchanged and blank new rows
	 * are skipped. One rate-limit hit covers the batch.
	 *
	 * @param int                            $user_id Employee.
	 * @param array<int,array<string,mixed>> $lines   Raw lines.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function submit_period( $user_id, $lines ) {
		$user_id = (int) $user_id;
		$limited = $this->assert_not_rate_limited( $user_id );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$parsed_rows = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$shift_id = isset( $line['shift_id'] ) ? absint( $line['shift_id'] ) : 0;
			$in       = isset( $line['proposed_in'] ) ? trim( (string) $line['proposed_in'] ) : '';
			$out      = isset( $line['proposed_out'] ) ? trim( (string) $line['proposed_out'] ) : '';
			if ( $shift_id < 1 && '' === $in && '' === $out ) {
				continue;
			}

			$parsed = $this->parse_submission( $user_id, $line );
			if ( is_wp_error( $parsed ) ) {
				$code = $parsed->get_error_code();
				if ( 'css_tc_unchanged' === $code || 'css_tc_empty' === $code ) {
					continue;
				}
				return $parsed;
			}
			$parsed_rows[] = $parsed;
		}

		if ( empty( $parsed_rows ) ) {
			return new WP_Error(
				'css_tc_unchanged',
				__( 'Change a clock-in or clock-out before sending.', 'css-timeclock-addon' )
			);
		}

		$created = array();
		foreach ( $parsed_rows as $parsed ) {
			$stored = $this->store_parsed( $user_id, $parsed );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			$created[] = $stored;
		}

		return $created;
	}

	/**
	 * @param int    $user_id Employee user ID.
	 * @param string $date    Y-m-d.
	 * @return WP_Post|null
	 */
	public function pending_for_day( $user_id, $date ) {
		$items = $this->query_posts(
			array(
				'author'         => (int) $user_id,
				'posts_per_page' => 5,
				'post_status'    => 'pending',
				'meta_key'       => 'css_tc_work_date',
				'meta_value'     => $date,
			)
		);

		return ! empty( $items ) ? $items[0] : null;
	}

	/**
	 * Admin queue: pending first, then recently reviewed.
	 *
	 * @return array{pending:array<int,array<string,mixed>>,recent:array<int,array<string,mixed>>,pending_count:int}
	 */
	public function admin_queue() {
		$pending_posts = $this->query_posts(
			array(
				'posts_per_page' => 50,
				'post_status'    => 'pending',
			)
		);
		$recent_posts  = $this->query_posts(
			array(
				'posts_per_page' => 20,
				'post_status'    => array( 'private', 'draft' ),
			)
		);

		$pending = array();
		foreach ( $pending_posts as $post ) {
			$row = $this->to_public_row( $post, true );
			if ( $row ) {
				$pending[] = $row;
			}
		}

		$recent = array();
		foreach ( $recent_posts as $post ) {
			$row = $this->to_public_row( $post, true );
			if ( $row ) {
				$recent[] = $row;
			}
		}

		return array(
			'pending'       => $pending,
			'recent'        => $recent,
			'pending_count' => count( $pending ),
		);
	}

	/**
	 * @return int
	 */
	public function pending_count() {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'pending',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$count = (int) $query->found_posts;
		wp_reset_postdata();
		return $count;
	}

	/**
	 * @param int    $correction_id Correction post ID.
	 * @param int    $reviewer_id   Admin user ID.
	 * @param string $note          Optional review note.
	 * @return array<string,mixed>|WP_Error
	 */
	public function approve( $correction_id, $reviewer_id, $note = '' ) {
		$post = $this->require_pending( $correction_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$open = $this->assert_correction_period_open( $post );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$user_id   = (int) $post->post_author;
		$work_date = (string) get_post_meta( $post->ID, 'css_tc_work_date', true );
		$shift_id  = (int) get_post_meta( $post->ID, 'css_tc_shift_id', true );
		$before_day = $this->fresh_day_hours( $user_id, $work_date, $shift_id );
		$shift_hours = $this->stored_shift_hours( $post );
		$punches = css_tc_addon()->punches;
		$audit   = array(
			'correction_id' => (int) $post->ID,
			'suggested_by'  => $user_id,
			'approved_by'   => (int) $reviewer_id,
		);

		$proposed_in  = (string) get_post_meta( $post->ID, 'css_tc_proposed_in', true );
		$proposed_out = (string) get_post_meta( $post->ID, 'css_tc_proposed_out', true );
		$clear_out    = ( '' === $proposed_out && '1' === (string) get_post_meta( $post->ID, 'css_tc_clear_out', true ) );

		if ( $shift_id > 0 ) {
			$applied = $punches->apply_times( $shift_id, $user_id, $proposed_in, $proposed_out, $clear_out, $audit );
		} else {
			if ( '' === $proposed_in ) {
				return new WP_Error( 'css_tc_need_in', __( 'This suggestion has no clock-in time to apply.', 'css-timeclock-addon' ) );
			}
			$applied = $punches->create_corrected_shift( $user_id, $proposed_in, $proposed_out, $audit );
		}

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		wp_update_post(
			array(
				'ID'          => (int) $post->ID,
				'post_status' => 'private',
			)
		);

		update_post_meta( $post->ID, 'css_tc_reviewer_id', (int) $reviewer_id );
		update_post_meta( $post->ID, 'css_tc_reviewed_at', $punches->current_mysql_time() );
		update_post_meta( $post->ID, 'css_tc_review_note', $this->sanitize_note( $note ) );
		update_post_meta( $post->ID, 'css_tc_applied_shift_id', (int) $applied['id'] );
		$this->store_review_snapshot(
			(int) $post->ID,
			$this->fresh_day_hours( $user_id, $work_date, $shift_id ),
			$before_day,
			$shift_hours['shift'],
			$shift_hours['original']
		);
		css_tc_addon()->timecard->clear_flag( $user_id, $work_date );

		$fresh = get_post( $post->ID );
		return $this->to_public_row( $fresh, true );
	}

	/**
	 * @param int    $correction_id Correction post ID.
	 * @param int    $reviewer_id   Admin user ID.
	 * @param string $note          Optional review note.
	 * @return array<string,mixed>|WP_Error
	 */
	public function reject( $correction_id, $reviewer_id, $note = '' ) {
		$post = $this->require_pending( $correction_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$user_id     = (int) $post->post_author;
		$work_date   = (string) get_post_meta( $post->ID, 'css_tc_work_date', true );
		$shift_id    = (int) get_post_meta( $post->ID, 'css_tc_shift_id', true );
		$before_day  = $this->fresh_day_hours( $user_id, $work_date, $shift_id );
		$shift_hours = $this->stored_shift_hours( $post );

		wp_update_post(
			array(
				'ID'          => (int) $post->ID,
				'post_status' => 'draft',
			)
		);

		update_post_meta( $post->ID, 'css_tc_reviewer_id', (int) $reviewer_id );
		update_post_meta( $post->ID, 'css_tc_reviewed_at', css_tc_addon()->punches->current_mysql_time() );
		update_post_meta( $post->ID, 'css_tc_review_note', $this->sanitize_note( $note ) );
		$this->store_review_snapshot(
			(int) $post->ID,
			$this->fresh_day_hours( $user_id, $work_date, $shift_id ),
			$before_day,
			$shift_hours['shift'],
			$shift_hours['original']
		);
		css_tc_addon()->timecard->clear_flag( $user_id, $work_date );

		$fresh = get_post( $post->ID );
		return $this->to_public_row( $fresh, true );
	}

	/**
	 * Refuse approval when the shift or the proposed times sit in a closed period.
	 *
	 * @param WP_Post $post Pending correction.
	 * @return true|WP_Error
	 */
	private function assert_correction_period_open( $post ) {
		$work_date = (string) get_post_meta( $post->ID, 'css_tc_work_date', true );
		$open      = css_tc_addon()->pay_periods->assert_open_date( $work_date );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$shift_id = (int) get_post_meta( $post->ID, 'css_tc_shift_id', true );
		if ( $shift_id > 0 ) {
			$live_in = (string) get_post_meta( $shift_id, 'employee_clock_in_time', true );
			if ( '' !== $live_in ) {
				$live = css_tc_addon()->pay_periods->assert_shift_times_open( $live_in, '' );
				if ( is_wp_error( $live ) ) {
					return $live;
				}
			}
		}

		$proposed_in  = (string) get_post_meta( $post->ID, 'css_tc_proposed_in', true );
		$proposed_out = (string) get_post_meta( $post->ID, 'css_tc_proposed_out', true );
		if ( '' !== $proposed_in ) {
			$proposed = css_tc_addon()->pay_periods->assert_shift_times_open( $proposed_in, $proposed_out );
			if ( is_wp_error( $proposed ) ) {
				return $proposed;
			}
		}

		return true;
	}

	/**
	 * @param int $correction_id Correction post ID.
	 * @return WP_Post|WP_Error
	 */
	private function require_pending( $correction_id ) {
		$post = get_post( (int) $correction_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'css_tc_missing', __( 'That suggestion was not found.', 'css-timeclock-addon' ) );
		}
		if ( 'pending' !== $post->post_status ) {
			return new WP_Error( 'css_tc_not_pending', __( 'That suggestion has already been reviewed.', 'css-timeclock-addon' ) );
		}
		return $post;
	}

	/**
	 * @param int                 $user_id Employee user ID.
	 * @param array<string,mixed> $input   Raw input.
	 * @return array<string,mixed>|WP_Error
	 */
	private function parse_submission( $user_id, $input ) {
		$punches = css_tc_addon()->punches;
		$date    = isset( $input['work_date'] ) ? sanitize_text_field( (string) $input['work_date'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'css_tc_bad_date', __( 'Choose a valid day.', 'css-timeclock-addon' ) );
		}

		$open = css_tc_addon()->pay_periods->assert_open_date( $date );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$reason = $this->sanitize_note( isset( $input['reason'] ) ? $input['reason'] : '' );

		$shift_id       = isset( $input['shift_id'] ) ? absint( $input['shift_id'] ) : 0;
		$correction_id  = isset( $input['correction_id'] ) ? absint( $input['correction_id'] ) : 0;
		$missing        = ! empty( $input['missing_punch'] );
		$out_next_day   = ! empty( $input['out_next_day'] );
		$clear_out      = empty( $input['proposed_out'] ) && ! empty( $input['clear_out'] );
		$original_in    = '';
		$original_out   = '';

		if ( $shift_id > 0 ) {
			$post = get_post( $shift_id );
			if ( ! $post || Css_Tc_Punches::POST_TYPE !== $post->post_type || (int) $post->post_author !== $user_id ) {
				return new WP_Error( 'css_tc_bad_shift', __( 'That shift does not belong to you.', 'css-timeclock-addon' ) );
			}
			$original_in  = (string) get_post_meta( $shift_id, 'employee_clock_in_time', true );
			$original_out = (string) get_post_meta( $shift_id, 'employee_clock_out_time', true );
			$live_open    = css_tc_addon()->pay_periods->assert_shift_times_open( $original_in, '' );
			if ( '' !== $original_in && is_wp_error( $live_open ) ) {
				return $live_open;
			}
		}

		$proposed_in  = $punches->combine_day_time( $date, isset( $input['proposed_in'] ) ? $input['proposed_in'] : '', false, $original_in );
		$proposed_out = $punches->combine_day_time( $date, isset( $input['proposed_out'] ) ? $input['proposed_out'] : '', $out_next_day, $original_out );

		// Blank inputs on an existing shift mean "leave this time", not "clear it".
		if ( $shift_id > 0 && '' === $proposed_in && '' !== $original_in ) {
			$proposed_in = $original_in;
		}
		if ( $shift_id > 0 && '' === $proposed_out && '' !== $original_out && ! $clear_out ) {
			$proposed_out = $original_out;
		}

		if ( $shift_id < 1 && ! $missing && '' === $proposed_in ) {
			return new WP_Error( 'css_tc_need_in', __( 'Add a clock-in time, or mark this as a missing punch.', 'css-timeclock-addon' ) );
		}

		if ( '' === $proposed_in && '' === $proposed_out && ! $missing && ! $clear_out ) {
			return new WP_Error( 'css_tc_empty', __( 'Propose a clock-in or clock-out time, or mark a missing punch.', 'css-timeclock-addon' ) );
		}

		if ( '' !== $proposed_in && '' !== $proposed_out && strcmp( $proposed_out, $proposed_in ) <= 0 ) {
			return new WP_Error( 'css_tc_order', __( 'Clock-out must be after clock-in. Check “next day” if the shift ran past midnight.', 'css-timeclock-addon' ) );
		}

		if ( $shift_id > 0 && $proposed_in === $original_in && $proposed_out === $original_out && ! $missing && ! $clear_out ) {
			return new WP_Error( 'css_tc_unchanged', __( 'Change a time or note a missing punch before sending this.', 'css-timeclock-addon' ) );
		}

		if ( '' !== $proposed_in ) {
			$proposed_open = css_tc_addon()->pay_periods->assert_shift_times_open( $proposed_in, $proposed_out );
			if ( is_wp_error( $proposed_open ) ) {
				return $proposed_open;
			}
			$in_day = css_tc_addon()->time->site_date_of( $proposed_in );
			if ( $in_day !== $date ) {
				return new WP_Error( 'css_tc_bad_date', __( 'Clock-in has to stay on the day you are correcting.', 'css-timeclock-addon' ) );
			}
		}

		if ( $shift_id < 1 && '' === $proposed_in ) {
			return new WP_Error( 'css_tc_need_in', __( 'A missing punch still needs a proposed clock-in time so a supervisor can apply it.', 'css-timeclock-addon' ) );
		}

		return array(
			'work_date'     => $date,
			'correction_id' => $correction_id,
			'meta'          => array(
				'css_tc_work_date'    => $date,
				'css_tc_shift_id'     => $shift_id,
				'css_tc_original_in'  => $original_in,
				'css_tc_original_out' => $original_out,
				'css_tc_proposed_in'  => $proposed_in,
				'css_tc_proposed_out' => $proposed_out,
				'css_tc_clear_out'    => $clear_out ? '1' : '',
				'css_tc_missing'      => $missing ? '1' : '',
				'css_tc_out_next_day' => $out_next_day ? '1' : '',
				'css_tc_reason'       => $reason,
			),
		);
	}

	/**
	 * @param WP_Post $post Correction post.
	 * @param bool    $for_admin Include employee name.
	 * @return array<string,mixed>|null
	 */
	public function to_public_row( $post, $for_admin = false ) {
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$punches      = css_tc_addon()->punches;
		$status       = $this->status_from_post( $post );
		$proposed_in  = (string) get_post_meta( $post->ID, 'css_tc_proposed_in', true );
		$proposed_out = (string) get_post_meta( $post->ID, 'css_tc_proposed_out', true );
		$original_in  = (string) get_post_meta( $post->ID, 'css_tc_original_in', true );
		$original_out = (string) get_post_meta( $post->ID, 'css_tc_original_out', true );
		$reviewed_at  = (string) get_post_meta( $post->ID, 'css_tc_reviewed_at', true );
		$reviewer_id  = (int) get_post_meta( $post->ID, 'css_tc_reviewer_id', true );

		$row = array(
			'id'              => (int) $post->ID,
			'work_date'       => (string) get_post_meta( $post->ID, 'css_tc_work_date', true ),
			'shift_id'        => (int) get_post_meta( $post->ID, 'css_tc_shift_id', true ),
			'status'          => $status,
			'reason'          => (string) get_post_meta( $post->ID, 'css_tc_reason', true ),
			'missing_punch'   => ( '1' === (string) get_post_meta( $post->ID, 'css_tc_missing', true ) ),
			'out_next_day'    => ( '1' === (string) get_post_meta( $post->ID, 'css_tc_out_next_day', true ) ),
			'original_in'     => $original_in ? $punches->format_time( $original_in ) : '',
			'original_out'    => $original_out ? $punches->format_time( $original_out ) : '',
			'proposed_in'     => $proposed_in ? $punches->format_time( $proposed_in ) : '',
			'proposed_out'    => $proposed_out ? $punches->format_time( $proposed_out ) : '',
			'proposed_in_hm'  => $proposed_in ? $punches->format_hour_minute( $proposed_in ) : '',
			'proposed_out_hm' => $proposed_out ? $punches->format_hour_minute( $proposed_out ) : '',
			'proposed_in_hms' => $proposed_in ? css_tc_addon()->time->site_hms( $proposed_in ) : '',
			'proposed_out_hms'=> $proposed_out ? css_tc_addon()->time->site_hms( $proposed_out ) : '',
			'review_note'     => (string) get_post_meta( $post->ID, 'css_tc_review_note', true ),
			'reviewed_at'     => $reviewed_at ? $punches->format_time( $reviewed_at ) : '',
			'submitted_at'    => $punches->format_time( $post->post_date ),
			'manager_edit'    => ( '1' === (string) get_post_meta( $post->ID, 'css_tc_manager_edit', true ) ),
			'deleted_shift'   => ( '1' === (string) get_post_meta( $post->ID, 'css_tc_deleted', true ) ),
		);

		$time = css_tc_addon()->time;
		$proposed_seconds = ( '' !== $proposed_in && '' !== $proposed_out ) ? $time->elapsed_seconds( $proposed_in, $proposed_out ) : -1;
		$original_seconds = ( '' !== $original_in && '' !== $original_out ) ? $time->elapsed_seconds( $original_in, $original_out ) : -1;

		if ( ! $for_admin ) {
			$row['proposed_hours'] = $time->format_hours_hm( $proposed_seconds );
			$row['original_hours'] = $time->format_hours_hm( $original_seconds );
			return $row;
		}

		$row['employee']         = css_tc_addon()->employees->display_name( (int) $post->post_author );
		$row['employee_id']      = (int) $post->post_author;
		$row['reviewer']         = $reviewer_id ? css_tc_addon()->employees->display_name( $reviewer_id ) : '';
		$row['applied_shift_id'] = (int) get_post_meta( $post->ID, 'css_tc_applied_shift_id', true );

		if ( self::STATUS_PENDING === $status ) {
			$row['proposed_hours']     = $time->format_hours_hm( $proposed_seconds );
			$row['original_hours']     = $time->format_hours_hm( $original_seconds );
			$row['day_total_hours']    = $time->format_hours_hm(
				$this->review_day_seconds( (int) $post->post_author, $row['work_date'], $row['shift_id'], $proposed_seconds )
			);
			$row['day_original_hours'] = $time->format_hours_hm(
				$this->review_day_seconds( (int) $post->post_author, $row['work_date'], $row['shift_id'], null )
			);
			return $row;
		}

		if ( metadata_exists( 'post', $post->ID, self::SNAP_DAY_TOTAL ) ) {
			$row['day_total_hours']    = (string) get_post_meta( $post->ID, self::SNAP_DAY_TOTAL, true );
			$row['day_original_hours'] = (string) get_post_meta( $post->ID, self::SNAP_DAY_ORIGINAL, true );
			$row['proposed_hours']     = (string) get_post_meta( $post->ID, self::SNAP_SHIFT_HOURS, true );
			$row['original_hours']     = (string) get_post_meta( $post->ID, self::SNAP_SHIFT_ORIGINAL, true );
			return $row;
		}

		$row['day_totals_unrecorded'] = true;
		if ( $proposed_seconds >= 0 ) {
			$row['proposed_hours'] = $time->format_hours_hm( $proposed_seconds );
		}
		if ( $original_seconds >= 0 ) {
			$row['original_hours'] = $time->format_hours_hm( $original_seconds );
		}

		return $row;
	}

	/**
	 * Day total from shifts as they are stored right now. Clears the request cache first.
	 *
	 * @param int    $user_id   Employee.
	 * @param string $work_date Y-m-d.
	 * @param int    $shift_id  Shift this correction edits.
	 * @return string
	 */
	private function fresh_day_hours( $user_id, $work_date, $shift_id ) {
		$this->day_shift_cache = array();
		return css_tc_addon()->time->format_hours_hm(
			$this->review_day_seconds( (int) $user_id, (string) $work_date, (int) $shift_id, null )
		);
	}

	/**
	 * Shift lengths from the times stored on the correction, not from the live shift.
	 *
	 * @param WP_Post $post Correction post.
	 * @return array{shift:string,original:string}
	 */
	private function stored_shift_hours( $post ) {
		$time         = css_tc_addon()->time;
		$proposed_in  = (string) get_post_meta( $post->ID, 'css_tc_proposed_in', true );
		$proposed_out = (string) get_post_meta( $post->ID, 'css_tc_proposed_out', true );
		$original_in  = (string) get_post_meta( $post->ID, 'css_tc_original_in', true );
		$original_out = (string) get_post_meta( $post->ID, 'css_tc_original_out', true );
		$proposed     = ( '' !== $proposed_in && '' !== $proposed_out ) ? $time->elapsed_seconds( $proposed_in, $proposed_out ) : -1;
		$original     = ( '' !== $original_in && '' !== $original_out ) ? $time->elapsed_seconds( $original_in, $original_out ) : -1;
		return array(
			'shift'    => $time->format_hours_hm( $proposed ),
			'original' => $time->format_hours_hm( $original ),
		);
	}

	/**
	 * Freeze the card at the moment of approval or rejection.
	 *
	 * @param int    $post_id        Correction post ID.
	 * @param string $day_total      Total hours after the decision.
	 * @param string $day_original   Day total before the decision.
	 * @param string $shift_hours    Proposed shift length.
	 * @param string $shift_original Stored shift length.
	 * @return void
	 */
	private function store_review_snapshot( $post_id, $day_total, $day_original, $shift_hours, $shift_original ) {
		update_post_meta( (int) $post_id, self::SNAP_DAY_TOTAL, $day_total );
		update_post_meta( (int) $post_id, self::SNAP_DAY_ORIGINAL, $day_original );
		update_post_meta( (int) $post_id, self::SNAP_SHIFT_HOURS, $shift_hours );
		update_post_meta( (int) $post_id, self::SNAP_SHIFT_ORIGINAL, $shift_original );
	}

	/**
	 * Counted seconds for one calendar day. A proposal replaces that shift.
	 *
	 * @param int      $user_id          Employee.
	 * @param string   $work_date        Y-m-d.
	 * @param int      $shift_id         Shift this correction edits, or 0 for a new punch.
	 * @param int|null $override_seconds Proposed seconds, or null to use stored times. Negative skips the shift.
	 * @return int
	 */
	private function review_day_seconds( $user_id, $work_date, $shift_id, $override_seconds ) {
		$total   = 0;
		$matched = false;
		foreach ( $this->shifts_on_date( $user_id, $work_date ) as $shift ) {
			$complete = empty( $shift['is_open'] ) && empty( $shift['is_missing_in'] ) && '' !== $shift['clock_in_raw'] && '' !== $shift['clock_out_raw'];
			if ( (int) $shift['id'] === (int) $shift_id && $shift_id > 0 ) {
				$matched = true;
				if ( null === $override_seconds ) {
					if ( $complete && (int) $shift['seconds'] >= 0 ) {
						$total += (int) $shift['seconds'];
					}
				} elseif ( $override_seconds >= 0 ) {
					$total += (int) $override_seconds;
				}
				continue;
			}
			if ( $complete && (int) $shift['seconds'] >= 0 ) {
				$total += (int) $shift['seconds'];
			}
		}
		if ( ! $matched && null !== $override_seconds && $override_seconds >= 0 ) {
			$total += (int) $override_seconds;
		}
		return $total;
	}

	/**
	 * @param int    $user_id   Employee.
	 * @param string $work_date Y-m-d.
	 * @return array<int,array<string,mixed>>
	 */
	private function shifts_on_date( $user_id, $work_date ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $work_date ) ) {
			return array();
		}
		$period = css_tc_addon()->pay_periods->period_for_date( $work_date );
		if ( ! $period ) {
			return array();
		}
		$key = (int) $user_id . '|' . $period['start'];
		if ( ! isset( $this->day_shift_cache[ $key ] ) ) {
			$this->day_shift_cache[ $key ] = css_tc_addon()->timecard->shifts_for_period( (int) $user_id, $period );
		}
		$day = array();
		foreach ( $this->day_shift_cache[ $key ] as $shift ) {
			if ( isset( $shift['work_date'] ) && $shift['work_date'] === $work_date ) {
				$day[] = $shift;
			}
		}
		return $day;
	}

	/**
	 * @param WP_Post $post Correction post.
	 * @return string
	 */
	private function status_from_post( $post ) {
		if ( 'private' === $post->post_status ) {
			return self::STATUS_APPROVED;
		}
		if ( 'draft' === $post->post_status ) {
			return self::STATUS_REJECTED;
		}
		return self::STATUS_PENDING;
	}

	/**
	 * @param array<string,mixed> $args WP_Query args.
	 * @return WP_Post[]
	 */
	private function query_posts( $args ) {
		$defaults = array(
			'post_type'      => self::POST_TYPE,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'posts_per_page' => 40,
		);
		$query    = new WP_Query( array_merge( $defaults, $args ) );
		$posts    = $query->posts;
		wp_reset_postdata();
		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * @param mixed $note Raw note.
	 * @return string
	 */
	private function sanitize_note( $note ) {
		$text = sanitize_textarea_field( (string) $note );
		if ( strlen( $text ) > 500 ) {
			$text = substr( $text, 0, 500 );
		}
		return $text;
	}

	/**
	 * @param int $user_id Employee user ID.
	 * @return true|WP_Error
	 */
	private function assert_not_rate_limited( $user_id ) {
		$key   = 'css_tc_suggest_' . (int) $user_id;
		$count = (int) get_transient( $key );
		if ( $count >= 12 ) {
			return new WP_Error( 'css_tc_suggest_limited', __( 'Please wait a bit before sending another suggestion.', 'css-timeclock-addon' ) );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Save a manager's direct edits for one day. Each changed, added, or deleted
	 * shift becomes an auto-approved correction with a 1.4.7 day snapshot.
	 * Closed pay periods are allowed.
	 *
	 * @param int                            $employee_id Employee whose punches change.
	 * @param int                            $reviewer_id Manager.
	 * @param string                         $date        Y-m-d.
	 * @param array<int,array<string,mixed>> $lines       Raw lines.
	 * @param string                         $note        Optional note.
	 * @return int|WP_Error Number of recorded edits.
	 */
	public function manager_edit_day( $employee_id, $reviewer_id, $date, $lines, $note = '' ) {
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			return new WP_Error( 'css_tc_forbidden', __( 'You do not have permission to edit timecards.', 'css-timeclock-addon' ) );
		}

		$employee_id = (int) $employee_id;
		$reviewer_id = (int) $reviewer_id;
		$date        = sanitize_text_field( (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'css_tc_bad_date', __( 'Choose a valid day.', 'css-timeclock-addon' ) );
		}

		$allowed = false;
		foreach ( css_tc_addon()->employees->list_for_admin() as $user ) {
			if ( (int) $user->ID === $employee_id ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			return new WP_Error( 'css_tc_bad_employee', __( 'That employee was not found.', 'css-timeclock-addon' ) );
		}

		$ops = $this->parse_manager_lines( $employee_id, $date, $lines );
		if ( is_wp_error( $ops ) ) {
			return $ops;
		}
		if ( empty( $ops ) ) {
			return new WP_Error(
				'css_tc_unchanged',
				__( 'Change a clock-in or clock-out, add a punch, or delete a shift before saving.', 'css-timeclock-addon' )
			);
		}

		$note  = $this->sanitize_note( $note );
		$count = 0;
		foreach ( $ops as $op ) {
			$saved = $this->commit_manager_op( $employee_id, $reviewer_id, $date, $op, $note );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			++$count;
		}

		css_tc_addon()->timecard->clear_flag( $employee_id, $date );
		return $count;
	}

	/**
	 * @param int                            $employee_id Employee.
	 * @param string                         $date        Y-m-d.
	 * @param array<int,array<string,mixed>> $lines       Raw lines.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function parse_manager_lines( $employee_id, $date, $lines ) {
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$punches = css_tc_addon()->punches;
		$ops     = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$shift_id = isset( $line['shift_id'] ) ? absint( $line['shift_id'] ) : 0;
			$delete   = ! empty( $line['delete'] );
			$next_day = ! empty( $line['out_next_day'] );
			$raw_in   = isset( $line['proposed_in'] ) ? trim( (string) $line['proposed_in'] ) : '';
			$raw_out  = isset( $line['proposed_out'] ) ? trim( (string) $line['proposed_out'] ) : '';

			if ( $shift_id < 1 && '' === $raw_in && '' === $raw_out ) {
				continue;
			}

			$original_in  = '';
			$original_out = '';
			if ( $shift_id > 0 ) {
				$post = get_post( $shift_id );
				if ( ! $post || Css_Tc_Punches::POST_TYPE !== $post->post_type || (int) $post->post_author !== $employee_id ) {
					return new WP_Error( 'css_tc_bad_shift', __( 'That shift does not belong to this employee.', 'css-timeclock-addon' ) );
				}
				$original_in  = (string) get_post_meta( $shift_id, 'employee_clock_in_time', true );
				$original_out = (string) get_post_meta( $shift_id, 'employee_clock_out_time', true );
			}

			if ( $delete && $shift_id > 0 ) {
				$ops[] = array(
					'action'       => 'delete',
					'shift_id'     => $shift_id,
					'original_in'  => $original_in,
					'original_out' => $original_out,
					'proposed_in'  => '',
					'proposed_out' => '',
					'clear_out'    => false,
					'out_next_day' => false,
					'missing'      => false,
				);
				continue;
			}

			$proposed_in  = $punches->combine_day_time( $date, $raw_in, false, $original_in );
			$proposed_out = $punches->combine_day_time( $date, $raw_out, $next_day, $original_out );

			if ( '' === $proposed_in ) {
				return new WP_Error( 'css_tc_need_in', __( 'Each punch needs a clock-in time.', 'css-timeclock-addon' ) );
			}

			$in_day = css_tc_addon()->time->site_date_of( $proposed_in );
			if ( $in_day !== $date ) {
				return new WP_Error( 'css_tc_bad_date', __( 'Clock-in has to stay on the day you are editing.', 'css-timeclock-addon' ) );
			}

			if ( '' !== $proposed_out && strcmp( $proposed_out, $proposed_in ) <= 0 ) {
				return new WP_Error( 'css_tc_order', __( 'Clock-out must be after clock-in. Check “next day” if the shift ran past midnight.', 'css-timeclock-addon' ) );
			}

			if ( $shift_id > 0 && $proposed_in === $original_in && $proposed_out === $original_out ) {
				continue;
			}

			$ops[] = array(
				'action'       => $shift_id > 0 ? 'edit' : 'add',
				'shift_id'     => $shift_id,
				'original_in'  => $original_in,
				'original_out' => $original_out,
				'proposed_in'  => $proposed_in,
				'proposed_out' => $proposed_out,
				'clear_out'    => ( '' === $proposed_out ),
				'out_next_day' => $next_day,
				'missing'      => ( $shift_id < 1 ),
			);
		}

		return $ops;
	}

	/**
	 * @param int                 $employee_id Employee.
	 * @param int                 $reviewer_id Manager.
	 * @param string              $date        Y-m-d.
	 * @param array<string,mixed> $op          One parsed change.
	 * @param string              $note        Optional note.
	 * @return int|WP_Error Correction post ID.
	 */
	private function commit_manager_op( $employee_id, $reviewer_id, $date, $op, $note ) {
		$before  = $this->fresh_day_hours( $employee_id, $date, (int) $op['shift_id'] );
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => sprintf(
					/* translators: 1: employee name, 2: work date */
					__( 'Edited by manager: %1$s — %2$s', 'css-timeclock-addon' ),
					css_tc_addon()->employees->display_name( $employee_id ),
					$date
				),
				'post_status' => 'private',
				'post_author' => $employee_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$meta = array(
			'css_tc_work_date'    => $date,
			'css_tc_shift_id'     => (int) $op['shift_id'],
			'css_tc_original_in'  => (string) $op['original_in'],
			'css_tc_original_out' => (string) $op['original_out'],
			'css_tc_proposed_in'  => (string) $op['proposed_in'],
			'css_tc_proposed_out' => (string) $op['proposed_out'],
			'css_tc_clear_out'    => ! empty( $op['clear_out'] ) ? '1' : '',
			'css_tc_missing'      => ! empty( $op['missing'] ) ? '1' : '',
			'css_tc_out_next_day' => ! empty( $op['out_next_day'] ) ? '1' : '',
			'css_tc_deleted'      => ( 'delete' === $op['action'] ) ? '1' : '',
			'css_tc_reason'       => __( 'Edited by manager', 'css-timeclock-addon' ),
			'css_tc_manager_edit' => '1',
			'css_tc_reviewer_id'  => (int) $reviewer_id,
			'css_tc_review_note'  => $note,
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		$punches = css_tc_addon()->punches;
		$audit   = array(
			'correction_id' => (int) $post_id,
			'suggested_by'  => (int) $employee_id,
			'approved_by'   => (int) $reviewer_id,
		);

		if ( 'delete' === $op['action'] ) {
			$removed = wp_delete_post( (int) $op['shift_id'], true );
			if ( ! $removed ) {
				wp_delete_post( (int) $post_id, true );
				return new WP_Error( 'css_tc_bad_shift', __( 'That shift could not be deleted.', 'css-timeclock-addon' ) );
			}
			$applied_id = 0;
		} elseif ( 'add' === $op['action'] ) {
			$applied = $punches->create_corrected_shift( $employee_id, (string) $op['proposed_in'], (string) $op['proposed_out'], $audit );
			if ( is_wp_error( $applied ) ) {
				wp_delete_post( (int) $post_id, true );
				return $applied;
			}
			$applied_id = (int) $applied['id'];
		} else {
			$applied = $punches->apply_times(
				(int) $op['shift_id'],
				$employee_id,
				(string) $op['proposed_in'],
				(string) $op['proposed_out'],
				! empty( $op['clear_out'] ),
				$audit
			);
			if ( is_wp_error( $applied ) ) {
				wp_delete_post( (int) $post_id, true );
				return $applied;
			}
			$applied_id = (int) $applied['id'];
		}

		update_post_meta( $post_id, 'css_tc_applied_shift_id', $applied_id );
		update_post_meta( $post_id, 'css_tc_reviewed_at', $punches->current_mysql_time() );

		$correction = get_post( $post_id );
		$hours      = $correction ? $this->stored_shift_hours( $correction ) : array(
			'shift'    => '--:--',
			'original' => '--:--',
		);
		$this->store_review_snapshot(
			(int) $post_id,
			$this->fresh_day_hours( $employee_id, $date, (int) $op['shift_id'] ),
			$before,
			$hours['shift'],
			$hours['original']
		);

		return (int) $post_id;
	}

	/**
	 * Drop flags whose day was already reviewed and has nothing pending.
	 * A later Request change is left alone because this runs once.
	 *
	 * @return void
	 */
	private function clear_stale_day_flags() {
		$users = get_users(
			array(
				'meta_key' => Css_Tc_Timecard::FLAG_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'   => array( 'ID' ),
				'number'   => 2000,
			)
		);
		if ( ! is_array( $users ) ) {
			return;
		}

		foreach ( $users as $user ) {
			$user_id = (int) $user->ID;
			$flags   = css_tc_addon()->timecard->flagged_dates( $user_id );
			$keep    = array();
			foreach ( $flags as $date ) {
				if ( $this->pending_for_day( $user_id, $date ) ) {
					$keep[] = $date;
					continue;
				}
				$reviewed = $this->query_posts(
					array(
						'author'         => $user_id,
						'posts_per_page' => 1,
						'post_status'    => array( 'private', 'draft' ),
						'meta_key'       => 'css_tc_work_date',
						'meta_value'     => $date,
					)
				);
				if ( empty( $reviewed ) ) {
					$keep[] = $date;
				}
			}
			update_user_meta( $user_id, Css_Tc_Timecard::FLAG_META, $keep );
		}
	}

	/**
	 * Delete all correction posts (uninstall).
	 *
	 * @return void
	 */
	public static function delete_all() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 500,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
}
