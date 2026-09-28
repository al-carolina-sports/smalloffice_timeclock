<?php
/**
 * Employment status: Active, On leave, Inactive.
 *
 * The WordPress role is never changed, so an employee's history stays
 * linked to them. Status is worked out for a given date:
 *  - On leave from a start date to an optional end date; after the end
 *    date the employee is active again without anyone touching it.
 *  - Inactive after the last day worked; before it they were active.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status rules and hooks.
 */
class Css_Tc_Status {

	const META_STATUS     = 'css_tc_status';
	const META_LEAVE_FROM = 'css_tc_leave_from';
	const META_LEAVE_TO   = 'css_tc_leave_to';
	const META_LAST_DAY   = 'css_tc_last_day';
	const META_LOG        = 'css_tc_status_log';
	const PROFILE_NONCE   = 'css_tc_status_profile';

	const ACTIVE   = 'active';
	const LEAVE    = 'leave';
	const INACTIVE = 'inactive';

	/**
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			self::ACTIVE   => __( 'Active', 'css-timeclock-addon' ),
			self::LEAVE    => __( 'On leave', 'css-timeclock-addon' ),
			self::INACTIVE => __( 'Inactive', 'css-timeclock-addon' ),
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * Pure rules (bin/check-status.php)
	 * ------------------------------------------------------------------
	 */

	/**
	 * Status on a date from stored values.
	 *
	 * @param array{status:string,leave_from:string,leave_to:string,last_day:string} $rec Stored values.
	 * @param string                                                             $date Y-m-d.
	 * @return string active|leave|inactive
	 */
	public static function status_on( $rec, $date ) {
		$status = (string) ( $rec['status'] ?? self::ACTIVE );
		if ( self::INACTIVE === $status ) {
			$last = (string) ( $rec['last_day'] ?? '' );
			return ( '' === $last || $date > $last ) ? self::INACTIVE : self::ACTIVE;
		}
		if ( self::LEAVE === $status ) {
			$from = (string) ( $rec['leave_from'] ?? '' );
			$to   = (string) ( $rec['leave_to'] ?? '' );
			if ( ( '' === $from || $date >= $from ) && ( '' === $to || $date <= $to ) ) {
				return self::LEAVE;
			}
		}
		return self::ACTIVE;
	}

	/**
	 * Check posted values.
	 *
	 * @param string $status     Status.
	 * @param string $leave_from Y-m-d or ''.
	 * @param string $leave_to   Y-m-d or ''.
	 * @param string $last_day   Y-m-d or ''.
	 * @param string $hire       Y-m-d or ''.
	 * @return string Error message, or '' when fine.
	 */
	public static function validate( $status, $leave_from, $leave_to, $last_day, $hire ) {
		if ( ! isset( self::labels()[ $status ] ) ) {
			return __( 'Choose a valid employment status.', 'css-timeclock-addon' );
		}
		foreach ( array( $leave_from, $leave_to, $last_day ) as $d ) {
			if ( '' !== $d && ! self::is_date( $d ) ) {
				return __( 'Employment status dates must be valid dates.', 'css-timeclock-addon' );
			}
		}
		if ( self::LEAVE === $status ) {
			if ( '' === $leave_from ) {
				return __( 'On leave needs a start date.', 'css-timeclock-addon' );
			}
			if ( '' !== $leave_to && $leave_to < $leave_from ) {
				return __( 'The leave end date is before the start date.', 'css-timeclock-addon' );
			}
		}
		if ( self::INACTIVE === $status ) {
			if ( '' === $last_day ) {
				return __( 'Inactive needs the last day worked.', 'css-timeclock-addon' );
			}
			if ( '' !== $hire && $last_day < $hire ) {
				return __( 'The last day worked is before the hire date.', 'css-timeclock-addon' );
			}
		}
		return '';
	}

	/**
	 * @param string $d Candidate.
	 * @return bool
	 */
	public static function is_date( $d ) {
		return (bool) ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) );
	}

	/*
	 * ------------------------------------------------------------------
	 * Stored values
	 * ------------------------------------------------------------------
	 */

	/**
	 * @param int $user_id Employee.
	 * @return array{status:string,leave_from:string,leave_to:string,last_day:string}
	 */
	public function record( $user_id ) {
		$status = (string) get_user_meta( (int) $user_id, self::META_STATUS, true );
		return array(
			'status'     => isset( self::labels()[ $status ] ) ? $status : self::ACTIVE,
			'leave_from' => (string) get_user_meta( (int) $user_id, self::META_LEAVE_FROM, true ),
			'leave_to'   => (string) get_user_meta( (int) $user_id, self::META_LEAVE_TO, true ),
			'last_day'   => (string) get_user_meta( (int) $user_id, self::META_LAST_DAY, true ),
		);
	}

	/**
	 * @param int    $user_id Employee.
	 * @param string $date    Y-m-d.
	 * @return string
	 */
	public function on( $user_id, $date ) {
		return self::status_on( $this->record( $user_id ), $date );
	}

	/**
	 * Status today.
	 *
	 * @param int $user_id Employee.
	 * @return string
	 */
	public function current( $user_id ) {
		return $this->on( $user_id, css_tc_addon()->time->site_today() );
	}

	/**
	 * Active today (may use the kiosk).
	 *
	 * @param int $user_id Employee.
	 * @return bool
	 */
	public function can_punch( $user_id ) {
		return self::ACTIVE === $this->current( $user_id );
	}

	/**
	 * Short text for lists: "On leave until Oct 30", "Inactive (last day Sep 12)".
	 *
	 * @param int $user_id Employee.
	 * @return string
	 */
	public function describe( $user_id ) {
		$rec   = $this->record( $user_id );
		$today = css_tc_addon()->time->site_today();
		$now   = self::status_on( $rec, $today );
		if ( self::LEAVE === $now ) {
			return '' !== $rec['leave_to']
				/* translators: %s: date */
				? sprintf( __( 'On leave until %s', 'css-timeclock-addon' ), css_tc_leave_date_label( $rec['leave_to'] ) )
				: __( 'On leave', 'css-timeclock-addon' );
		}
		if ( self::INACTIVE === $now ) {
			/* translators: %s: date */
			return sprintf( __( 'Inactive (last day %s)', 'css-timeclock-addon' ), css_tc_leave_date_label( $rec['last_day'] ) );
		}
		if ( self::LEAVE === $rec['status'] && '' !== $rec['leave_from'] && $rec['leave_from'] > $today ) {
			/* translators: %s: date */
			return sprintf( __( 'Active · leave starts %s', 'css-timeclock-addon' ), css_tc_leave_date_label( $rec['leave_from'] ) );
		}
		if ( self::INACTIVE === $rec['status'] && '' !== $rec['last_day'] ) {
			/* translators: %s: date */
			return sprintf( __( 'Active · last day %s', 'css-timeclock-addon' ), css_tc_leave_date_label( $rec['last_day'] ) );
		}
		return __( 'Active', 'css-timeclock-addon' );
	}

	/**
	 * Why a date gets no automatic holiday pay / cannot be requested, or ''.
	 *
	 * @param int    $user_id Employee.
	 * @param string $date    Y-m-d.
	 * @return string
	 */
	public function blocked_reason( $user_id, $date ) {
		$s = $this->on( $user_id, $date );
		if ( self::LEAVE === $s ) {
			return __( 'on leave', 'css-timeclock-addon' );
		}
		if ( self::INACTIVE === $s ) {
			return __( 'after last day worked', 'css-timeclock-addon' );
		}
		return '';
	}

	/*
	 * ------------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------------
	 */

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'user_profile_update_errors', array( $this, 'validate_profile' ), 10, 3 );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
		add_filter( 'wp_authenticate_user', array( $this, 'block_inactive_login' ), 20 );
		add_action( 'admin_notices', array( $this, 'open_shift_notice' ) );
	}

	/**
	 * Inactive employees cannot sign in. Managers are never locked out here.
	 *
	 * @param WP_User|WP_Error $user User.
	 * @return WP_User|WP_Error
	 */
	public function block_inactive_login( $user ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}
		if ( user_can( $user, 'manage_options' ) || user_can( $user, Css_Tc_Plugin::MANAGE_CAP ) ) {
			return $user;
		}
		if ( css_tc_addon()->employees->is_employee( (int) $user->ID ) && self::INACTIVE === $this->current( (int) $user->ID ) ) {
			return new WP_Error( 'css_tc_inactive', __( 'This account is no longer active. Please contact your manager.', 'css-timeclock-addon' ) );
		}
		return $user;
	}

	/**
	 * @return array{status:string,leave_from:string,leave_to:string,last_day:string,note:string}
	 */
	private function posted() {
		$get = static function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		};
		$status = sanitize_key( $get( 'css_tc_status' ) );
		return array(
			'status'     => '' === $status ? self::ACTIVE : $status,
			'leave_from' => self::LEAVE === $status ? $get( 'css_tc_leave_from' ) : '',
			'leave_to'   => self::LEAVE === $status ? $get( 'css_tc_leave_to' ) : '',
			'last_day'   => self::INACTIVE === $status ? $get( 'css_tc_last_day' ) : '',
			'note'       => substr( $get( 'css_tc_status_note' ), 0, 200 ),
		);
	}

	/**
	 * @param WP_Error $errors Errors.
	 * @param bool     $update Update.
	 * @param object   $user   User.
	 * @return void
	 */
	public function validate_profile( $errors, $update, $user ) {
		unset( $update );
		if ( ! isset( $_POST['css_tc_status_present'] ) || ! Css_Tc_Plugin::user_can_manage() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$p    = $this->posted();
		$hire = isset( $_POST['css_tc_hire_date'] ) ? sanitize_text_field( wp_unslash( $_POST['css_tc_hire_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$err  = self::validate( $p['status'], $p['leave_from'], $p['leave_to'], $p['last_day'], $hire );
		if ( '' === $err && isset( $user->ID ) && (int) $user->ID === get_current_user_id() && self::INACTIVE === $p['status'] ) {
			$err = __( 'You cannot mark your own account inactive.', 'css-timeclock-addon' );
		}
		if ( '' !== $err ) {
			$errors->add( 'css_tc_status', $err );
		}
	}

	/**
	 * @param int $user_id Profile user.
	 * @return void
	 */
	public function save_profile( $user_id ) {
		if ( ! Css_Tc_Plugin::user_can_manage() || ! isset( $_POST['css_tc_status_present'] ) ) {
			return;
		}
		check_admin_referer( self::PROFILE_NONCE, 'css_tc_status_nonce' );
		$p    = $this->posted();
		$hire = isset( $_POST['css_tc_hire_date'] ) ? sanitize_text_field( wp_unslash( $_POST['css_tc_hire_date'] ) ) : '';
		if ( '' !== self::validate( $p['status'], $p['leave_from'], $p['leave_to'], $p['last_day'], $hire ) ) {
			return;
		}
		if ( (int) $user_id === get_current_user_id() && self::INACTIVE === $p['status'] ) {
			return;
		}
		$before = $this->record( $user_id );
		$after  = array(
			'status'     => $p['status'],
			'leave_from' => $p['leave_from'],
			'leave_to'   => $p['leave_to'],
			'last_day'   => $p['last_day'],
		);
		if ( $before === $after && '' === $p['note'] ) {
			return;
		}
		update_user_meta( (int) $user_id, self::META_STATUS, $after['status'] );
		foreach ( array( 'leave_from' => self::META_LEAVE_FROM, 'leave_to' => self::META_LEAVE_TO, 'last_day' => self::META_LAST_DAY ) as $k => $meta ) {
			if ( '' === $after[ $k ] ) {
				delete_user_meta( (int) $user_id, $meta );
			} else {
				update_user_meta( (int) $user_id, $meta, $after[ $k ] );
			}
		}
		$log   = get_user_meta( (int) $user_id, self::META_LOG, true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array_merge(
			$after,
			array(
				'note' => $p['note'],
				'by'   => get_current_user_id(),
				'at'   => time(),
			)
		);
		update_user_meta( (int) $user_id, self::META_LOG, array_slice( $log, -50 ) );
		if ( self::ACTIVE !== self::status_on( $after, css_tc_addon()->time->site_today() ) && ! empty( css_tc_addon()->punches->open_shift_for( (int) $user_id )['is_clocked_in'] ) ) {
			set_transient( 'css_tc_status_open_' . get_current_user_id(), (int) $user_id, 120 );
		}
	}

	/**
	 * Warn when someone just marked on leave / inactive is still clocked in.
	 *
	 * @return void
	 */
	public function open_shift_notice() {
		$key = 'css_tc_status_open_' . get_current_user_id();
		$uid = (int) get_transient( $key );
		if ( $uid < 1 ) {
			return;
		}
		delete_transient( $key );
		$url = Css_Tc_Admin::timecards_url( array( 'employee' => $uid ) );
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %s: employee */
				__( '%s is still clocked in. Close the open shift on their timecard so their hours are right.', 'css-timeclock-addon' ),
				css_tc_addon()->employees->display_name( $uid )
			)
		) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Open timecard', 'css-timeclock-addon' ) . '</a></p></div>';
	}

	/**
	 * Change history for the profile.
	 *
	 * @param int $user_id Employee.
	 * @return array<int,array<string,mixed>>
	 */
	public function log( $user_id ) {
		$log = get_user_meta( (int) $user_id, self::META_LOG, true );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}
}
