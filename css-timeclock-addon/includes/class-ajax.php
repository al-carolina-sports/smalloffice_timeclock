<?php
/**
 * Public kiosk AJAX and admin PIN AJAX.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers privileged and nopriv endpoints. Kiosk auth is PIN, not WP login.
 */
class Css_Tc_Ajax {

	const PUBLIC_NONCE = 'css_tc_kiosk';
	const ADMIN_NONCE  = 'css_tc_admin';

	/**
	 * @return void
	 */
	public static function register() {
		$self = new self();

		add_action( 'wp_ajax_css_tc_resolve_pin', array( $self, 'resolve_pin' ) );
		add_action( 'wp_ajax_nopriv_css_tc_resolve_pin', array( $self, 'resolve_pin' ) );
		add_action( 'wp_ajax_css_tc_punch', array( $self, 'punch' ) );
		add_action( 'wp_ajax_nopriv_css_tc_punch', array( $self, 'punch' ) );
		add_action( 'wp_ajax_css_tc_employees', array( $self, 'employees' ) );
		add_action( 'wp_ajax_nopriv_css_tc_employees', array( $self, 'employees' ) );
		add_action( 'wp_ajax_css_tc_roster', array( $self, 'roster' ) );
		add_action( 'wp_ajax_nopriv_css_tc_roster', array( $self, 'roster' ) );

		add_action( 'wp_ajax_css_tc_save_settings', array( $self, 'save_settings' ) );
		add_action( 'wp_ajax_css_tc_save_pin', array( $self, 'save_pin' ) );
		add_action( 'wp_ajax_css_tc_clear_pin', array( $self, 'clear_pin' ) );
		add_action( 'wp_ajax_css_tc_create_pages', array( $self, 'create_pages' ) );
		add_action( 'wp_ajax_css_tc_review_correction', array( $self, 'review_correction' ) );

		add_action( 'wp_ajax_css_tc_my_times', array( $self, 'my_times' ) );
		add_action( 'wp_ajax_css_tc_suggest_edit', array( $self, 'suggest_edit' ) );

		add_action( 'admin_post_css_tc_submit_period', array( $self, 'submit_period' ) );
		add_action( 'admin_post_css_tc_flag_day', array( $self, 'flag_day' ) );
	}

	/**
	 * @return void
	 */
	public function resolve_pin() {
		$this->verify_public_nonce();
		$this->assert_office_network();

		$settings = css_tc_addon()->get_settings();
		$mode     = isset( $_POST['kiosk'] ) ? sanitize_key( wp_unslash( $_POST['kiosk'] ) ) : 'pin';

		if ( 'name' === $mode && empty( $settings['name_kiosk_enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The name-list kiosk is disabled.', 'css-timeclock-addon' ) ), 403 );
		}
		if ( 'name' !== $mode && empty( $settings['pin_kiosk_enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The PIN kiosk is disabled.', 'css-timeclock-addon' ) ), 403 );
		}

		$limited = css_tc_addon()->pins->assert_not_rate_limited();
		if ( is_wp_error( $limited ) ) {
			wp_send_json_error( array( 'message' => $limited->get_error_message() ), 429 );
		}

		$pin     = css_tc_addon()->pins->normalize( isset( $_POST['pin'] ) ? wp_unslash( $_POST['pin'] ) : '' );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		if ( '' === $pin ) {
			css_tc_addon()->pins->record_failure();
			wp_send_json_error( array( 'message' => __( 'That PIN was not recognized.', 'css-timeclock-addon' ) ), 403 );
		}

		if ( $user_id > 0 ) {
			if ( ! css_tc_addon()->employees->is_kiosk_employee( $user_id ) ) {
				css_tc_addon()->pins->record_failure();
				wp_send_json_error( array( 'message' => __( 'That PIN was not recognized.', 'css-timeclock-addon' ) ), 403 );
			}
			$ok = css_tc_addon()->pins->verify_for_user( $user_id, $pin );
			if ( ! $ok ) {
				css_tc_addon()->pins->record_failure();
				wp_send_json_error( array( 'message' => __( 'That PIN was not recognized.', 'css-timeclock-addon' ) ), 403 );
			}
		} else {
			$user_id = css_tc_addon()->pins->find_user_id_by_pin( $pin );
			if ( $user_id < 1 || ! css_tc_addon()->employees->is_employee( $user_id ) ) {
				css_tc_addon()->pins->record_failure();
				wp_send_json_error( array( 'message' => __( 'That PIN was not recognized.', 'css-timeclock-addon' ) ), 403 );
			}
		}

		css_tc_addon()->pins->record_success();

		$open = css_tc_addon()->punches->open_shift_for( $user_id );

		wp_send_json_success(
			array(
				'user_id'       => $user_id,
				'name'          => css_tc_addon()->employees->greeting_name( $user_id ),
				'department'    => css_tc_addon()->employees->department( $user_id ),
				'is_clocked_in' => $open['is_clocked_in'],
				'clock_in_time' => $open['clock_in_time'],
				'next_action'   => $open['is_clocked_in'] ? 'clock_out' : 'clock_in',
			)
		);
	}

	/**
	 * @return void
	 */
	public function punch() {
		$this->verify_public_nonce();
		$this->assert_office_network();

		$settings = css_tc_addon()->get_settings();
		$source   = isset( $_POST['kiosk'] ) ? sanitize_key( wp_unslash( $_POST['kiosk'] ) ) : 'pin_kiosk';
		if ( 'name' === $source ) {
			$source = 'name_kiosk';
		} elseif ( 'pin' === $source ) {
			$source = 'pin_kiosk';
		}

		if ( 'name_kiosk' === $source && empty( $settings['name_kiosk_enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The name-list kiosk is disabled.', 'css-timeclock-addon' ) ), 403 );
		}
		if ( 'name_kiosk' !== $source && empty( $settings['pin_kiosk_enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The PIN kiosk is disabled.', 'css-timeclock-addon' ) ), 403 );
		}

		$limited = css_tc_addon()->pins->assert_not_rate_limited();
		if ( is_wp_error( $limited ) ) {
			wp_send_json_error( array( 'message' => $limited->get_error_message() ), 429 );
		}

		$pin        = css_tc_addon()->pins->normalize( isset( $_POST['pin'] ) ? wp_unslash( $_POST['pin'] ) : '' );
		$user_id    = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$clock_act  = isset( $_POST['clock_action'] ) ? sanitize_key( wp_unslash( $_POST['clock_action'] ) ) : '';

		if ( ! in_array( $clock_act, array( 'clock_in', 'clock_out' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown clock action.', 'css-timeclock-addon' ) ), 400 );
		}

		if ( $user_id > 0 ) {
			if ( ! css_tc_addon()->pins->verify_for_user( $user_id, $pin ) ) {
				css_tc_addon()->pins->record_failure();
				wp_send_json_error( array( 'message' => __( 'That PIN was not recognized.', 'css-timeclock-addon' ) ), 403 );
			}
		} else {
			$user_id = css_tc_addon()->pins->find_user_id_by_pin( $pin );
			if ( $user_id < 1 ) {
				css_tc_addon()->pins->record_failure();
				wp_send_json_error( array( 'message' => __( 'That PIN was not recognized.', 'css-timeclock-addon' ) ), 403 );
			}
		}

		if ( ! css_tc_addon()->employees->is_employee( $user_id ) ) {
			css_tc_addon()->pins->record_failure();
			wp_send_json_error( array( 'message' => __( 'That PIN was not recognized.', 'css-timeclock-addon' ) ), 403 );
		}

		css_tc_addon()->pins->record_success();

		if ( 'clock_in' === $clock_act ) {
			$result = css_tc_addon()->punches->clock_in( $user_id, $source );
		} else {
			$result = css_tc_addon()->punches->clock_out( $user_id, $source );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		$result['name']  = css_tc_addon()->employees->greeting_name( $user_id );
		$result['board'] = css_tc_addon()->punches->public_board();
		wp_send_json_success( $result );
	}

	/**
	 * Alphabetical employee list for the name kiosk.
	 *
	 * @return void
	 */
	public function employees() {
		$this->verify_public_nonce();
		$this->assert_office_network();

		$settings = css_tc_addon()->get_settings();
		if ( empty( $settings['name_kiosk_enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The name-list kiosk is disabled.', 'css-timeclock-addon' ) ), 403 );
		}

		$list = css_tc_addon()->employees->list_for_kiosk( true );

		wp_send_json_success(
			array(
				'employees' => $list,
				'count'     => count( $list ),
			)
		);
	}

	/**
	 * Public who's-working board for logged-out kiosk tablets.
	 *
	 * @return void
	 */
	public function roster() {
		$this->verify_public_nonce();
		$this->assert_office_network();

		$settings = css_tc_addon()->get_settings();
		if ( empty( $settings['pin_kiosk_enabled'] ) && empty( $settings['name_kiosk_enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The kiosk is disabled.', 'css-timeclock-addon' ) ), 403 );
		}

		$limited = $this->assert_roster_not_rate_limited();
		if ( is_wp_error( $limited ) ) {
			wp_send_json_error( array( 'message' => $limited->get_error_message() ), 429 );
		}

		wp_send_json_success( css_tc_addon()->punches->public_board() );
	}

	/**
	 * @return void
	 */
	public function save_settings() {
		$this->verify_admin();

		$settings = css_tc_addon()->get_settings();
		$settings['pin_kiosk_enabled']  = empty( $_POST['pin_kiosk_enabled'] ) ? 0 : 1;
		$settings['name_kiosk_enabled'] = empty( $_POST['name_kiosk_enabled'] ) ? 0 : 1;
		$settings['pin_min_length']     = min( 8, max( 4, isset( $_POST['pin_min_length'] ) ? absint( $_POST['pin_min_length'] ) : 4 ) );
		$settings['pin_max_length']     = min( 12, max( $settings['pin_min_length'], isset( $_POST['pin_max_length'] ) ? absint( $_POST['pin_max_length'] ) : 8 ) );
		$settings['rate_limit_max']     = min( 20, max( 3, isset( $_POST['rate_limit_max'] ) ? absint( $_POST['rate_limit_max'] ) : 5 ) );
		$settings['rate_limit_window']  = min( 3600, max( 60, isset( $_POST['rate_limit_window'] ) ? absint( $_POST['rate_limit_window'] ) : 900 ) );

		$allow_raw = isset( $_POST['ip_allowlist'] ) ? (string) wp_unslash( $_POST['ip_allowlist'] ) : '';
		$allow_raw = str_replace( array( "\r\n", "\r" ), "\n", $allow_raw );
		$allow_raw = sanitize_textarea_field( $allow_raw );
		if ( strlen( $allow_raw ) > 5000 ) {
			wp_send_json_error( array( 'message' => __( 'The office IP list is too long.', 'css-timeclock-addon' ) ), 400 );
		}
		$parsed = css_tc_addon()->pins->parse_allowlist( $allow_raw );
		if ( ! empty( $parsed['invalid'] ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: invalid allowlist lines the admin typed */
						__( 'These lines are not IPv4, IPv6, or CIDR ranges: %s', 'css-timeclock-addon' ),
						implode( ', ', array_map( 'sanitize_text_field', array_slice( $parsed['invalid'], 0, 8 ) ) )
					),
				),
				400
			);
		}
		$settings['ip_allowlist_enabled'] = empty( $_POST['ip_allowlist_enabled'] ) ? 0 : 1;
		$settings['ip_allowlist']         = $allow_raw;

		$settings['idle_reset_ms']         = min( 30000, max( 3000, isset( $_POST['idle_reset_ms'] ) ? absint( $_POST['idle_reset_ms'] ) : 8000 ) );
		$settings['times_lookback_days']   = min( 60, max( 7, isset( $_POST['times_lookback_days'] ) ? absint( $_POST['times_lookback_days'] ) : 21 ) );

		$length = isset( $_POST['pay_period_length'] ) ? sanitize_key( wp_unslash( $_POST['pay_period_length'] ) ) : 'biweekly';
		$settings['pay_period_length'] = ( 'weekly' === $length ) ? 'weekly' : 'biweekly';

		$anchor = isset( $_POST['pay_period_anchor'] ) ? sanitize_text_field( wp_unslash( $_POST['pay_period_anchor'] ) ) : Css_Tc_Pay_Periods::DEFAULT_ANCHOR;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $anchor ) || ! css_tc_addon()->pay_periods->is_monday( $anchor ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The pay period anchor must be a Monday, written as YYYY-MM-DD.', 'css-timeclock-addon' ),
				),
				400
			);
		}
		$settings['pay_period_anchor'] = $anchor;
		$settings['missed_clock_out_hours'] = min( 36, max( 1, isset( $_POST['missed_clock_out_hours'] ) ? absint( $_POST['missed_clock_out_hours'] ) : 16 ) );
		$settings['long_shift_hours']       = min( 36, max( 1, isset( $_POST['long_shift_hours'] ) ? absint( $_POST['long_shift_hours'] ) : 16 ) );
		$settings['wide_layout']            = empty( $_POST['wide_layout'] ) ? 0 : 1;

		css_tc_addon()->update_settings( $settings );

		wp_send_json_success(
			array(
				'message'  => __( 'Settings saved.', 'css-timeclock-addon' ),
				'settings' => $settings,
			)
		);
	}

	/**
	 * @return void
	 */
	public function save_pin() {
		$this->verify_admin();

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$pin     = isset( $_POST['pin'] ) ? wp_unslash( $_POST['pin'] ) : '';

		$result = css_tc_addon()->pins->set_pin( $user_id, $pin );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'PIN saved. The digits are stored as a hash only.', 'css-timeclock-addon' ),
				'user_id'  => $user_id,
				'has_pin'  => true,
				'set_at'   => wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'g:i a' ) ),
			)
		);
	}

	/**
	 * @return void
	 */
	public function clear_pin() {
		$this->verify_admin();

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( $user_id < 1 || ! css_tc_addon()->employees->is_employee( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid employee.', 'css-timeclock-addon' ) ), 400 );
		}

		css_tc_addon()->pins->clear_pin( $user_id );

		wp_send_json_success(
			array(
				'message' => __( 'PIN removed.', 'css-timeclock-addon' ),
				'user_id' => $user_id,
				'has_pin' => false,
			)
		);
	}

	/**
	 * @return void
	 */
	public function create_pages() {
		$this->verify_admin();

		$ids = Css_Tc_Shortcodes::create_public_pages();

		wp_send_json_success(
			array(
				'message' => __( 'Kiosk pages are ready.', 'css-timeclock-addon' ),
				'pages'   => $ids,
			)
		);
	}

	/**
	 * Logged-in employee: their recent days only.
	 *
	 * @return void
	 */
	public function my_times() {
		$this->verify_employee();
		$user_id = get_current_user_id();
		wp_send_json_success( css_tc_addon()->corrections->dashboard_for_user( $user_id ) );
	}

	/**
	 * Logged-in employee: suggest an edit for one of their days.
	 *
	 * @return void
	 */
	public function suggest_edit() {
		$this->verify_employee();

		$user_id = get_current_user_id();
		$result  = css_tc_addon()->corrections->submit(
			$user_id,
			array(
				'work_date'     => isset( $_POST['work_date'] ) ? wp_unslash( $_POST['work_date'] ) : '',
				'shift_id'      => isset( $_POST['shift_id'] ) ? wp_unslash( $_POST['shift_id'] ) : 0,
				'proposed_in'   => isset( $_POST['proposed_in'] ) ? wp_unslash( $_POST['proposed_in'] ) : '',
				'proposed_out'  => isset( $_POST['proposed_out'] ) ? wp_unslash( $_POST['proposed_out'] ) : '',
				'out_next_day'  => ! empty( $_POST['out_next_day'] ),
				'missing_punch' => ! empty( $_POST['missing_punch'] ),
				'clear_out'     => ! empty( $_POST['clear_out'] ),
				'reason'        => isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Suggestion sent. A supervisor will review it.', 'css-timeclock-addon' ),
				'suggestion' => $result,
				'dashboard'  => css_tc_addon()->corrections->dashboard_for_user( $user_id ),
			)
		);
	}

	/**
	 * Logged-in employee: submit edits for every changed day in the open pay period.
	 *
	 * @return void
	 */
	public function submit_period() {
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! css_tc_addon()->employees->can_view_own_times( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to suggest corrections.', 'css-timeclock-addon' ) );
		}
		check_admin_referer( Css_Tc_Corrections::EMPLOYEE_NONCE );

		$raw   = isset( $_POST['lines'] ) ? wp_unslash( $_POST['lines'] ) : array();
		$lines = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $line ) {
				if ( ! is_array( $line ) ) {
					continue;
				}
				$lines[] = array(
					'work_date'     => isset( $line['work_date'] ) ? $line['work_date'] : '',
					'shift_id'      => isset( $line['shift_id'] ) ? $line['shift_id'] : 0,
					'correction_id' => isset( $line['correction_id'] ) ? $line['correction_id'] : 0,
					'proposed_in'   => isset( $line['proposed_in'] ) ? $line['proposed_in'] : '',
					'proposed_out'  => isset( $line['proposed_out'] ) ? $line['proposed_out'] : '',
					'out_next_day'  => ! empty( $line['out_next_day'] ),
					'missing_punch' => ! empty( $line['missing_punch'] ),
					'reason'        => isset( $line['reason'] ) ? $line['reason'] : '',
				);
			}
		}

		$result = css_tc_addon()->corrections->submit_period( $user_id, $lines );
		if ( is_wp_error( $result ) ) {
			set_transient( 'css_tc_period_error_' . $user_id, $result->get_error_message(), 2 * MINUTE_IN_SECONDS );
			wp_safe_redirect( Css_Tc_Shortcodes::correct_url() );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'css_tc_notice', 'sent', Css_Tc_Shortcodes::times_url() ) );
		exit;
	}

	/**
	 * Logged-in employee: flag a day in the current pay period.
	 *
	 * @return void
	 */
	public function flag_day() {
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! css_tc_addon()->employees->can_view_own_times( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to flag a day.', 'css-timeclock-addon' ) );
		}
		check_admin_referer( Css_Tc_Corrections::EMPLOYEE_NONCE );

		$date   = isset( $_POST['work_date'] ) ? sanitize_text_field( wp_unslash( $_POST['work_date'] ) ) : '';
		$on     = ! empty( $_POST['flag'] );
		$result = css_tc_addon()->timecard->set_flag( $user_id, $date, $on );
		$url    = Css_Tc_Shortcodes::times_url();
		if ( is_wp_error( $result ) ) {
			set_transient( 'css_tc_period_error_' . $user_id, $result->get_error_message(), 2 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Admin: approve or reject a pending suggestion.
	 *
	 * @return void
	 */
	public function review_correction() {
		$this->verify_admin();

		$correction_id = isset( $_POST['correction_id'] ) ? absint( $_POST['correction_id'] ) : 0;
		$decision      = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$note          = isset( $_POST['review_note'] ) ? wp_unslash( $_POST['review_note'] ) : '';
		$reviewer_id   = get_current_user_id();

		if ( 'approve' === $decision ) {
			$result = css_tc_addon()->corrections->approve( $correction_id, $reviewer_id, $note );
		} elseif ( 'reject' === $decision ) {
			$result = css_tc_addon()->corrections->reject( $correction_id, $reviewer_id, $note );
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown review action.', 'css-timeclock-addon' ) ), 400 );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		wp_send_json_success(
			array(
				'message'    => 'approve' === $decision
					? __( 'Correction applied. The original times are kept on the suggestion for audit.', 'css-timeclock-addon' )
					: __( 'Suggestion rejected. Punches were not changed.', 'css-timeclock-addon' ),
				'suggestion' => $result,
				'queue'      => css_tc_addon()->corrections->admin_queue(),
			)
		);
	}

	/**
	 * Refuse kiosk reads and punches from outside the office list.
	 *
	 * wp-admin and logged-in employee times are not checked here. The message
	 * never includes the client address.
	 *
	 * @return void
	 */
	private function assert_office_network() {
		if ( css_tc_addon()->pins->is_client_allowed() ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'This kiosk only works from the office network.', 'css-timeclock-addon' ),
				'code'    => 'office_only',
			),
			403
		);
	}

	/**
	 * Soft IP throttle for the public roster poll (separate from the PIN lock).
	 *
	 * @return true|WP_Error
	 */
	private function assert_roster_not_rate_limited() {
		$key   = css_tc_addon()->pins->client_key() . '_roster';
		$count = (int) get_transient( $key );
		$max   = 40;

		if ( $count >= $max ) {
			return new WP_Error(
				'css_tc_roster_limited',
				__( 'Please wait a moment and try again.', 'css-timeclock-addon' )
			);
		}

		set_transient( $key, $count + 1, 60 );

		return true;
	}

	/**
	 * @return void
	 */
	private function verify_public_nonce() {
		if ( ! check_ajax_referer( self::PUBLIC_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired. Refresh the kiosk page.', 'css-timeclock-addon' ) ), 403 );
		}
	}

	/**
	 * @return void
	 */
	private function verify_admin() {
		if ( ! check_ajax_referer( self::ADMIN_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired. Refresh the page.', 'css-timeclock-addon' ) ), 403 );
		}
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage kiosk settings.', 'css-timeclock-addon' ) ), 403 );
		}
	}

	/**
	 * @return void
	 */
	private function verify_employee() {
		if ( ! check_ajax_referer( Css_Tc_Corrections::EMPLOYEE_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired. Refresh the page.', 'css-timeclock-addon' ) ), 403 );
		}
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! css_tc_addon()->employees->can_view_own_times( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view these times.', 'css-timeclock-addon' ) ), 403 );
		}
	}
}
