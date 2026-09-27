<?php
/**
 * Kiosk shortcodes and page creation.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [css_tc_pin_kiosk], [css_tc_name_kiosk], and [css_tc_my_times].
 */
class Css_Tc_Shortcodes {

	/**
	 * @var bool
	 */
	private static $assets_queued = false;

	/**
	 * @var bool
	 */
	private static $times_assets_queued = false;

	/**
	 * @return void
	 */
	public static function register() {
		add_shortcode( 'css_tc_pin_kiosk', array( __CLASS__, 'pin_kiosk' ) );
		add_shortcode( 'css_tc_name_kiosk', array( __CLASS__, 'name_kiosk' ) );
		add_shortcode( 'css_tc_my_times', array( __CLASS__, 'my_times' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_aio_clock_page' ) );
	}

	/**
	 * Send AIO's logged-in clock page to an SMOTC kiosk.
	 *
	 * AIO's widget stores wp_date() (site-local). After the site timezone leaves
	 * UTC those strings are not UTC. The kiosk already writes UTC. The AJAX
	 * action is still normalized if something calls it directly.
	 *
	 * @return void
	 */
	public static function redirect_aio_clock_page() {
		if ( is_admin() || wp_doing_ajax() || ! is_singular( 'page' ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! self::is_aio_clock_page( $post ) ) {
			return;
		}

		$target = self::preferred_kiosk_url();
		$here   = get_permalink( $post );
		if ( ! $target || ! $here ) {
			return;
		}
		if ( untrailingslashit( $target ) === untrailingslashit( $here ) ) {
			return;
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * @param WP_Post $post Page being viewed.
	 * @return bool
	 */
	public static function is_aio_clock_page( $post ) {
		if ( 'time-clock' === $post->post_name ) {
			return true;
		}
		return has_shortcode( (string) $post->post_content, 'show_aio_time_clock_lite' );
	}

	/**
	 * Name kiosk when it is enabled, otherwise the PIN kiosk.
	 *
	 * @return string
	 */
	public static function preferred_kiosk_url() {
		$settings = css_tc_addon()->get_settings();
		$name_on  = ! empty( $settings['name_kiosk_enabled'] );
		$pin_on   = ! empty( $settings['pin_kiosk_enabled'] );

		if ( $name_on ) {
			$url = self::page_url( isset( $settings['name_kiosk_page_id'] ) ? (int) $settings['name_kiosk_page_id'] : 0, 'name-time-clock' );
			if ( $url ) {
				return $url;
			}
		}
		if ( $pin_on || ! $name_on ) {
			$url = self::page_url( isset( $settings['pin_kiosk_page_id'] ) ? (int) $settings['pin_kiosk_page_id'] : 0, 'pin-time-clock' );
			if ( $url ) {
				return $url;
			}
		}

		return home_url( '/name-time-clock/' );
	}

	/**
	 * @param int    $page_id  Saved page ID.
	 * @param string $slug     Fallback slug.
	 * @return string
	 */
	private static function page_url( $page_id, $slug ) {
		if ( $page_id > 0 ) {
			$link = get_permalink( $page_id );
			if ( $link ) {
				return (string) $link;
			}
		}
		return home_url( '/' . $slug . '/' );
	}

	/**
	 * @return array<string,int>
	 */
	public static function create_kiosk_pages() {
		return self::create_public_pages();
	}

	/**
	 * Create (or reuse) public pages that host the kiosk and employee shortcodes.
	 *
	 * @return array{pin_kiosk_page_id:int,name_kiosk_page_id:int,employee_times_page_id:int}
	 */
	public static function create_public_pages() {
		$plugin   = css_tc_addon();
		$settings = $plugin->get_settings();

		$pages = array(
			'pin_kiosk_page_id'      => array(
				'title'   => __( 'PIN Time Clock', 'css-timeclock-addon' ),
				'slug'    => 'pin-time-clock',
				'content' => '[css_tc_pin_kiosk]',
			),
			'name_kiosk_page_id'     => array(
				'title'   => __( 'Name Time Clock', 'css-timeclock-addon' ),
				'slug'    => 'name-time-clock',
				'content' => '[css_tc_name_kiosk]',
			),
			'employee_times_page_id' => array(
				'title'   => __( 'My Time Clock', 'css-timeclock-addon' ),
				'slug'    => 'my-time-clock',
				'content' => '[css_tc_my_times]',
			),
		);

		foreach ( $pages as $option_key => $spec ) {
			$existing_id = isset( $settings[ $option_key ] ) ? (int) $settings[ $option_key ] : 0;
			if ( $existing_id && get_post_status( $existing_id ) ) {
				$status = get_post_status( $existing_id );
				if ( 'trash' !== $status ) {
					continue;
				}
			}

			$found = get_page_by_path( $spec['slug'] );
			if ( $found && 'trash' !== $found->post_status ) {
				$settings[ $option_key ] = (int) $found->ID;
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => $spec['title'],
					'post_name'    => $spec['slug'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => $spec['content'],
				),
				true
			);

			if ( ! is_wp_error( $page_id ) ) {
				$settings[ $option_key ] = (int) $page_id;
			}
		}

		$plugin->update_settings( $settings );

		return array(
			'pin_kiosk_page_id'      => (int) $settings['pin_kiosk_page_id'],
			'name_kiosk_page_id'     => (int) $settings['name_kiosk_page_id'],
			'employee_times_page_id' => (int) $settings['employee_times_page_id'],
		);
	}

	/**
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public static function body_class( $classes ) {
		if ( ! is_singular() ) {
			return $classes;
		}
		$post = get_post();
		if ( ! $post ) {
			return $classes;
		}
		if ( has_shortcode( $post->post_content, 'css_tc_pin_kiosk' ) || has_shortcode( $post->post_content, 'css_tc_name_kiosk' ) ) {
			$classes[] = 'css-tc-kiosk-page';
		}
		if ( has_shortcode( $post->post_content, 'css_tc_my_times' ) ) {
			$classes[] = 'css-tc-times-page';
		}
		return $classes;
	}

	/**
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function pin_kiosk( $atts ) {
		unset( $atts );
		$settings = css_tc_addon()->get_settings();
		self::enqueue_assets();

		ob_start();
		$enabled = ! empty( $settings['pin_kiosk_enabled'] );
		$mode    = 'pin';
		include CSS_TC_ADDON_DIR . 'public/views/pin-kiosk.php';
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function name_kiosk( $atts ) {
		unset( $atts );
		$settings = css_tc_addon()->get_settings();
		self::enqueue_assets();

		ob_start();
		$enabled = ! empty( $settings['name_kiosk_enabled'] );
		$mode    = 'name';
		include CSS_TC_ADDON_DIR . 'public/views/name-kiosk.php';
		return (string) ob_get_clean();
	}

	/**
	 * Front-end URL of the employee timecard page.
	 *
	 * @param string $period_start Optional Y-m-d period start.
	 * @return string
	 */
	public static function times_url( $period_start = '' ) {
		$settings = css_tc_addon()->get_settings();
		$page_id  = isset( $settings['employee_times_page_id'] ) ? (int) $settings['employee_times_page_id'] : 0;
		$base     = ( $page_id && get_permalink( $page_id ) ) ? (string) get_permalink( $page_id ) : home_url( '/my-time-clock/' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $period_start ) ) {
			$base = add_query_arg( 'period', $period_start, $base );
		}
		return $base;
	}

	/**
	 * Corrections form for the current pay period only.
	 *
	 * @param string $day Optional Y-m-d hash target.
	 * @return string
	 */
	public static function correct_url( $day = '' ) {
		$url = add_query_arg( 'css_tc_view', 'correct', self::times_url() );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) ) {
			$url .= '#day-' . $day;
		}
		return $url;
	}

	/**
	 * Logged-in employee timecard. Corrections are limited to the open pay period.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function my_times( $atts ) {
		unset( $atts );
		self::enqueue_times_assets();

		$user_id   = get_current_user_id();
		$logged_in = $user_id > 0;
		$allowed   = $logged_in && css_tc_addon()->employees->can_view_own_times( $user_id );
		$login_url = wp_login_url( self::times_url() );

		$view = 'timecard';
		if ( isset( $_GET['css_tc_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = sanitize_key( wp_unslash( $_GET['css_tc_view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'correct' === $requested ) {
				$view = 'correct';
			}
		}

		$period_start = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$period       = $allowed ? css_tc_addon()->pay_periods->period_by_start( $period_start ) : null;
		if ( $allowed && ! $period ) {
			$period = css_tc_addon()->pay_periods->current_period();
		}

		$sheet      = null;
		$form       = null;
		$form_error = '';
		$notice     = '';
		if ( $allowed && 'correct' === $view ) {
			$form = css_tc_addon()->timecard->form_days( $user_id );
			$form_error = (string) get_transient( 'css_tc_period_error_' . $user_id );
			if ( '' !== $form_error ) {
				delete_transient( 'css_tc_period_error_' . $user_id );
			}
		} elseif ( $allowed && $period ) {
			$sheet = css_tc_addon()->timecard->build( $user_id, $period );
			if ( isset( $_GET['css_tc_notice'] ) && 'sent' === sanitize_key( wp_unslash( $_GET['css_tc_notice'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$notice = __( 'Suggestions sent. A supervisor will review them before any punch changes.', 'css-timeclock-addon' );
			}
			$form_error = (string) get_transient( 'css_tc_period_error_' . $user_id );
			if ( '' !== $form_error ) {
				delete_transient( 'css_tc_period_error_' . $user_id );
			}
		}

		ob_start();
		include CSS_TC_ADDON_DIR . 'public/views/my-times.php';
		return (string) ob_get_clean();
	}

	/**
	 * @return void
	 */
	private static function enqueue_times_assets() {
		if ( self::$times_assets_queued ) {
			return;
		}
		self::$times_assets_queued = true;

		wp_enqueue_style(
			'css-tc-times',
			CSS_TC_ADDON_URL . 'public/css/times.css',
			array(),
			CSS_TC_ADDON_VERSION
		);
		wp_enqueue_style(
			'css-tc-timecard',
			CSS_TC_ADDON_URL . 'public/css/timecard.css',
			array( 'css-tc-times' ),
			CSS_TC_ADDON_VERSION
		);

		if ( ! is_user_logged_in() || ! css_tc_addon()->employees->can_view_own_times( get_current_user_id() ) ) {
			return;
		}

		wp_enqueue_script(
			'css-tc-timecard',
			CSS_TC_ADDON_URL . 'public/js/timecard.js',
			array(),
			CSS_TC_ADDON_VERSION,
			true
		);
	}

	/**
	 * @return void
	 */
	private static function enqueue_assets() {
		if ( self::$assets_queued ) {
			return;
		}
		self::$assets_queued = true;

		$settings = css_tc_addon()->get_settings();

		wp_enqueue_style(
			'css-tc-kiosk',
			CSS_TC_ADDON_URL . 'public/css/kiosk.css',
			array(),
			CSS_TC_ADDON_VERSION
		);

		wp_enqueue_script(
			'css-tc-kiosk',
			CSS_TC_ADDON_URL . 'public/js/kiosk.js',
			array(),
			CSS_TC_ADDON_VERSION,
			true
		);

		wp_localize_script(
			'css-tc-kiosk',
			'cssTcKiosk',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( Css_Tc_Ajax::PUBLIC_NONCE ),
				'pinMin'         => (int) $settings['pin_min_length'],
				'pinMax'         => (int) $settings['pin_max_length'],
				'idleResetMs'    => (int) $settings['idle_reset_ms'],
				'pinEnabled'     => ! empty( $settings['pin_kiosk_enabled'] ),
				'nameEnabled'    => ! empty( $settings['name_kiosk_enabled'] ),
				'boardRefreshMs' => 20000,
				'strings'        => array(
					'enterPin'       => __( 'Enter your PIN', 'css-timeclock-addon' ),
					'confirmPin'     => __( 'Confirm with your PIN', 'css-timeclock-addon' ),
					'clockIn'        => __( 'Clock in', 'css-timeclock-addon' ),
					'clockOut'       => __( 'Clock out', 'css-timeclock-addon' ),
					'workingSince'   => __( 'Clocked in since', 'css-timeclock-addon' ),
					'hello'          => __( 'Hello', 'css-timeclock-addon' ),
					'successIn'      => __( 'You are clocked in.', 'css-timeclock-addon' ),
					'successOut'     => __( 'You are clocked out.', 'css-timeclock-addon' ),
					'shiftTotal'     => __( 'Shift time', 'css-timeclock-addon' ),
					'badPin'         => __( 'That PIN was not recognized.', 'css-timeclock-addon' ),
					'network'        => __( 'Could not reach the time clock. Try again.', 'css-timeclock-addon' ),
					'disabled'       => __( 'This kiosk is turned off.', 'css-timeclock-addon' ),
					'noEmployees'    => __( 'No employees have a PIN yet. A supervisor can set PINs under SMOTC.', 'css-timeclock-addon' ),
					'search'         => __( 'Search names', 'css-timeclock-addon' ),
					'cancel'         => __( 'Cancel', 'css-timeclock-addon' ),
					'clear'          => __( 'Clear', 'css-timeclock-addon' ),
					'back'           => __( 'Back', 'css-timeclock-addon' ),
					'workingNow'     => __( 'Working now', 'css-timeclock-addon' ),
					'notClockedIn'   => __( 'Not clocked in', 'css-timeclock-addon' ),
					'nobodyIn'       => __( 'Nobody is clocked in.', 'css-timeclock-addon' ),
					'everyoneIn'     => __( 'Everyone is clocked in.', 'css-timeclock-addon' ),
					'updatedAt'      => __( 'Updated', 'css-timeclock-addon' ),
					'boardError'     => __( 'Could not load who is working.', 'css-timeclock-addon' ),
				),
			)
		);
	}
}
