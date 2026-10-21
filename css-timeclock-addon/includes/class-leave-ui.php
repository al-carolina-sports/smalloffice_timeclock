<?php
/**
 * PTO & sick screens: TC-Config tab, manager Time off page, profile
 * section, and the employee request endpoints.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks and handlers around Css_Tc_Leave.
 */
class Css_Tc_Leave_Ui {

	const PAGE                = 'css-tc-timeoff';
	const ADMIN_NONCE         = 'css_tc_leave_admin';
	const PROFILE_NONCE       = 'css_tc_leave_profile';
	const EMPLOYEE_EDIT_NONCE = 'css_tc_leave_employee';

	/**
	 * @return void
	 */
	public static function register() {
		$self = new self();
		add_action( 'admin_menu', array( $self, 'add_menu' ), 26 );
		add_action( 'admin_post_css_tc_leave_settings', array( $self, 'save_settings' ) );
		add_action( 'admin_post_css_tc_leave_decide', array( $self, 'decide' ) );
		add_action( 'admin_post_css_tc_leave_add', array( $self, 'add' ) );
		add_action( 'admin_post_css_tc_leave_csv', array( $self, 'csv' ) );
		add_action( 'admin_post_css_tc_leave_employee', array( $self, 'save_employee' ) );
		add_action( 'show_user_profile', array( $self, 'render_profile' ), 6 );
		add_action( 'edit_user_profile', array( $self, 'render_profile' ), 6 );
		add_action( 'user_profile_update_errors', array( $self, 'validate_profile' ), 10, 3 );
		add_action( 'personal_options_update', array( $self, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $self, 'save_profile' ) );
		add_action( 'wp_ajax_css_tc_leave_state', array( $self, 'ajax_state' ) );
		add_action( 'wp_ajax_css_tc_leave_request', array( $self, 'ajax_request' ) );
		add_action( 'wp_ajax_css_tc_leave_cancel', array( $self, 'ajax_cancel' ) );
	}

	/**
	 * @return Css_Tc_Leave
	 */
	private function leave() {
		return css_tc_addon()->leave;
	}

	/**
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * @return void
	 */
	public function add_menu() {
		if ( ! Css_Tc_Plugin::aio_is_active() ) {
			add_options_page( __( 'Time off', 'css-timeclock-addon' ), __( 'Time off', 'css-timeclock-addon' ), 'manage_options', self::PAGE, array( $this, 'render_page' ) );
			return;
		}
		add_submenu_page( 'aio-tc-lite', __( 'Time off', 'css-timeclock-addon' ), __( 'Time off', 'css-timeclock-addon' ), Css_Tc_Plugin::admin_capability(), self::PAGE, array( $this, 'render_page' ) );
	}

	/**
	 * @return void
	 */
	private function require_manager() {
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			wp_die( esc_html__( 'Only time clock managers can do that.', 'css-timeclock-addon' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * @param string $url     Base.
	 * @param string $message Notice.
	 * @param bool   $error   Error notice.
	 * @return void
	 */
	private function back( $url, $message, $error = false ) {
		set_transient( 'css_tc_leave_notice_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), 60 );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * One-time notice after a redirect.
	 *
	 * @return array{message:string,error:bool}|null
	 */
	public static function take_notice() {
		$key    = 'css_tc_leave_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			return $notice;
		}
		return null;
	}

	/**
	 * Hours text box → seconds, or null when unreadable.
	 *
	 * @param mixed $raw Value.
	 * @return float|null Hours.
	 */
	private static function hours_in( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( ! is_numeric( $raw ) ) {
			return -1.0;
		}
		return (float) $raw;
	}

	/*
	 * ------------------------------------------------------------------
	 * TC-Config → PTO & sick
	 * ------------------------------------------------------------------
	 */

	/**
	 * @return void
	 */
	public function save_settings() {
		$this->require_manager();
		check_admin_referer( self::ADMIN_NONCE );
		$url = Css_Tc_Admin::settings_url( 'leave' );
		$s   = css_tc_addon()->get_settings();

		$num = static function ( $key, $min, $max, $allow_blank = false ) {
			$raw = isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $raw && $allow_blank ) {
				return '';
			}
			if ( ! is_numeric( $raw ) || (float) $raw < $min || (float) $raw > $max ) {
				return null;
			}
			return round( (float) $raw, 2 );
		};

		$fields = array(
			'pto_hours_year'        => array( 0, 2000, false, __( 'PTO hours per year', 'css-timeclock-addon' ) ),
			'pto_first_year_hours'  => array( 0, 2000, false, __( 'First-year PTO', 'css-timeclock-addon' ) ),
			'sick_hours_year'       => array( 0, 2000, false, __( 'Sick hours per year', 'css-timeclock-addon' ) ),
			'sick_first_year_hours' => array( 0, 2000, true, __( 'First-year sick hours', 'css-timeclock-addon' ) ),
			'pto_notice_days'       => array( 0, 365, false, __( 'Days ahead for PTO', 'css-timeclock-addon' ) ),
			'leave_day_hours'       => array( 1, 24, false, __( 'Length of a day off', 'css-timeclock-addon' ) ),
		);
		foreach ( $fields as $key => $f ) {
			$v = $num( $key, $f[0], $f[1], $f[2] );
			if ( null === $v ) {
				/* translators: %s: field name */
				$this->back( $url, sprintf( __( '%s: enter a number in range.', 'css-timeclock-addon' ), $f[3] ), true );
			}
			$s[ $key ] = $v;
		}
		$s['pto_notice_days']      = (int) $s['pto_notice_days'];
		$s['pto_enabled']          = empty( $_POST['pto_enabled'] ) ? 0 : 1;
		$s['sick_enabled']         = empty( $_POST['sick_enabled'] ) ? 0 : 1;
		$s['sick_from_pto']        = empty( $_POST['sick_from_pto'] ) ? 0 : 1;
		$s['leave_allow_negative'] = empty( $_POST['leave_allow_negative'] ) ? 0 : 1;
		$inc                       = isset( $_POST['leave_increment'] ) ? absint( $_POST['leave_increment'] ) : 60;
		$s['leave_increment']      = in_array( $inc, array( 15, 30, 60 ), true ) ? $inc : 60;
		css_tc_addon()->update_settings( $s );
		$this->back( $url, __( 'PTO & sick settings saved.', 'css-timeclock-addon' ) );
	}

	/*
	 * ------------------------------------------------------------------
	 * Manager Time off page
	 * ------------------------------------------------------------------
	 */

	/**
	 * @return void
	 */
	public function render_page() {
		$this->require_manager();
		$leave     = $this->leave();
		$today     = $leave->today();
		$notice    = self::take_notice();
		$pending   = Css_Tc_Leave::group( $leave->records( 0, '', '', array( 'pending' ) ) );
		$recent    = array_slice( Css_Tc_Leave::group( $leave->records( 0, Css_Tc_Leave::add_days( $today, -120 ), '', array( 'approved', 'denied', 'cancelled' ) ) ), 0, 40 );
		$employees = css_tc_addon()->employees->list_for_admin();
		usort(
			$employees,
			static function ( $a, $b ) {
				return strcasecmp( css_tc_addon()->employees->display_name( (int) $a->ID ), css_tc_addon()->employees->display_name( (int) $b->ID ) );
			}
		);
		$out_from = ( new DateTimeImmutable( $today, new DateTimeZone( 'UTC' ) ) )->modify( 'monday this week' )->format( 'Y-m-d' );
		$out_to   = Css_Tc_Leave::add_days( $out_from, 34 );
		$out_rows = $leave->records( 0, $out_from, $out_to, array( 'approved', 'pending' ) );
		$pto_choices = array();
		foreach ( $employees as $candidate ) {
			if ( in_array( 'administrator', (array) $candidate->roles, true ) ) {
				continue;
			}
			$pto_choices[] = $candidate;
		}
		$pto_selected   = isset( $_GET['employee'] ) ? absint( wp_unslash( $_GET['employee'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pto_editor     = '';
		$pto_pick_error = '';
		if ( $pto_selected > 0 ) {
			$found = null;
			foreach ( $pto_choices as $candidate ) {
				if ( (int) $candidate->ID === $pto_selected ) {
					$found = $candidate;
					break;
				}
			}
			if ( ! $found || ! self::actor_may_edit( $pto_selected ) ) {
				$pto_pick_error = __( 'Choose an employee. Site administrators are not edited here.', 'css-timeclock-addon' );
			} else {
				$pto_editor = $this->editor_html( $found, false, true );
			}
		}
		include CSS_TC_ADDON_DIR . 'admin/views/timeoff-page.php';
	}

	/**
	 * Save hire date, allowances, and a balance adjustment from Time off.
	 *
	 * @return void
	 */
	public function save_employee() {
		$this->require_manager();
		check_admin_referer( self::EMPLOYEE_EDIT_NONCE );
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$url     = add_query_arg( 'employee', $user_id, self::page_url() );
		if ( ! self::actor_may_edit( $user_id ) ) {
			$this->back( self::page_url(), __( 'Choose an employee. Site administrators are not edited here.', 'css-timeclock-addon' ), true );
		}
		$result = $this->apply_pto( $user_id, true );
		if ( is_wp_error( $result ) ) {
			$this->back( $url, $result->get_error_message(), true );
		}
		$this->back(
			$url,
			sprintf(
				/* translators: %s: employee */
				__( 'Time off saved for %s.', 'css-timeclock-addon' ),
				css_tc_addon()->employees->display_name( $user_id )
			)
		);
	}

	/**
	 * Approve / deny / cancel.
	 *
	 * @return void
	 */
	public function decide() {
		$this->require_manager();
		check_admin_referer( self::ADMIN_NONCE );
		$group  = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '';
		$do     = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$map    = array( 'approve' => 'approved', 'deny' => 'denied', 'cancel' => 'cancelled' );
		if ( ! isset( $map[ $do ] ) ) {
			$this->back( self::page_url(), __( 'Unknown action.', 'css-timeclock-addon' ), true );
		}
		if ( 'cancel' === $do && '' === trim( $reason ) ) {
			$this->back( self::page_url(), __( 'Give a reason for cancelling approved time off.', 'css-timeclock-addon' ), true );
		}
		$result = $this->leave()->decide( $group, $map[ $do ], get_current_user_id(), $reason );
		if ( is_wp_error( $result ) ) {
			$this->back( self::page_url(), $result->get_error_message(), true );
		}
		$msg = array(
			'approve' => __( 'Time off approved.', 'css-timeclock-addon' ),
			'deny'    => __( 'Request denied.', 'css-timeclock-addon' ),
			'cancel'  => __( 'Time off cancelled.', 'css-timeclock-addon' ),
		);
		$this->back( self::page_url(), $msg[ $do ] );
	}

	/**
	 * Days between two dates, optionally without weekends.
	 *
	 * @param string $from          Y-m-d.
	 * @param string $to            Y-m-d.
	 * @param bool   $skip_weekends Skip Sat/Sun.
	 * @return string[]
	 */
	public static function date_span( $from, $to, $skip_weekends ) {
		$out = array();
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
			return $out;
		}
		$d = $from;
		for ( $i = 0; $i < 62 && $d <= $to; $i++ ) {
			$dow = (int) ( new DateTimeImmutable( $d, new DateTimeZone( 'UTC' ) ) )->format( 'N' );
			if ( ! $skip_weekends || $dow < 6 ) {
				$out[] = $d;
			}
			$d = Css_Tc_Leave::add_days( $d, 1 );
		}
		return $out;
	}

	/**
	 * Manager adds time off for an employee (approved, needs agreement).
	 *
	 * @return void
	 */
	public function add() {
		$this->require_manager();
		check_admin_referer( self::ADMIN_NONCE );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$from    = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to      = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$to      = '' === $to ? $from : $to;
		$hours   = self::hours_in( isset( $_POST['hours'] ) ? wp_unslash( $_POST['hours'] ) : '' );
		$note    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$agreed  = ! empty( $_POST['agreed'] );
		$skip    = ! empty( $_POST['skip_weekends'] );

		if ( ! css_tc_addon()->employees->is_employee( $user_id ) ) {
			$this->back( self::page_url(), __( 'Choose an employee.', 'css-timeclock-addon' ), true );
		}
		$dates = self::date_span( $from, $to, $skip );
		if ( empty( $dates ) ) {
			$this->back( self::page_url(), __( 'Choose the first and last day.', 'css-timeclock-addon' ), true );
		}
		if ( null === $hours || $hours <= 0 ) {
			$this->back( self::page_url(), __( 'Enter the hours per day.', 'css-timeclock-addon' ), true );
		}
		$days = array();
		foreach ( $dates as $d ) {
			$days[ $d ] = (int) round( $hours * 3600 );
		}
		$result = $this->leave()->create( $user_id, $days, $type, $note, get_current_user_id(), true, $agreed );
		if ( is_wp_error( $result ) ) {
			$this->back( self::page_url(), $result->get_error_message(), true );
		}
		$this->back(
			self::page_url(),
			sprintf(
				/* translators: 1: type, 2: hours, 3: employee */
				__( '%1$s added: %2$s for %3$s.', 'css-timeclock-addon' ),
				Css_Tc_Leave::label( $type ),
				Css_Tc_Leave::hours( array_sum( $days ) ),
				css_tc_addon()->employees->display_name( $user_id )
			)
		);
	}

	/**
	 * Balances CSV.
	 *
	 * @return void
	 */
	public function csv() {
		$this->require_manager();
		check_admin_referer( self::ADMIN_NONCE );
		$leave = $this->leave();
		$head  = array( 'Employee', 'Hire date', 'Leave year start', 'Leave year end', 'Usable from' );
		foreach ( $leave->banks() as $bank ) {
			$l      = 'pto' === $bank ? 'PTO' : 'Sick';
			$head[] = "$l allowance (hours)";
			$head[] = "$l adjustments (hours)";
			$head[] = "$l used (hours)";
			$head[] = "$l pending (hours)";
			$head[] = "$l left (hours)";
		}
		$lines = array( $head );
		foreach ( css_tc_addon()->employees->list_for_admin() as $user ) {
			$b    = $leave->balances( (int) $user->ID );
			$line = array( css_tc_addon()->employees->display_name( (int) $user->ID ), $b['hire'], $b['cycle']['start'] ?? '', $b['cycle']['end'] ?? '', $b['usable_from'] );
			foreach ( $leave->banks() as $bank ) {
				$t = $b['banks'][ $bank ] ?? array( 'allowance' => 0, 'adjust' => 0, 'used' => 0, 'pending' => 0, 'left' => 0 );
				foreach ( array( 'allowance', 'adjust', 'used', 'pending', 'left' ) as $k ) {
					$line[] = Css_Tc_Reports::decimal_hours( (int) $t[ $k ] );
				}
			}
			$lines[] = $line;
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="time-off-balances-' . $leave->today() . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		foreach ( $lines as $line ) {
			fputcsv( $out, array_map( array( 'Css_Tc_Reports', 'csv_cell' ), $line ), ',', '"', '' );
		}
		fclose( $out );
		exit;
	}

	/*
	 * ------------------------------------------------------------------
	 * Profile
	 * ------------------------------------------------------------------
	 */

	/**
	 * Manager or administrator, and the target is a time-clock employee who is not a site administrator.
	 *
	 * Does not require edit_users. Includes the manager's own record (edited under Time off).
	 *
	 * @param int $user_id Target user.
	 * @return bool
	 */
	public static function actor_may_edit( $user_id ) {
		$user_id  = (int) $user_id;
		$user     = get_userdata( $user_id );
		$is_admin = $user && in_array( 'administrator', (array) $user->roles, true );
		return Css_Tc_Leave::may_edit_pto(
			Css_Tc_Plugin::user_can_manage(),
			(int) get_current_user_id(),
			$user_id,
			css_tc_addon()->employees->is_employee( $user_id ),
			$is_admin
		);
	}

	/**
	 * Profile save is allowed for another employee only.
	 *
	 * @param int $user_id Profile user.
	 * @return bool
	 */
	public static function actor_may_save_profile( $user_id ) {
		$user_id  = (int) $user_id;
		$user     = get_userdata( $user_id );
		$is_admin = $user && in_array( 'administrator', (array) $user->roles, true );
		return Css_Tc_Leave::may_save_profile_pto(
			Css_Tc_Plugin::user_can_manage(),
			(int) get_current_user_id(),
			$user_id,
			css_tc_addon()->employees->is_employee( $user_id ),
			$is_admin
		);
	}

	/**
	 * Allowance keys stored on the user, blank meaning "use the default".
	 *
	 * @param array<string,mixed> $overrides Stored or posted values.
	 * @return array<string,string>
	 */
	private static function normalize_overrides( $overrides ) {
		$out = array();
		foreach ( array( 'pto_year', 'pto_first', 'sick_year', 'sick_first' ) as $key ) {
			$out[ $key ] = isset( $overrides[ $key ] ) ? (string) $overrides[ $key ] : '';
		}
		return $out;
	}

	/**
	 * Shared Time off / profile markup.
	 *
	 * @param WP_User $user            Employee.
	 * @param bool    $readonly        Balances only.
	 * @param bool    $show_hire_input Hire date field (Time off page).
	 * @return string
	 */
	private function editor_html( $user, $readonly, $show_hire_input ) {
		$leave           = $this->leave();
		$user_id         = (int) $user->ID;
		$overrides       = $leave->overrides( $user_id );
		$balances        = $leave->balances( $user_id );
		$adjustments     = array_reverse( $leave->adjustments( $user_id ) );
		$settings        = css_tc_addon()->get_settings();
		$hire            = css_tc_addon()->holidays->hire_date( $user_id );
		$readonly        = (bool) $readonly;
		$show_hire_input = (bool) $show_hire_input && ! $readonly;
		$audit           = $readonly ? array() : $leave->audit_log( $user_id );
		ob_start();
		include CSS_TC_ADDON_DIR . 'admin/views/profile-leave.php';
		return (string) ob_get_clean();
	}

	/**
	 * Write allowances, an optional adjustment, and (from Time off) the hire date.
	 *
	 * Caller has already checked the nonce and actor_may_edit().
	 *
	 * @param int  $user_id   Employee.
	 * @param bool $with_hire Read css_tc_hire_date from the request.
	 * @return true|WP_Error
	 */
	private function apply_pto( $user_id, $with_hire ) {
		$user_id = (int) $user_id;
		if ( ! self::actor_may_edit( $user_id ) ) {
			return new WP_Error( 'css_tc_leave_forbidden', __( 'You cannot change that person\'s time off.', 'css-timeclock-addon' ) );
		}
		$p = $this->posted_profile();
		if ( '' !== $p['error'] ) {
			return new WP_Error( 'css_tc_leave', $p['error'] );
		}
		$leave      = $this->leave();
		$hire_after = null;
		// A missing hire field must not clear the date. An empty field does.
		if ( $with_hire && isset( $_POST['css_tc_hire_date'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$hire_after = sanitize_text_field( wp_unslash( $_POST['css_tc_hire_date'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$check      = css_tc_addon()->holidays->validate_hire_date( $hire_after );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		$hire_now       = css_tc_addon()->holidays->hire_date( $user_id );
		$hire_effective = null !== $hire_after ? $hire_after : $hire_now;
		$adjusting      = null !== $p['adjust'] && 0.0 !== (float) $p['adjust'];
		if ( $adjusting && '' === $hire_effective ) {
			return new WP_Error( 'css_tc_leave_adjust', __( 'Set a hire date first.', 'css-timeclock-addon' ) );
		}
		if ( $adjusting && ! in_array( $p['adjust_bank'], $leave->banks(), true ) ) {
			return new WP_Error( 'css_tc_leave_adjust', __( 'That balance is not in use.', 'css-timeclock-addon' ) );
		}

		$changes = array();
		if ( null !== $hire_after && $hire_after !== $hire_now ) {
			$saved = css_tc_addon()->holidays->set_hire_date( $user_id, $hire_after );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$changes[] = array(
				'field'  => 'hire',
				'before' => $hire_now,
				'after'  => $hire_after,
			);
		}

		$before = self::normalize_overrides( $leave->overrides( $user_id ) );
		$after  = $before;
		// Fields that were not on the form (for example while that bank is off) stay as they are.
		foreach ( array( 'pto_year', 'pto_first', 'sick_year', 'sick_first' ) as $key ) {
			if ( isset( $_POST[ 'css_tc_leave_' . $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$after[ $key ] = (string) ( $p['overrides'][ $key ] ?? '' );
			}
		}
		if ( $before !== $after ) {
			update_user_meta( $user_id, Css_Tc_Leave::META_ALLOW, $after );
			foreach ( $after as $key => $value ) {
				if ( (string) $before[ $key ] !== (string) $value ) {
					$changes[] = array(
						'field'  => $key,
						'before' => (string) $before[ $key ],
						'after'  => (string) $value,
					);
				}
			}
		}

		if ( $adjusting ) {
			$bank        = $p['adjust_bank'];
			$bal_before  = $leave->balances( $user_id );
			$left_before = isset( $bal_before['banks'][ $bank ] ) ? (int) $bal_before['banks'][ $bank ]['left'] : 0;
			$seconds     = (int) round( (float) $p['adjust'] * 3600 );
			$result      = $leave->adjust( $user_id, $bank, $seconds, $p['reason'], get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$bal_after  = $leave->balances( $user_id );
			$left_after = isset( $bal_after['banks'][ $bank ] ) ? (int) $bal_after['banks'][ $bank ]['left'] : 0;
			$changes[]  = array(
				'field'  => 'adjust_' . $bank,
				'before' => Css_Tc_Leave::hours( $left_before ),
				'after'  => Css_Tc_Leave::hours( $left_after ),
				'reason' => $p['reason'],
			);
		}

		$leave->record_audit( $user_id, $changes, get_current_user_id() );
		return true;
	}

	/**
	 * @param WP_User $user Profile user.
	 * @return void
	 */
	public function render_profile( $user ) {
		$user_id = (int) $user->ID;
		if ( ! css_tc_addon()->employees->is_employee( $user_id ) || ! $this->leave()->any_enabled() ) {
			return;
		}
		$self = $user_id === (int) get_current_user_id();
		// Other people's profiles are manager-only. Your own profile is read-only.
		if ( ! $self && ! self::actor_may_save_profile( $user_id ) ) {
			return;
		}
		$readonly = $self || ! self::actor_may_edit( $user_id );
		echo $this->editor_html( $user, $readonly, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @return array{overrides:array<string,string>,adjust_bank:string,adjust:float|null,reason:string,error:string}
	 */
	private function posted_profile() {
		$over  = array();
		$error = '';
		foreach ( array( 'pto_year', 'pto_first', 'sick_year', 'sick_first' ) as $k ) {
			$h = self::hours_in( isset( $_POST[ 'css_tc_leave_' . $k ] ) ? wp_unslash( $_POST[ 'css_tc_leave_' . $k ] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( null === $h ) {
				$over[ $k ] = '';
			} elseif ( $h < 0 || $h > 2000 ) {
				$error = __( 'Time off hours must be numbers from 0 to 2000, or blank for the default.', 'css-timeclock-addon' );
			} else {
				$over[ $k ] = (string) round( $h, 2 );
			}
		}
		$adjust = self::hours_in( isset( $_POST['css_tc_leave_adjust'] ) ? wp_unslash( $_POST['css_tc_leave_adjust'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_adjust = isset( $_POST['css_tc_leave_adjust'] ) ? trim( (string) wp_unslash( $_POST['css_tc_leave_adjust'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' !== $raw_adjust && ! is_numeric( $raw_adjust ) ) {
			$error = __( 'Balance adjustment must be a number of hours, like 4 or -2.', 'css-timeclock-addon' );
		}
		$reason = isset( $_POST['css_tc_leave_adjust_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['css_tc_leave_adjust_reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' !== $raw_adjust && is_numeric( $raw_adjust ) && 0.0 !== (float) $raw_adjust && '' === $reason ) {
			$error = __( 'Give a reason for the balance adjustment.', 'css-timeclock-addon' );
		}
		return array(
			'overrides'   => $over,
			'adjust_bank' => isset( $_POST['css_tc_leave_adjust_bank'] ) ? sanitize_key( wp_unslash( $_POST['css_tc_leave_adjust_bank'] ) ) : 'pto', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'adjust'      => '' === $raw_adjust ? null : (float) $raw_adjust,
			'reason'      => $reason,
			'error'       => $error,
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
		if ( ! isset( $_POST['css_tc_leave_present'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$user_id = isset( $user->ID ) ? (int) $user->ID : 0;
		// Own profile and anyone who is not a manager: the fields are ignored on save.
		if ( ! self::actor_may_save_profile( $user_id ) ) {
			return;
		}
		$p = $this->posted_profile();
		if ( '' !== $p['error'] ) {
			$errors->add( 'css_tc_leave', $p['error'] );
		} elseif ( null !== $p['adjust'] && 0.0 !== (float) $p['adjust'] && ! in_array( $p['adjust_bank'], $this->leave()->banks(), true ) ) {
			$errors->add( 'css_tc_leave', __( 'That balance is not in use.', 'css-timeclock-addon' ) );
		}
	}

	/**
	 * @param int $user_id Profile user.
	 * @return void
	 */
	public function save_profile( $user_id ) {
		// Employees, and a manager saving their own profile, cannot change PTO here.
		if ( ! isset( $_POST['css_tc_leave_present'] ) || ! self::actor_may_save_profile( (int) $user_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		check_admin_referer( self::PROFILE_NONCE, 'css_tc_leave_nonce' );
		$this->apply_pto( (int) $user_id, false );
	}

	/*
	 * ------------------------------------------------------------------
	 * Employee endpoints (My Time Clock → Request time off)
	 * ------------------------------------------------------------------
	 */

	/**
	 * @return int Employee ID.
	 */
	private function employee_or_die() {
		if ( ! check_ajax_referer( Css_Tc_Corrections::EMPLOYEE_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page expired. Reload and try again.', 'css-timeclock-addon' ) ), 403 );
		}
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! css_tc_addon()->employees->can_view_own_times( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sign in with your employee account.', 'css-timeclock-addon' ) ), 403 );
		}
		// Requests and cancellations are only for the signed-in employee.
		if ( isset( $_POST['user_id'] ) && '' !== (string) wp_unslash( $_POST['user_id'] ) && absint( wp_unslash( $_POST['user_id'] ) ) !== $user_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_send_json_error( array( 'message' => __( 'You can only change your own time-off requests.', 'css-timeclock-addon' ) ), 403 );
		}
		// Allowance, adjustment, and hire-date fields are manager-only. Drop them
		// so a time-off request cannot carry a balance change.
		foreach ( array( 'css_tc_leave_pto_year', 'css_tc_leave_pto_first', 'css_tc_leave_sick_year', 'css_tc_leave_sick_first', 'css_tc_leave_adjust', 'css_tc_leave_adjust_bank', 'css_tc_leave_adjust_reason', 'css_tc_leave_present', 'css_tc_hire_date', 'css_tc_employment_present' ) as $blocked ) {
			unset( $_POST[ $blocked ], $_REQUEST[ $blocked ] );
		}
		if ( ! $this->leave()->any_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Time off requests are not turned on.', 'css-timeclock-addon' ) ), 403 );
		}
		return $user_id;
	}

	/**
	 * Everything the request calendar needs.
	 *
	 * @param int $user_id Employee.
	 * @return array<string,mixed>
	 */
	public function state( $user_id ) {
		$leave = $this->leave();
		$ctx   = $leave->context( $user_id );
		$bal   = $leave->balances( $user_id, '', $ctx );
		$today = $leave->today();
		$banks = array();
		foreach ( $bal['banks'] as $bank => $t ) {
			$banks[] = array(
				'bank'      => $bank,
				'label'     => 'pto' === $bank ? ( $leave->one_bank() ? __( 'PTO (incl. sick)', 'css-timeclock-addon' ) : __( 'PTO', 'css-timeclock-addon' ) ) : __( 'Sick', 'css-timeclock-addon' ),
				'allowance' => $t['allowance'] + $t['adjust'],
				'used'      => $t['used'],
				'pending'   => $t['pending'],
				'left'      => $t['left'],
			);
		}
		$holidays = array();
		foreach ( css_tc_addon()->holidays->for_employee( $user_id, Css_Tc_Leave::add_days( $today, -62 ), Css_Tc_Leave::add_days( $today, 400 ) ) as $d => $info ) {
			$holidays[ $d ] = implode( ' · ', $info['names'] );
		}
		$mine    = array();
		$records = $leave->records( $user_id, Css_Tc_Leave::add_days( $today, -120 ), '' );
		foreach ( Css_Tc_Leave::group( $records ) as $g ) {
			$dates      = array_keys( $g['days'] );
			$can_cancel = 'pending' === $g['status'] || ( 'approved' === $g['status'] && max( $dates ) > $today );
			$mine[]     = array(
				'group'      => $g['group'],
				'type'       => $g['type'],
				'label'      => Css_Tc_Leave::label( $g['type'] ),
				'status'     => $g['status'],
				'when'       => Css_Tc_Leave::describe_days( $dates ),
				'hours'      => Css_Tc_Leave::hours( $g['seconds'] ),
				'note'       => $g['note'],
				'reason'     => $g['reason'],
				'source'     => $g['source'],
				'can_cancel' => in_array( $g['status'], array( 'pending', 'approved' ), true ) && $can_cancel,
			);
		}
		$days = array();
		foreach ( $records as $r ) {
			if ( in_array( $r['status'], array( 'pending', 'approved' ), true ) ) {
				$days[ $r['date'] ] = array(
					'type'   => $r['type'],
					'status' => $r['status'],
					'hours'  => Css_Tc_Leave::hours( $r['seconds'] ),
				);
			}
		}
		$types = array();
		foreach ( Css_Tc_Leave::TYPES as $t ) {
			if ( $leave->type_enabled( $t ) ) {
				$types[] = array( 'type' => $t, 'label' => Css_Tc_Leave::label( $t ) );
			}
		}
		return array(
			'today'        => $today,
			'hire'         => $ctx['hire'],
			'usable_from'  => $ctx['usable_from'],
			'notice_from'  => $leave->notice_cutoff(),
			'notice_days'  => $leave->notice_days(),
			'period_start' => $ctx['period_start'],
			'status'       => $ctx['status'],
			'cycle'        => $bal['cycle'],
			'cycle_label'  => $bal['cycle'] ? css_tc_leave_date_label( $bal['cycle']['start'] ) . ' – ' . css_tc_leave_date_label( $bal['cycle']['end'] ) : '',
			'banks'        => $banks,
			'one_bank'     => $leave->one_bank(),
			'types'        => $types,
			'day_seconds'  => $leave->day_seconds(),
			'step_seconds' => $leave->increment_seconds(),
			'holidays'     => $holidays,
			'days'         => $days,
			'requests'     => $mine,
		);
	}

	/**
	 * Read-only calendar state for the signed-in employee.
	 * Does not write allowances, adjustments, or the hire date.
	 *
	 * @return void
	 */
	public function ajax_state() {
		$user_id = $this->employee_or_die();
		wp_send_json_success( $this->state( $user_id ) );
	}

	/**
	 * Create a time-off request for the signed-in employee only.
	 * Does not write allowances, adjustments, or the hire date.
	 *
	 * @return void
	 */
	public function ajax_request() {
		$user_id = $this->employee_or_die();
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$dates   = isset( $_POST['dates'] ) ? array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['dates'] ) ) ) ) ) : array();
		$seconds = isset( $_POST['seconds'] ) ? absint( $_POST['seconds'] ) : 0;
		$note    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$days    = array();
		foreach ( $dates as $d ) {
			$days[ $d ] = $seconds;
		}
		$result = $this->leave()->create( $user_id, $days, $type, $note, $user_id, false, false );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success(
			array(
				'message' => __( 'Request sent. A manager will approve or deny it.', 'css-timeclock-addon' ),
				'state'   => $this->state( $user_id ),
			)
		);
	}

	/**
	 * Cancel the signed-in employee's own pending or future request.
	 * Does not write allowances, adjustments, or the hire date.
	 *
	 * @return void
	 */
	public function ajax_cancel() {
		$user_id = $this->employee_or_die();
		$group   = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '';
		$result  = $this->leave()->cancel_own( $group, $user_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success(
			array(
				'message' => __( 'Cancelled.', 'css-timeclock-addon' ),
				'state'   => $this->state( $user_id ),
			)
		);
	}
}
