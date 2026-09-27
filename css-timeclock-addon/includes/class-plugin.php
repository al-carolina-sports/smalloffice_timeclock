<?php
/**
 * Main plugin controller.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once CSS_TC_ADDON_DIR . 'includes/class-time.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-pay-codes.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-pay-periods.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-employees.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-pins.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-punches.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-corrections.php';
require_once CSS_TC_ADDON_DIR . 'includes/class-timecard.php';
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
		$this->employees   = new Css_Tc_Employees();
		$this->pins        = new Css_Tc_Pins();
		$this->punches     = new Css_Tc_Punches();
		$this->punches->register_hooks();
		$this->corrections = new Css_Tc_Corrections();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_runtime' ) );
		Css_Tc_Branding::register();
		add_action( 'admin_init', array( $this, 'maybe_create_times_page' ) );
		add_action( 'admin_notices', array( $this, 'maybe_missing_aio_notice' ) );
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
			'idle_reset_ms'      => 8000,
			'pin_kiosk_page_id'       => 0,
			'name_kiosk_page_id'      => 0,
			'employee_times_page_id'  => 0,
			'times_lookback_days'     => 21,
			'pay_period_length'       => 'biweekly',
			'pay_period_anchor'       => '2026-09-07',
			'missed_clock_out_hours'  => 16,
			'long_shift_hours'        => 16,
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
	 * Capability used for admin screens.
	 *
	 * Matches AIO's Time Clock menu (`edit_posts`) so Time Clock Admins can
	 * manage PINs. Falls back to Settings → manage_options when AIO is absent.
	 *
	 * @return string
	 */
	public static function admin_capability() {
		if ( self::aio_is_active() ) {
			return 'edit_posts';
		}
		return 'manage_options';
	}

	/**
	 * @return bool
	 */
	public static function user_can_manage() {
		if ( current_user_can( 'manage_options' ) || current_user_can( self::admin_capability() ) ) {
			return true;
		}
		$user = wp_get_current_user();
		return $user && in_array( 'time_clock_admin', (array) $user->roles, true );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'css-timeclock-addon', false, dirname( CSS_TC_ADDON_BASENAME ) . '/languages' );
	}

	public function register_runtime() {
		$this->corrections->register();
		Css_Tc_Ajax::register();
		Css_Tc_Admin::register();
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
		$url = current_user_can( 'manage_options' )
			? admin_url( 'options-general.php?page=css-tc-addon' )
			: admin_url( 'admin.php?page=css-tc-addon' );
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
	}

	/**
	 * @return void
	 */
	public static function deactivate() {
		// Pages and hashed PINs are left in place so reactivation is non-destructive.
	}
}
