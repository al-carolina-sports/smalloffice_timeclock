<?php
/**
 * Admin settings and per-employee PIN management.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Time Clock submenu when AIO is present; otherwise Settings.
 */
class Css_Tc_Admin {

	/**
	 * @return void
	 */
	public static function register() {
		$self = new self();
		add_action( 'admin_menu', array( $self, 'add_menu' ), 25 );
		add_action( 'admin_menu', array( $self, 'replace_monitoring_screen' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $self, 'enqueue' ) );
	}

	/**
	 * @return void
	 */
	public function add_menu() {
		$page = 'css-tc-addon';

		add_options_page(
			Css_Tc_Branding::BRAND,
			Css_Tc_Branding::BRAND,
			'manage_options',
			$page,
			array( $this, 'render_page' )
		);

		if ( Css_Tc_Plugin::aio_is_active() ) {
			add_submenu_page(
				'aio-tc-lite',
				Css_Tc_Branding::BRAND,
				Css_Tc_Branding::BRAND,
				'edit_posts',
				$page,
				array( $this, 'render_page' )
			);
			add_submenu_page(
				'aio-tc-lite',
				__( 'Timecards', 'css-timeclock-addon' ),
				__( 'Timecards', 'css-timeclock-addon' ),
				'edit_posts',
				'css-tc-timecards',
				array( $this, 'render_timecards' )
			);
		} else {
			add_options_page(
				__( 'Timecards', 'css-timeclock-addon' ),
				__( 'Timecards', 'css-timeclock-addon' ),
				'manage_options',
				'css-tc-timecards',
				array( $this, 'render_timecards' )
			);
		}
	}

	/**
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( $this->is_aio_admin_screen( $hook ) ) {
			$this->enqueue_aio_upsell_hide();
		}

		$is_timecards  = ( false !== strpos( (string) $hook, 'css-tc-timecards' ) );
		$is_monitoring = ( false !== strpos( (string) $hook, 'aio-monitoring-sub' ) );
		$is_ours       = $is_timecards || $is_monitoring || ( false !== strpos( (string) $hook, 'css-tc-addon' ) );
		if ( ! $is_ours ) {
			return;
		}

		if ( $is_monitoring ) {
			wp_enqueue_style(
				'css-tc-admin',
				CSS_TC_ADDON_URL . 'admin/css/admin.css',
				array(),
				CSS_TC_ADDON_VERSION
			);
			return;
		}

		if ( $is_timecards ) {
			wp_enqueue_style(
				'css-tc-timecard',
				CSS_TC_ADDON_URL . 'public/css/timecard.css',
				array(),
				CSS_TC_ADDON_VERSION
			);
			wp_enqueue_script(
				'css-tc-timecard',
				CSS_TC_ADDON_URL . 'public/js/timecard.js',
				array(),
				CSS_TC_ADDON_VERSION,
				true
			);
			return;
		}

		wp_enqueue_style(
			'css-tc-admin',
			CSS_TC_ADDON_URL . 'admin/css/admin.css',
			array(),
			CSS_TC_ADDON_VERSION
		);

		wp_enqueue_script(
			'css-tc-admin',
			CSS_TC_ADDON_URL . 'admin/js/admin.js',
			array(),
			CSS_TC_ADDON_VERSION,
			true
		);

		wp_localize_script(
			'css-tc-admin',
			'cssTcAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Css_Tc_Ajax::ADMIN_NONCE ),
				'strings' => array(
					'confirmClear' => __( 'Remove this employee PIN? They will not be able to use the kiosk until a new PIN is set.', 'css-timeclock-addon' ),
					'saving'       => __( 'Saving…', 'css-timeclock-addon' ),
					'saved'        => __( 'Saved.', 'css-timeclock-addon' ),
					'error'        => __( 'Something went wrong. Try again.', 'css-timeclock-addon' ),
					'notSet'       => __( 'Not set', 'css-timeclock-addon' ),
					'set'          => __( 'Set', 'css-timeclock-addon' ),
					'showPin'      => __( 'Show PIN', 'css-timeclock-addon' ),
					'hidePin'      => __( 'Hide PIN', 'css-timeclock-addon' ),
					'confirmReject'=> __( 'Reject this suggestion? Punches will stay unchanged.', 'css-timeclock-addon' ),
					'approved'     => __( 'Approved', 'css-timeclock-addon' ),
					'rejected'     => __( 'Rejected', 'css-timeclock-addon' ),
					'pending'      => __( 'Pending review', 'css-timeclock-addon' ),
				),
			)
		);
	}

	/**
	 * AIO Lite screens, plus Kiosk & PINs in case a Pro control is rendered there.
	 *
	 * @param string $hook Current admin hook.
	 * @return bool
	 */
	private function is_aio_admin_screen( $hook ) {
		$hook    = (string) $hook;
		$screens = array(
			'aio-tc-lite',
			'aio-monitoring-sub',
			'aio-employees-sub',
			'aio-department-sub',
			'aio-shifts-sub',
			'aio-reports-sub',
			'css-tc-addon',
		);

		foreach ( $screens as $screen ) {
			if ( false !== strpos( $hook, $screen ) ) {
				return true;
			}
		}

		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $page, $screens, true );
	}

	/**
	 * Stylesheet (and a small script) that hides Pro promos. Does not change AIO files.
	 *
	 * @return void
	 */
	private function enqueue_aio_upsell_hide() {
		wp_enqueue_style(
			'css-tc-aio-upsell',
			CSS_TC_ADDON_URL . 'admin/css/aio-upsell.css',
			array(),
			CSS_TC_ADDON_VERSION
		);

		wp_enqueue_script(
			'css-tc-aio-upsell',
			CSS_TC_ADDON_URL . 'admin/js/aio-upsell.js',
			array(),
			CSS_TC_ADDON_VERSION,
			true
		);
	}

	/**
	 * @return void
	 */
	public function render_page() {
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage the time clock kiosk.', 'css-timeclock-addon' ) );
		}

		$settings  = css_tc_addon()->get_settings();
		$employees = css_tc_addon()->employees->list_for_admin();
		$tab       = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'settings', 'pins', 'corrections' ), true ) ) {
			$tab = 'settings';
		}

		$pin_page   = ! empty( $settings['pin_kiosk_page_id'] ) ? get_permalink( (int) $settings['pin_kiosk_page_id'] ) : '';
		$name_page  = ! empty( $settings['name_kiosk_page_id'] ) ? get_permalink( (int) $settings['name_kiosk_page_id'] ) : '';
		$times_page = ! empty( $settings['employee_times_page_id'] ) ? get_permalink( (int) $settings['employee_times_page_id'] ) : '';
		$queue      = css_tc_addon()->corrections->admin_queue();
		$base_url = current_user_can( 'manage_options' )
			? admin_url( 'options-general.php?page=css-tc-addon' )
			: admin_url( 'admin.php?page=css-tc-addon' );

		include CSS_TC_ADDON_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Swap AIO's Real Time Monitoring callback for the SMOTC screen.
	 *
	 * The menu slug stays aio-monitoring-sub so existing links keep working.
	 * AIO's page lists every open shift and prints the stored UTC digits.
	 *
	 * @return void
	 */
	public function replace_monitoring_screen() {
		if ( ! Css_Tc_Plugin::aio_is_active() || ! function_exists( 'get_plugin_page_hookname' ) ) {
			return;
		}

		$hook = get_plugin_page_hookname( 'aio-monitoring-sub', 'aio-tc-lite' );
		if ( '' === $hook ) {
			return;
		}

		remove_all_actions( $hook );
		add_action( $hook, array( $this, 'render_monitoring' ) );
	}

	/**
	 * Working now, plus missed clock-outs, in the site timezone.
	 *
	 * @return void
	 */
	public function render_monitoring() {
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view who is working.', 'css-timeclock-addon' ) );
		}

		$snapshot = css_tc_addon()->punches->monitoring_snapshot();
		include CSS_TC_ADDON_DIR . 'admin/views/monitoring-page.php';
	}

	/**
	 * SMOTC → Timecards. Any employee, read-only outside the current period.
	 *
	 * @return void
	 */
	public function render_timecards() {
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view timecards.', 'css-timeclock-addon' ) );
		}

		$employees = css_tc_addon()->employees->list_for_admin();
		usort(
			$employees,
			static function ( $a, $b ) {
				return strcasecmp(
					css_tc_addon()->employees->display_name( (int) $a->ID ),
					css_tc_addon()->employees->display_name( (int) $b->ID )
				);
			}
		);

		$requested = isset( $_GET['employee'] ) ? absint( $_GET['employee'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids       = array();
		foreach ( $employees as $user ) {
			$ids[] = (int) $user->ID;
		}
		if ( $requested && in_array( $requested, $ids, true ) ) {
			$user_id = $requested;
		} elseif ( ! empty( $ids ) ) {
			$user_id = $ids[0];
		} else {
			$user_id = 0;
		}

		$period_start = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$period       = css_tc_addon()->pay_periods->period_by_start( $period_start );
		if ( ! $period ) {
			$period = css_tc_addon()->pay_periods->current_period();
		}

		$sheet    = ( $user_id && $period ) ? css_tc_addon()->timecard->build( $user_id, $period ) : null;
		$index    = array_search( $user_id, $ids, true );
		$prev_id  = ( false !== $index && $index > 0 ) ? $ids[ $index - 1 ] : 0;
		$next_id  = ( false !== $index && $index < count( $ids ) - 1 ) ? $ids[ $index + 1 ] : 0;
		$periods  = css_tc_addon()->pay_periods->dropdown_periods( 6 );
		$edit_day = isset( $_GET['edit_day'] ) ? sanitize_text_field( wp_unslash( $_GET['edit_day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $edit_day ) ) {
			$edit_day = '';
		}
		$notice     = '';
		$form_error = '';
		if ( isset( $_GET['css_tc_notice'] ) && 'edited' === sanitize_key( wp_unslash( $_GET['css_tc_notice'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = __( 'Day saved. The edit is under Recently reviewed on the Corrections tab, labeled Edited by manager.', 'css-timeclock-addon' );
		}
		$stored_error = (string) get_transient( 'css_tc_manager_error_' . get_current_user_id() );
		if ( '' !== $stored_error ) {
			delete_transient( 'css_tc_manager_error_' . get_current_user_id() );
			$form_error = $stored_error;
		}

		include CSS_TC_ADDON_DIR . 'admin/views/timecard-page.php';
	}

	/**
	 * @param array<string,mixed> $args Query args.
	 * @return string
	 */
	public static function timecards_url( $args = array() ) {
		$base = Css_Tc_Plugin::aio_is_active()
			? admin_url( 'admin.php?page=css-tc-timecards' )
			: admin_url( 'options-general.php?page=css-tc-timecards' );
		if ( ! empty( $args ) ) {
			$base = add_query_arg( $args, $base );
		}
		return $base;
	}
}
