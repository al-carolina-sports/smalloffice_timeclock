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
		add_filter( 'template_include', array( __CLASS__, 'wide_template' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_wide_layout' ), 999 );
	}

	/**
	 * Send AIO's logged-in clock page to an USOTC kiosk.
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
		if ( ! $post instanceof WP_Post || ! ( self::is_aio_clock_page( $post ) || self::is_name_kiosk_page( $post ) ) ) {
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
	 * The retired name-list kiosk page (its shortcode alone, or the page
	 * USOTC created for it).
	 *
	 * @param WP_Post $post Page being viewed.
	 * @return bool
	 */
	public static function is_name_kiosk_page( $post ) {
		$settings = css_tc_addon()->get_settings();
		if ( ! empty( $settings['name_kiosk_page_id'] ) && (int) $settings['name_kiosk_page_id'] === (int) $post->ID ) {
			return true;
		}
		return 'name-time-clock' === $post->post_name;
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
	 * The main time clock page: the PIN kiosk.
	 *
	 * @return string
	 */
	public static function preferred_kiosk_url() {
		$settings = css_tc_addon()->get_settings();
		$url      = self::page_url( isset( $settings['pin_kiosk_page_id'] ) ? (int) $settings['pin_kiosk_page_id'] : 0, 'pin-time-clock' );
		return $url ? $url : home_url( '/pin-time-clock/' );
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
	 * Front-end pages that host an USOTC shortcode.
	 *
	 * @param WP_Post|null $post Page.
	 * @return bool
	 */
	public static function is_smotc_front_page( $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			$queried = get_queried_object();
			$post    = $queried instanceof WP_Post ? $queried : null;
		}
		if ( ! $post || 'page' !== $post->post_type ) {
			return false;
		}
		$content = (string) $post->post_content;
		foreach ( array( 'css_tc_my_times', 'css_tc_pin_kiosk', 'css_tc_name_kiosk' ) as $tag ) {
			if ( has_shortcode( $content, $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this request should replace the theme template.
	 *
	 * A CSS breakout (100vw / negative margins) still sits inside Twenty Fifteen's
	 * sidebar column and is clipped by theme wrappers that set overflow. Owning
	 * the page template avoids that. The setting puts the theme template back.
	 *
	 * @return bool
	 */
	public static function uses_wide_layout() {
		if ( is_admin() || wp_doing_ajax() || ! is_singular( 'page' ) ) {
			return false;
		}
		$settings = css_tc_addon()->get_settings();
		if ( empty( $settings['wide_layout'] ) ) {
			return false;
		}
		return self::is_smotc_front_page();
	}

	/**
	 * @param string $template Theme template path.
	 * @return string
	 */
	public static function wide_template( $template ) {
		if ( ! self::uses_wide_layout() ) {
			return $template;
		}
		$ours = CSS_TC_ADDON_DIR . 'public/views/wide-layout.php';
		return file_exists( $ours ) ? $ours : $template;
	}

	/**
	 * Drop the active theme's styles so its column and sidebar cannot shrink the sheet.
	 *
	 * @return void
	 */
	public static function enqueue_wide_layout() {
		if ( ! self::uses_wide_layout() ) {
			return;
		}

		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
		wp_dequeue_style( 'global-styles' );
		wp_dequeue_style( 'classic-theme-styles' );

		global $wp_styles;
		if ( $wp_styles instanceof WP_Styles ) {
			foreach ( $wp_styles->registered as $handle => $style ) {
				$src = isset( $style->src ) ? (string) $style->src : '';
				if ( '' !== $src && false !== strpos( $src, '/themes/' ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}

		wp_enqueue_style(
			'css-tc-wide-layout',
			CSS_TC_ADDON_URL . 'public/css/wide-layout.css',
			array(),
			CSS_TC_ADDON_ASSET_VERSION
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
		if ( self::uses_wide_layout() ) {
			$classes[] = 'css-tc-wide-layout';
		}
		return $classes;
	}

	/**
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function pin_kiosk( $atts ) {
		$atts     = shortcode_atts( array( 'location' => '' ), $atts, 'css_tc_pin_kiosk' );
		$location = self::location_attr( (string) $atts['location'] );
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
		$atts     = shortcode_atts( array( 'location' => '' ), $atts, 'css_tc_name_kiosk' );
		$location = self::location_attr( (string) $atts['location'] );
		$settings = css_tc_addon()->get_settings();
		self::enqueue_assets();

		// The PIN kiosk now lets people tap their name on the board, so the
		// separate name-list kiosk is retired. Old pages keep working.
		ob_start();
		$enabled = ! empty( $settings['pin_kiosk_enabled'] );
		$mode    = 'pin';
		include CSS_TC_ADDON_DIR . 'public/views/pin-kiosk.php';
		return (string) ob_get_clean();
	}

	/**
	 * Kiosk page location from the shortcode attribute: an ID or a name.
	 *
	 * @param string $value Attribute value.
	 * @return int Location ID or 0.
	 */
	private static function location_attr( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		$org = css_tc_addon()->organization;
		if ( ctype_digit( $value ) && $org->location( (int) $value ) ) {
			return (int) $value;
		}
		foreach ( $org->locations() as $row ) {
			if ( 0 === strcasecmp( (string) $row['name'], $value ) ) {
				return (int) $row['id'];
			}
		}
		return 0;
	}

	/**
	 * My Time Clock page: saved setting, else the page that contains the shortcode, else home.
	 *
	 * @return string
	 */
	public static function employee_times_url() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$settings = css_tc_addon()->get_settings();
		$page_id  = isset( $settings['employee_times_page_id'] ) ? (int) $settings['employee_times_page_id'] : 0;
		if ( $page_id > 0 ) {
			$link = get_permalink( $page_id );
			if ( $link ) {
				$cached = (string) $link;
				return $cached;
			}
		}

		global $wpdb;
		$like = '%' . $wpdb->esc_like( '[css_tc_my_times' ) . '%';
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
				$like
			)
		);
		if ( $found > 0 ) {
			$link = get_permalink( $found );
			if ( $link ) {
				$cached = (string) $link;
				return $cached;
			}
		}

		$cached = home_url( '/' );
		return $cached;
	}

	/**
	 * Permalink of the page being viewed, keeping the corrections query.
	 *
	 * @return string
	 */
	public static function current_front_url() {
		$url = '';
		if ( is_singular() ) {
			$url = (string) get_permalink();
		}
		if ( '' === $url ) {
			$url = home_url( '/' );
		}
		if ( isset( $_GET['css_tc_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$view = sanitize_key( wp_unslash( $_GET['css_tc_view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'correct' === $view ) {
				$url = add_query_arg( 'css_tc_view', 'correct', $url );
			}
		}
		return $url;
	}

	/**
	 * Small links so a kiosk or timecard page can reach login, the timecard, or admin.
	 *
	 * @param string $context `kiosk` or `times`.
	 * @return void
	 */
	public static function render_staff_nav( $context = 'times' ) {
		$times = self::employee_times_url();
		$links = array();

		if ( ! is_user_logged_in() ) {
			$redirect = ( 'kiosk' === $context ) ? $times : self::current_front_url();
			$links[]  = array(
				'url'   => wp_login_url( $redirect ),
				'label' => __( 'Staff login', 'css-timeclock-addon' ),
			);
		} else {
			$links[] = array(
				'url'   => $times,
				'label' => __( 'My timecard', 'css-timeclock-addon' ),
			);
			if ( Css_Tc_Plugin::user_can_manage() ) {
				$links[] = array(
					'url'   => Css_Tc_Admin::timecards_url(),
					'label' => __( 'Admin', 'css-timeclock-addon' ),
				);
			}
			if ( 'kiosk' === $context ) {
				$links[] = array(
					'url'   => wp_logout_url( self::current_front_url() ),
					'label' => __( 'Log out', 'css-timeclock-addon' ),
				);
			} else {
				$kiosk = self::preferred_kiosk_url();
				if ( $kiosk ) {
					$links[] = array(
						'url'   => $kiosk,
						'label' => __( 'Time clock', 'css-timeclock-addon' ),
					);
				}
			}
		}

		$css_tc_staff_links = $links;
		include CSS_TC_ADDON_DIR . 'public/views/staff-nav.php';
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
		$base     = ( $page_id && get_permalink( $page_id ) ) ? (string) get_permalink( $page_id ) : self::employee_times_url();
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $period_start ) ) {
			$base = add_query_arg( 'period', $period_start, $base );
		}
		return $base;
	}

	/**
	 * Employee time off page (request calendar and history).
	 *
	 * @param string $hash Optional section id.
	 * @return string
	 */
	public static function timeoff_url( $hash = '' ) {
		return add_query_arg( 'css_tc_view', 'timeoff', self::times_url() ) . ( '' !== $hash ? '#' . $hash : '' );
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
		$login_url = wp_login_url( self::current_front_url() );

		$view = 'timecard';
		if ( isset( $_GET['css_tc_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = sanitize_key( wp_unslash( $_GET['css_tc_view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'correct' === $requested ) {
				$view = 'correct';
			} elseif ( 'timeoff' === $requested && css_tc_addon()->leave->any_enabled() ) {
				$view = 'timeoff';
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
			} elseif ( isset( $_GET['css_tc_notice'] ) && 'cancelled' === sanitize_key( wp_unslash( $_GET['css_tc_notice'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$notice = __( 'Request cancelled. The punches were not changed.', 'css-timeclock-addon' );
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
			CSS_TC_ADDON_ASSET_VERSION
		);
		wp_enqueue_style(
			'css-tc-timecard',
			CSS_TC_ADDON_URL . 'public/css/timecard.css',
			array( 'css-tc-times' ),
			CSS_TC_ADDON_ASSET_VERSION
		);

		if ( ! is_user_logged_in() || ! css_tc_addon()->employees->can_view_own_times( get_current_user_id() ) ) {
			return;
		}

		wp_enqueue_script(
			'css-tc-timecard',
			CSS_TC_ADDON_URL . 'public/js/timecard.js',
			array(),
			CSS_TC_ADDON_ASSET_VERSION,
			true
		);

		if ( css_tc_addon()->leave->any_enabled() ) {
			wp_enqueue_script( 'css-tc-timeoff', CSS_TC_ADDON_URL . 'public/js/timeoff.js', array(), CSS_TC_ADDON_ASSET_VERSION, true );
			wp_localize_script(
				'css-tc-timeoff',
				'cssTcTimeoff',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( Css_Tc_Corrections::EMPLOYEE_NONCE ),
					'locale'  => str_replace( '_', '-', get_locale() ),
					'strings' => array(
						'total'       => __( 'Total', 'css-timeclock-addon' ),
						'used'        => __( 'Used', 'css-timeclock-addon' ),
						'pending'     => __( 'Pending', 'css-timeclock-addon' ),
						'remaining'   => __( 'Remaining', 'css-timeclock-addon' ),
						'days'        => __( 'days', 'css-timeclock-addon' ),
						'leaveYear'   => __( 'Leave year', 'css-timeclock-addon' ),
						'usableFrom'  => __( 'You can use time off from %s.', 'css-timeclock-addon' ),
						'noHire'      => __( 'Your time off starts once a manager sets your hire date.', 'css-timeclock-addon' ),
						'notYet'      => __( 'Not available yet', 'css-timeclock-addon' ),
						'notice'      => __( 'PTO needs %d days\' notice', 'css-timeclock-addon' ),
						'closed'      => __( 'Closed pay period', 'css-timeclock-addon' ),
						'holiday'     => __( 'Holiday', 'css-timeclock-addon' ),
						'pendingDay'  => __( 'requested', 'css-timeclock-addon' ),
						'approvedDay' => __( 'approved', 'css-timeclock-addon' ),
						'fullDay'     => __( 'Full day', 'css-timeclock-addon' ),
						'perDay'      => __( 'per day', 'css-timeclock-addon' ),
						'pick'        => __( 'Tap the days you want off.', 'css-timeclock-addon' ),
						'picked'      => __( '%1$d day(s), %2$s in total', 'css-timeclock-addon' ),
						'send'        => __( 'Send request', 'css-timeclock-addon' ),
						'sending'     => __( 'Sending…', 'css-timeclock-addon' ),
						'cancel'      => __( 'Cancel request', 'css-timeclock-addon' ),
						'cancelSure'  => __( 'Tap again to cancel', 'css-timeclock-addon' ),
						'none'        => __( 'No time off requests yet.', 'css-timeclock-addon' ),
						'status'      => array(
							'pending'   => __( 'Waiting for approval', 'css-timeclock-addon' ),
							'approved'  => __( 'Approved', 'css-timeclock-addon' ),
							'denied'    => __( 'Denied', 'css-timeclock-addon' ),
							'cancelled' => __( 'Cancelled', 'css-timeclock-addon' ),
						),
						'addedByManager' => __( 'Added by a manager', 'css-timeclock-addon' ),
						'reason'      => __( 'Reason:', 'css-timeclock-addon' ),
						'network'     => __( 'Could not reach the time clock. Try again.', 'css-timeclock-addon' ),
						'prev'        => __( 'Previous month', 'css-timeclock-addon' ),
						'next'        => __( 'Next month', 'css-timeclock-addon' ),
						'oneBank'     => __( 'Sick time comes out of your PTO.', 'css-timeclock-addon' ),
						'onLeave'     => __( 'On leave — ask a manager', 'css-timeclock-addon' ),
						'inactive'    => __( 'After your last day', 'css-timeclock-addon' ),
					),
				)
			);
		}
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
			CSS_TC_ADDON_ASSET_VERSION
		);

		wp_enqueue_script(
			'css-tc-kiosk',
			CSS_TC_ADDON_URL . 'public/js/kiosk.js',
			array(),
			CSS_TC_ADDON_ASSET_VERSION,
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
					'hiEnterPin'     => __( 'Hi %s — enter your PIN', 'css-timeclock-addon' ),
					'tapName'        => __( 'Tap your name to clock in or out', 'css-timeclock-addon' ),
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
					'noEmployees'    => __( 'No employees have a PIN yet. A supervisor can set PINs under USOTC.', 'css-timeclock-addon' ),
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
					'chooseWhere'    => __( 'Where are you working?', 'css-timeclock-addon' ),
					'switchTo'       => __( 'Switch to…', 'css-timeclock-addon' ),
					'switchAway'     => __( 'You are still clocked in at %1$s. Switch to %2$s?', 'css-timeclock-addon' ),
					'workingAt'      => __( 'Working:', 'css-timeclock-addon' ),
					'successSwitch'  => __( 'Switched.', 'css-timeclock-addon' ),
					'notHere'        => __( 'You are not set up for this location. Pick where you are working.', 'css-timeclock-addon' ),
					'home'           => __( 'Home', 'css-timeclock-addon' ),
				),
			)
		);
	}
}
