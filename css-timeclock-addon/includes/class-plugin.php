<?php
/**
 * Main plugin controller.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once CSS_TC_ADDON_DIR . 'includes/compat-aio.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-time.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-pay-codes.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-overtime.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-pay-periods.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-employees.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-organization.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-pins.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-punches.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-corrections.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-timecard.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-reports.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-ajax.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-admin.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-branding.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-shortcodes.php';

/**
 * Singleton that wires admin, shortcodes, assets, and AJAX.
 */
class Css_Tc_Plugin {

	const OPTION_KEY = 'css_tc_addon_settings';

	/**
	 * @var Css_Tc_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Css_Tc_Time
	 */
	public $time;

	/**
	 * @var Css_Tc_Pay_Periods
	 */
	public $pay_periods;

	/**
	 * @var Css_Tc_Timecard
	 */
	public $timecard;

	/**
	 * @var Css_Tc_Overtime
	 */
	public $overtime;

	/**
	 * @var Css_Tc_Reports
	 */
	public $reports;

	/**
	 * @var Css_Tc_Organization
	 */
	public $organization;

	/**
	 * @var Css_Tc_Employees
	 */
	public $employees;

	/**
	 * @var Css_Tc_Pins
	 */
	public $pins;

	/**
	 * @var Css_Tc_Punches
	 */
	public $punches;

	/**
	 * @var Css_Tc_Corrections
	 */
	public $corrections;

	/**
	 * @return Css_Tc_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->time        = new Css_Tc_Time();
		$this->pay_periods = new Css_Tc_Pay_Periods();
		$this->timecard    = new Css_Tc_Timecard();
		$this->overtime    = new Css_Tc_Overtime();
		$this->reports     = new Css_Tc_Reports();
		$this->organization = new Css_Tc_Organization();
		$this->employees   = new Css_Tc_Employees();
		$this->pins        = new Css_Tc_Pins();
		$this->punches     = new Css_Tc_Punches();
		$this->punches->register_hooks();
		$this->corrections = new Css_Tc_Corrections();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_runtime' ) );
		Css_Tc_Branding::register();
		add_action( 'admin_init', array( $this, 'maybe_create_times_page' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( 'css_tc_refresh_dns', array( $this, 'refresh_dns' ) );
		add_action( 'init', array( $this, 'schedule_dns_refresh' ) );
		add_action( 'init', array( __CLASS__, 'maybe_grant_caps' ), 0 );
		add_action( 'init', array( __CLASS__, 'maybe_grant_caps' ), 20 ); // After AIO registers its roles.
		add_action( 'admin_init', array( $this, 'guard_admin_pages' ), 1 );
		add_action( 'admin_notices', array( $this, 'maybe_missing_aio_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_refused_kiosk_notice' ) );
		add_filter( 'plugin_action_links_' . CSS_TC_ADDON_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Default option values.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings() {
		return array(
			'pin_kiosk_enabled'  => 1,
			'name_kiosk_enabled' => 1,
			'pin_min_length'     => 4,
			'pin_max_length'     => 8,
			'rate_limit_max'     => 5,
			'rate_limit_window'  => 900,
			'ip_allowlist_enabled' => 0,
			'ip_allowlist'         => '',
			'trusted_proxies'      => '',
			'idle_reset_ms'      => 8000,
			'pin_kiosk_page_id'       => 0,
			'name_kiosk_page_id'      => 0,
			'employee_times_page_id'  => 0,
			'times_lookback_days'     => 21,
			'pay_period_length'       => 'biweekly',
			'pay_period_anchor'       => '2026-09-07',
			'missed_clock_out_hours'  => 16,
			'long_shift_hours'        => 16,
			'overtime_enabled'        => 0,
			'overtime_hours'          => 40,
			'overtime_weeks'          => 1,
			'overtime_scope'          => 'combined',
			'assignments_enabled'     => 0,
			'switch_enabled'          => 1,
			'wide_layout'             => 1,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::default_settings() );
	}

	/**
	 * @param array<string,mixed> $settings Partial settings.
	 * @return array<string,mixed>
	 */
	public function update_settings( $settings ) {
		$merged = wp_parse_args( $settings, $this->get_settings() );
		update_option( self::OPTION_KEY, $merged, false );
		return $merged;
	}

	/**
	 * True when All in One Time Clock Lite is loaded.
	 *
	 * @return bool
	 */
	public static function aio_is_active() {
		return class_exists( 'AIO_Time_Clock_Lite_Actions' ) || class_exists( 'AIO_Time_Clock_Plugin_Lite' );
	}

	/**
	 * Capability that makes someone a timeclock manager.
	 */
	const MANAGE_CAP = 'css_tc_manage';

	/**
	 * Bump when the roles that get MANAGE_CAP change.
	 */
	const CAPS_VERSION = '1';

	/**
	 * Roles that manage the time clock.
	 *
	 * @return string[]
	 */
	public static function manager_roles() {
		return array( 'administrator', 'time_clock_admin' );
	}

	/**
	 * Give manager roles the SMOTC capability (activation, and once after an
	 * upgrade, or when AIO's Time Clock Admin role appears later).
	 *
	 * @param bool $force Grant even if already recorded.
	 * @return void
	 */
	public static function maybe_grant_caps( $force = false ) {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}
		$have = get_option( 'css_tc_caps_version', '' );
		$seen = (array) get_option( 'css_tc_caps_roles', array() );
		$todo = array();
		foreach ( self::manager_roles() as $role_name ) {
			if ( $force || self::CAPS_VERSION !== $have || ! in_array( $role_name, $seen, true ) ) {
				$todo[] = $role_name;
			}
		}
		if ( empty( $todo ) ) {
			return;
		}
		foreach ( $todo as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::MANAGE_CAP );
				$seen[] = $role_name;
			}
		}
		update_option( 'css_tc_caps_version', self::CAPS_VERSION, true );
		update_option( 'css_tc_caps_roles', array_values( array_unique( $seen ) ), true );

		// The signed-in user's capabilities may already be loaded for this
		// request; refresh them so the first page after an upgrade works.
		if ( function_exists( 'wp_get_current_user' ) && did_action( 'set_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user && $user->exists() ) {
				$user->get_role_caps();
			}
		}
	}

	/**
	 * Tell a manager when kiosk punches were refused in the last hour.
	 *
	 * @return void
	 */
	public function maybe_refused_kiosk_notice() {
		if ( ! is_admin() || ! self::user_can_manage() ) {
			return;
		}
		$summary = $this->pins->refused_kiosk_summary( 3600 );
		if ( empty( $summary ) ) {
			return;
		}
		$dismissed = get_user_meta( get_current_user_id(), 'css_tc_refused_dismissed', true );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();
		$shown     = 0;
		foreach ( $summary as $row ) {
			if ( $shown >= 5 ) {
				break;
			}
			$ip = $row['ip'];
			if ( isset( $dismissed[ $ip ] ) && (int) $dismissed[ $ip ] >= (int) $row['latest'] ) {
				continue;
			}
			$add_url     = wp_nonce_url( admin_url( 'admin-post.php?action=css_tc_allow_refused_ip&ip=' . rawurlencode( $ip ) ), 'css_tc_allow_ip_' . $ip );
			$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=css_tc_dismiss_refused&ip=' . rawurlencode( $ip ) ), 'css_tc_dismiss_refused_' . $ip );
			echo '<div class="notice notice-warning css-tc-refused-notice"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: number of refused requests, 2: IP address */
					_n( '%1$d kiosk request was refused from %2$s in the last hour. Did an office IP change?', '%1$d kiosk requests were refused from %2$s in the last hour. Did an office IP change?', (int) $row['count'], 'css-timeclock-addon' ),
					(int) $row['count'],
					$ip
				)
			);
			echo ' ' . esc_html__( 'Only add it if you know it is one of your offices; for an office on dynamic DNS, list its hostname instead.', 'css-timeclock-addon' );
			echo '</p><p><a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add this IP to the allowlist', 'css-timeclock-addon' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'css-timeclock-addon' ) . '</a></p></div>';
			++$shown;
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules Cron schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['css_tc_five_minutes'] = array(
			'interval' => Css_Tc_Pins::DNS_REFRESH,
			'display'  => 'Every five minutes (SMOTC office hostnames)',
		);
		return $schedules;
	}

	/**
	 * Every hostname in the office allowlist or a location's office network.
	 *
	 * @return string[]
	 */
	public function office_hosts() {
		$settings = $this->get_settings();
		$hosts    = $this->pins->parse_allowlist( (string) ( $settings['ip_allowlist'] ?? '' ) )['hosts'];
		return array_values( array_unique( array_merge( $hosts, $this->organization->location_hosts() ) ) );
	}

	/**
	 * Keep office hostnames looked up in the background, only while any exist.
	 *
	 * @return void
	 */
	public function schedule_dns_refresh() {
		$next = wp_next_scheduled( 'css_tc_refresh_dns' );
		$want = ! empty( $this->office_hosts() );
		if ( $want && ! $next ) {
			wp_schedule_event( time() + 60, 'css_tc_five_minutes', 'css_tc_refresh_dns' );
		} elseif ( ! $want && $next ) {
			wp_clear_scheduled_hook( 'css_tc_refresh_dns' );
		}
	}

	/**
	 * @return void
	 */
	public function refresh_dns() {
		$this->pins->refresh_hosts( $this->office_hosts() );
	}

	/**
	 * Capability used for SMOTC admin screens.
	 *
	 * With AIO active this is css_tc_manage (administrators and AIO's Time
	 * Clock Admin role). AIO itself only asks for edit_posts, which every
	 * Contributor, Author and Editor has — too broad for payroll and PINs.
	 * Without AIO, Settings → manage_options.
	 *
	 * @return string
	 */
	public static function admin_capability() {
		if ( self::aio_is_active() ) {
			return self::MANAGE_CAP;
		}
		return 'manage_options';
	}

	/**
	 * @return bool
	 */
	public static function user_can_manage() {
		return current_user_can( 'manage_options' ) || current_user_can( self::MANAGE_CAP );
	}

	/**
	 * Time Clock admin pages (AIO's and SMOTC's) are for managers only.
	 * AIO registers them with edit_posts; turn everyone else away.
	 *
	 * @return void
	 */
	public function guard_admin_pages() {
		if ( ! is_admin() || wp_doing_ajax() || ! self::aio_is_active() ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page ) {
			return;
		}
		if ( 0 !== strpos( $page, 'aio-' ) && 0 !== strpos( $page, 'css-tc-' ) ) {
			return;
		}
		if ( self::user_can_manage() ) {
			return;
		}
		wp_die( esc_html__( 'Only time clock managers can open this page.', 'css-timeclock-addon' ), '', array( 'response' => 403 ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'css-timeclock-addon', false, dirname( CSS_TC_ADDON_BASENAME ) . '/languages' );
	}

	public function register_runtime() {
		$this->corrections->register();
		$this->corrections->maybe_upgrade_schema();
		Css_Tc_Ajax::register();
		Css_Tc_Admin::register();
		$this->reports->register_hooks();
		$this->organization->register_hooks();
		Css_Tc_Shortcodes::register();
	}

	/**
	 * Upgrades from 1.1.x get the employee times page without a reactivation.
	 *
	 * @return void
	 */
	public function maybe_create_times_page() {
		if ( ! self::user_can_manage() ) {
			return;
		}
		$settings = $this->get_settings();
		$page_id  = isset( $settings['employee_times_page_id'] ) ? (int) $settings['employee_times_page_id'] : 0;
		if ( $page_id && get_post_status( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
			return;
		}
		Css_Tc_Shortcodes::create_public_pages();
	}

	public function maybe_missing_aio_notice() {
		if ( ! self::user_can_manage() ) {
			return;
		}
		if ( self::aio_is_active() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$relevant = in_array( $screen->id, array( 'plugins', 'dashboard' ), true )
			|| ( isset( $screen->base ) && false !== strpos( (string) $screen->id, 'css-tc' ) )
			|| ( isset( $screen->parent_base ) && 'aio-tc-lite' === $screen->parent_base );

		if ( ! $relevant && isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'css-tc-addon' !== $page ) {
				return;
			}
		} elseif ( ! $relevant ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'SMOTC is a soft add-on for SMOTC Core. Install and activate SMOTC Core so Real Time Monitoring, employee roles, and shift reports stay in sync. Kiosk punches still write compatible shift posts if it is missing.', 'css-timeclock-addon' );
		echo '</p></div>';
	}

	/**
	 * @param array<string,string> $links Plugin row links.
	 * @return array<string,string>
	 */
	public function plugin_action_links( $links ) {
		$url = Css_Tc_Admin::settings_url();
		$links['settings'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Kiosk settings', 'css-timeclock-addon' ) . '</a>';
		return $links;
	}

	/**
	 * Activation: defaults + kiosk pages.
	 *
	 * @return void
	 */
	public static function activate() {
		$existing = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			add_option( self::OPTION_KEY, self::default_settings(), '', false );
		} else {
			update_option( self::OPTION_KEY, wp_parse_args( $existing, self::default_settings() ), false );
		}

		Css_Tc_Shortcodes::create_public_pages();
		css_tc_addon()->corrections->maybe_upgrade_schema();
		self::maybe_grant_caps( true );
	}

	/**
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'css_tc_refresh_dns' );
		// Pages and hashed PINs are left in place so reactivation is non-destructive.
	}
}
