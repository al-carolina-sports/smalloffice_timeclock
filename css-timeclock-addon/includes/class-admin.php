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
		add_action( 'admin_menu', array( $self, 'organize_menu' ), 1001 );
		add_filter( 'submenu_file', array( $self, 'highlight_tab_menu' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $self, 'enqueue' ) );
	}

	/**
	 * @return void
	 */
	public function add_menu() {
		$page = 'css-tc-addon';

		if ( ! Css_Tc_Plugin::aio_is_active() ) {
			add_options_page(
				Css_Tc_Branding::BRAND,
				Css_Tc_Branding::BRAND,
				'manage_options',
				$page,
				array( $this, 'render_page' )
			);
		}

		if ( Css_Tc_Plugin::aio_is_active() ) {
			add_submenu_page(
				'aio-tc-lite',
				Css_Tc_Branding::BRAND,
				Css_Tc_Branding::BRAND,
				Css_Tc_Plugin::admin_capability(),
				$page,
				array( $this, 'render_page' )
			);
			add_submenu_page(
				'aio-tc-lite',
				__( 'Timecards', 'css-timeclock-addon' ),
				__( 'Timecards', 'css-timeclock-addon' ),
				Css_Tc_Plugin::admin_capability(),
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
				CSS_TC_ADDON_ASSET_VERSION
			);
			return;
		}

		if ( $is_timecards ) {
			wp_enqueue_style(
				'css-tc-timecard',
				CSS_TC_ADDON_URL . 'public/css/timecard.css',
				array(),
				CSS_TC_ADDON_ASSET_VERSION
			);
			wp_enqueue_script(
				'css-tc-timecard',
				CSS_TC_ADDON_URL . 'public/js/timecard.js',
				array(),
				CSS_TC_ADDON_ASSET_VERSION,
				true
			);
			return;
		}

		wp_enqueue_style(
			'css-tc-admin',
			CSS_TC_ADDON_URL . 'admin/css/admin.css',
			array(),
			CSS_TC_ADDON_ASSET_VERSION
		);

		wp_enqueue_script(
			'css-tc-admin',
			CSS_TC_ADDON_URL . 'admin/js/admin.js',
			array(),
			CSS_TC_ADDON_ASSET_VERSION,
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
			CSS_TC_ADDON_ASSET_VERSION
		);

		wp_enqueue_script(
			'css-tc-aio-upsell',
			CSS_TC_ADDON_URL . 'admin/js/aio-upsell.js',
			array(),
			CSS_TC_ADDON_ASSET_VERSION,
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
		if ( ! in_array( $tab, array( 'settings', 'pins', 'locations', 'corrections' ), true ) ) {
			$tab = 'settings';
		}

		$pin_page   = ! empty( $settings['pin_kiosk_page_id'] ) ? get_permalink( (int) $settings['pin_kiosk_page_id'] ) : '';
		$name_page  = ! empty( $settings['name_kiosk_page_id'] ) ? get_permalink( (int) $settings['name_kiosk_page_id'] ) : '';
		$times_page = ! empty( $settings['employee_times_page_id'] ) ? get_permalink( (int) $settings['employee_times_page_id'] ) : '';
		$queue      = css_tc_addon()->corrections->admin_queue();
		$base_url = self::settings_url();

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
			$notice = __( 'Saved.', 'css-timeclock-addon' );
		}
		$manager_draft = get_transient( 'css_tc_manager_draft_' . get_current_user_id() );
		if ( ! is_array( $manager_draft ) ) {
			$manager_draft = array();
		}
		delete_transient( 'css_tc_manager_draft_' . get_current_user_id() );
		$stored_error = (string) get_transient( 'css_tc_manager_error_' . get_current_user_id() );
		if ( '' !== $stored_error ) {
			delete_transient( 'css_tc_manager_error_' . get_current_user_id() );
			$form_error = $stored_error;
		}

		include CSS_TC_ADDON_DIR . 'admin/views/timecard-page.php';
	}

	/**
	 * SMOTC settings page (optionally a tab).
	 *
	 * @param string $tab Tab slug or ''.
	 * @return string
	 */
	public static function settings_url( $tab = '' ) {
		$url = Css_Tc_Plugin::aio_is_active()
			? admin_url( 'admin.php?page=css-tc-addon' )
			: admin_url( 'options-general.php?page=css-tc-addon' );
		return '' !== $tab ? $url . '&tab=' . rawurlencode( $tab ) : $url;
	}

	/**
	 * One clear menu under the time clock:
	 * Timecards, Reports, Who's working, Corrections, Employees & PINs,
	 * Locations & departments, Settings, and (administrators) the base
	 * AIO settings last. AIO's Employees and Shifts pages, and its
	 * Departments page while SMOTC departments are on, leave the menu; the
	 * pages still open from a direct link.
	 *
	 * @return void
	 */
	public function organize_menu() {
		global $submenu;
		if ( ! Css_Tc_Plugin::aio_is_active() ) {
			return;
		}
		$parent = 'aio-tc-lite';
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$by_slug = array();
		foreach ( $submenu[ $parent ] as $item ) {
			if ( is_array( $item ) && isset( $item[2] ) ) {
				$by_slug[ (string) $item[2] ] = $item;
			}
		}

		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			remove_menu_page( $parent ); // AIO shows it to anyone with edit_posts.
			return;
		}
		$cap     = Css_Tc_Plugin::admin_capability();
		$pending = css_tc_addon()->corrections->pending_count();
		$badge   = $pending > 0
			? ' <span class="awaiting-mod count-' . (int) $pending . '"><span class="pending-count">' . (int) $pending . '</span></span>'
			: '';
		$tab_url = static function ( $tab ) {
			return 'admin.php?page=css-tc-addon&tab=' . $tab;
		};

		$ordered = array();
		$ordered[] = array( __( 'Timecards', 'css-timeclock-addon' ), $cap, 'css-tc-timecards', __( 'Timecards', 'css-timeclock-addon' ) );
		if ( isset( $by_slug['aio-reports-sub'] ) ) {
			$item      = $by_slug['aio-reports-sub'];
			$item[0]   = __( 'Reports', 'css-timeclock-addon' );
			$item[1]   = $cap;
			$ordered[] = $item;
		}
		if ( isset( $by_slug['aio-monitoring-sub'] ) ) {
			$item      = $by_slug['aio-monitoring-sub'];
			$item[0]   = __( 'Who\'s working', 'css-timeclock-addon' );
			$item[1]   = $cap;
			$ordered[] = $item;
		}
		$ordered[] = array( __( 'Corrections', 'css-timeclock-addon' ) . $badge, $cap, $tab_url( 'corrections' ), __( 'Corrections', 'css-timeclock-addon' ) );
		$ordered[] = array( __( 'Employees & PINs', 'css-timeclock-addon' ), $cap, $tab_url( 'pins' ), __( 'Employees & PINs', 'css-timeclock-addon' ) );
		$ordered[] = array( __( 'Locations & departments', 'css-timeclock-addon' ), $cap, $tab_url( 'locations' ), __( 'Locations & departments', 'css-timeclock-addon' ) );
		$ordered[] = array( __( 'Settings', 'css-timeclock-addon' ), $cap, 'css-tc-addon', __( 'Settings', 'css-timeclock-addon' ) );

		$hide = array( 'aio-tc-lite', 'aio-reports-sub', 'aio-monitoring-sub', 'css-tc-timecards', 'css-tc-addon', 'aio-employees-sub', 'aio-shifts-sub' );
		if ( css_tc_addon()->organization->enabled() ) {
			$hide[] = 'aio-department-sub';
		}
		foreach ( $by_slug as $slug => $item ) {
			if ( ! in_array( $slug, $hide, true ) ) {
				$ordered[] = $item; // Anything else AIO adds keeps a place, above base settings.
			}
		}
		if ( isset( $by_slug['aio-tc-lite'] ) && current_user_can( 'manage_options' ) ) {
			$item      = $by_slug['aio-tc-lite'];
			$item[0]   = __( 'Base clock settings', 'css-timeclock-addon' );
			$ordered[] = $item;
		}

		$submenu[ $parent ] = array_values( $ordered ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// AIO's "Department" screen under Users duplicates SMOTC's departments.
		if ( css_tc_addon()->organization->enabled() ) {
			remove_submenu_page( 'users.php', 'edit-tags.php?taxonomy=department' );
		}
	}

	/**
	 * Highlight the right menu item on the tabbed SMOTC settings page.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @param string      $parent_file  Current parent.
	 * @return string|null
	 */
	public function highlight_tab_menu( $submenu_file, $parent_file ) {
		unset( $parent_file );
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'css-tc-addon' === $page && in_array( $tab, array( 'corrections', 'pins', 'locations' ), true ) && Css_Tc_Plugin::aio_is_active() ) {
			return 'admin.php?page=css-tc-addon&tab=' . $tab;
		}
		return $submenu_file;
	}

	/**
	 * One line per hostname in a list: where it points and when it was checked.
	 *
	 * @param string $raw List text (allowlist or a location's office network).
	 * @return void
	 */
	public static function render_host_status( $raw ) {
		$pins  = css_tc_addon()->pins;
		$hosts = $pins->parse_allowlist( (string) $raw )['hosts'];
		if ( empty( $hosts ) ) {
			return;
		}
		echo '<ul class="css-tc-host-status">';
		foreach ( $hosts as $host ) {
			$st  = $pins->host_status( $host );
			$ago = $st['checked'] ? human_time_diff( (int) $st['checked'] ) : '';
			if ( empty( $st['ips'] ) ) {
				/* translators: %s: hostname */
				$text  = sprintf( __( '%s could not be looked up yet, so it matches no one. Check the spelling, or that the name is active.', 'css-timeclock-addon' ), $host );
				$class = 'css-tc-host-status__bad';
			} elseif ( ! empty( $st['failed'] ) ) {
				$text  = sprintf(
					/* translators: 1: hostname, 2: addresses, 3: time ago */
					__( '%1$s → %2$s (last lookup failed %3$s ago; still using the last address that worked).', 'css-timeclock-addon' ),
					$host,
					implode( ', ', $st['ips'] ),
					$ago
				);
				$class = 'css-tc-host-status__warn';
			} else {
				$text  = sprintf(
					/* translators: 1: hostname, 2: addresses, 3: time ago */
					__( '%1$s → %2$s (checked %3$s ago).', 'css-timeclock-addon' ),
					$host,
					implode( ', ', $st['ips'] ),
					$ago
				);
				$class = 'css-tc-host-status__ok';
			}
			echo '<li class="' . esc_attr( $class ) . '">' . esc_html( $text ) . '</li>';
		}
		echo '</ul>';
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
