<?php
/**
 * Ways for a logged-in employee to open My Time Clock.
 *
 * Dashboard widget, admin menu, toolbar, and a front-end menu item.
 * There is no login redirect.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links to the published page that contains [css_tc_my_times].
 */
class Css_Tc_Reach {

	const PAGE_CACHE = 'css_tc_my_times_page_id';
	const WIDGET_ID  = 'css_tc_my_time_clock';

	/**
	 * @return void
	 */
	public static function register() {
		$self = new self();
		add_action( 'wp_dashboard_setup', array( $self, 'register_dashboard_widget' ) );
		add_filter( 'get_user_option_meta-box-order_dashboard', array( $self, 'pin_dashboard_widget' ) );
		add_action( 'admin_menu', array( $self, 'register_admin_menu' ), 9 );
		add_action( 'admin_bar_menu', array( $self, 'register_admin_bar' ), 80 );
		add_filter( 'wp_nav_menu_items', array( $self, 'nav_menu_items' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( $self, 'enqueue_dashboard' ) );
		add_action( 'save_post_page', array( __CLASS__, 'forget_page_cache' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'forget_page_cache' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'forget_page_cache' ) );
	}

	/**
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function enabled( $key ) {
		$settings = css_tc_addon()->get_settings();
		return ! empty( $settings[ $key ] );
	}

	/**
	 * Dashboard widget and the admin sidebar item are for time-clock employees.
	 *
	 * @param bool $enabled     Feature toggle.
	 * @param bool $is_employee Current user is a time-clock employee.
	 * @return bool
	 */
	public static function show_for_employee( $enabled, $is_employee ) {
		return (bool) $enabled && (bool) $is_employee;
	}

	/**
	 * Toolbar link: employees, and managers or administrators as well.
	 *
	 * @param bool $enabled     Feature toggle.
	 * @param bool $is_employee Time-clock employee.
	 * @param bool $is_manager  css_tc_manage or manage_options.
	 * @return bool
	 */
	public static function show_for_staff( $enabled, $is_employee, $is_manager ) {
		return (bool) $enabled && ( (bool) $is_employee || (bool) $is_manager );
	}

	/**
	 * @return bool
	 */
	public static function current_user_is_employee() {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}
		return css_tc_addon()->employees->is_employee( get_current_user_id() );
	}

	/**
	 * Front-end timecard URL. Override, then a cached published page that
	 * contains the shortcode, then the page saved at activation.
	 *
	 * @return string
	 */
	public static function url() {
		$settings = css_tc_addon()->get_settings();
		$override = self::normalize_override( isset( $settings['my_times_url_override'] ) ? $settings['my_times_url_override'] : '' );
		if ( '' !== $override ) {
			return $override;
		}
		$detected = self::detected_permalink();
		$saved_id = isset( $settings['employee_times_page_id'] ) ? (int) $settings['employee_times_page_id'] : 0;
		$saved    = '';
		if ( $saved_id > 0 && function_exists( 'get_permalink' ) && 'publish' === get_post_status( $saved_id ) ) {
			$link = get_permalink( $saved_id );
			if ( $link ) {
				$saved = (string) $link;
			}
		}
		$fallback = function_exists( 'home_url' ) ? home_url( '/my-time-clock/' ) : '/my-time-clock/';
		return self::resolve_url( $override, $detected, $saved, $fallback );
	}

	/**
	 * @param string $override Saved override, already normalized, or ''.
	 * @param string $detected Permalink of the detected page, or ''.
	 * @param string $saved    Permalink of the activation page, or ''.
	 * @param string $fallback Last-resort path.
	 * @return string
	 */
	public static function resolve_url( $override, $detected, $saved, $fallback ) {
		if ( '' !== (string) $override ) {
			return (string) $override;
		}
		if ( '' !== (string) $detected ) {
			return (string) $detected;
		}
		if ( '' !== (string) $saved ) {
			return (string) $saved;
		}
		return (string) $fallback;
	}

	/**
	 * Keep a full http(s) URL or a site path. Anything else is blank.
	 *
	 * @param mixed $raw Posted value.
	 * @return string
	 */
	public static function normalize_override( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw || strlen( $raw ) > 300 ) {
			return '';
		}
		if ( preg_match( '/[\s<>"\']/', $raw ) ) {
			return '';
		}
		if ( '/' === $raw[0] ) {
			return $raw;
		}
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * @param string $content Page content.
	 * @return bool
	 */
	public static function content_has_shortcode( $content ) {
		$content = (string) $content;
		if ( function_exists( 'has_shortcode' ) ) {
			return has_shortcode( $content, 'css_tc_my_times' );
		}
		return false !== strpos( $content, '[css_tc_my_times' );
	}

	/**
	 * First published page whose content contains the shortcode.
	 *
	 * @param array<int,array{id:int,status:string,content:string}> $candidates Rows.
	 * @return int
	 */
	public static function choose_page_id( $candidates ) {
		foreach ( (array) $candidates as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$status  = isset( $row['status'] ) ? (string) $row['status'] : '';
			$content = isset( $row['content'] ) ? (string) $row['content'] : '';
			if ( 'publish' === $status && self::content_has_shortcode( $content ) ) {
				return (int) $row['id'];
			}
		}
		return 0;
	}

	/**
	 * @param bool   $clocked_in Open shift that is not a missed clock-out.
	 * @param string $since      Site-local clock-in label, or ''.
	 * @return string
	 */
	public static function status_line( $clocked_in, $since ) {
		if ( $clocked_in && '' !== (string) $since ) {
			return sprintf(
				/* translators: %s: date and time the open shift started */
				__( 'Clocked in since %s', 'css-timeclock-addon' ),
				$since
			);
		}
		if ( $clocked_in ) {
			return __( 'Clocked in', 'css-timeclock-addon' );
		}
		return __( 'Clocked out', 'css-timeclock-addon' );
	}

	/**
	 * @param string $hm H:MM from the current pay period timecard.
	 * @return string
	 */
	public static function hours_line( $hm ) {
		$hm = (string) $hm;
		if ( '' === $hm ) {
			$hm = '0:00';
		}
		return sprintf(
			/* translators: %s: hours and minutes this pay period, H:MM */
			__( 'Hours this pay period: %s', 'css-timeclock-addon' ),
			$hm
		);
	}

	/**
	 * Put the widget first in the normal column when the user has a saved
	 * order. A missing order is left alone so the other dashboard widgets
	 * keep WordPress's default list; priority high still renders ours first.
	 *
	 * @param mixed  $order     User meta, or false.
	 * @param string $widget_id Widget id.
	 * @return mixed
	 */
	public static function pin_dashboard_order( $order, $widget_id ) {
		if ( ! is_array( $order ) ) {
			return $order;
		}
		$widget_id = (string) $widget_id;
		foreach ( array( 'normal', 'side', 'column3', 'column4' ) as $col ) {
			if ( ! isset( $order[ $col ] ) ) {
				continue;
			}
			$parts = array_values(
				array_filter(
					array_map( 'trim', explode( ',', (string) $order[ $col ] ) ),
					'strlen'
				)
			);
			$parts = array_values( array_diff( $parts, array( $widget_id ) ) );
			if ( 'normal' === $col ) {
				array_unshift( $parts, $widget_id );
			}
			$order[ $col ] = implode( ',', $parts );
		}
		if ( ! isset( $order['normal'] ) ) {
			$order['normal'] = $widget_id;
		}
		return $order;
	}

	/**
	 * Append the link for logged-in viewers on the chosen theme location.
	 *
	 * @param string $items           Existing menu HTML.
	 * @param string $theme_location  wp_nav_menu theme_location.
	 * @param string $wanted_location Setting, or '' to add nothing.
	 * @param bool   $enabled         Toggle.
	 * @param bool   $logged_in       Viewer is logged in.
	 * @param string $url             Timecard URL.
	 * @return string
	 */
	public static function nav_item_html( $items, $theme_location, $wanted_location, $enabled, $logged_in, $url ) {
		$items = (string) $items;
		if ( ! $enabled || ! $logged_in || '' === (string) $url || '' === (string) $wanted_location ) {
			return $items;
		}
		if ( (string) $theme_location !== (string) $wanted_location ) {
			return $items;
		}
		$markup = self::nav_link_markup( $url );
		if ( false !== strpos( $items, $markup ) ) {
			return $items;
		}
		return $items . $markup;
	}

	/**
	 * @param string $url Timecard URL.
	 * @return string
	 */
	public static function nav_link_markup( $url ) {
		$href = function_exists( 'esc_url' ) ? esc_url( $url ) : htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
		$label = function_exists( 'esc_html__' )
			? esc_html__( 'My Timecard', 'css-timeclock-addon' )
			: htmlspecialchars( __( 'My Timecard', 'css-timeclock-addon' ), ENT_QUOTES, 'UTF-8' );
		return '<li class="menu-item css-tc-my-timecard-item"><a href="' . $href . '">' . $label . '</a></li>';
	}

	/**
	 * @return void
	 */
	public static function forget_page_cache() {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::PAGE_CACHE );
		}
	}

	/**
	 * @param mixed $order Saved dashboard order.
	 * @return mixed
	 */
	public function pin_dashboard_widget( $order ) {
		if ( ! $this->dashboard_visible() ) {
			return $order;
		}
		return self::pin_dashboard_order( $order, self::WIDGET_ID );
	}

	/**
	 * @return void
	 */
	public function register_dashboard_widget() {
		if ( ! $this->dashboard_visible() ) {
			return;
		}
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'My Time Clock', 'css-timeclock-addon' ),
			array( $this, 'render_dashboard_widget' )
		);
		// Priority "high" is the top of the normal column. WordPress before 5.6
		// has no priority argument, so move the box after it is registered.
		global $wp_meta_boxes; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( isset( $wp_meta_boxes['dashboard']['normal']['core'][ self::WIDGET_ID ] ) ) {
			$box = $wp_meta_boxes['dashboard']['normal']['core'][ self::WIDGET_ID ];
			unset( $wp_meta_boxes['dashboard']['normal']['core'][ self::WIDGET_ID ] );
			$wp_meta_boxes['dashboard']['normal']['high'][ self::WIDGET_ID ] = $box;
		}
	}

	/**
	 * @return void
	 */
	public function render_dashboard_widget() {
		$user_id = get_current_user_id();
		$open    = css_tc_addon()->punches->open_shift_for( $user_id );
		$in      = ! empty( $open['is_clocked_in'] );
		$since   = ( $in && isset( $open['clock_in_time'] ) ) ? (string) $open['clock_in_time'] : '';
		$period  = css_tc_addon()->pay_periods->current_period();
		$hm      = '0:00';
		$range   = '';
		if ( $period ) {
			$sheet = css_tc_addon()->timecard->build( $user_id, $period );
			$hm    = isset( $sheet['paid_hm'] ) ? (string) $sheet['paid_hm'] : '0:00';
			$range = isset( $period['label'] ) ? (string) $period['label'] : '';
		}
		$url    = self::url();
		$status = self::status_line( $in, $since );
		$hours  = self::hours_line( $hm );
		include CSS_TC_ADDON_DIR . 'admin/views/dashboard-widget.php';
	}

	/**
	 * @param string $hook Current admin screen.
	 * @return void
	 */
	public function enqueue_dashboard( $hook ) {
		if ( 'index.php' !== (string) $hook || ! $this->dashboard_visible() ) {
			return;
		}
		wp_enqueue_style( 'css-tc-admin', CSS_TC_ADDON_URL . 'admin/css/admin.css', array(), CSS_TC_ADDON_ASSET_VERSION );
	}

	/**
	 * Top-level item for time-clock employees. add_menu_page runs the slug
	 * through plugin_basename, which breaks an https address, so the visible
	 * slug is replaced with the front-end URL after registration. The slug
	 * is not css-tc-*, so the manager-only admin guard does not block it.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		if ( ! self::show_for_employee( self::enabled( 'reach_admin_menu' ), self::current_user_is_employee() ) ) {
			return;
		}
		$url = self::url();
		if ( '' === $url ) {
			return;
		}
		add_menu_page(
			__( 'My Timecard', 'css-timeclock-addon' ),
			__( 'My Timecard', 'css-timeclock-addon' ),
			'read',
			'css-my-timecard',
			array( $this, 'redirect_to_timecard' ),
			'dashicons-clock',
			3
		);
		global $menu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( ! is_array( $menu ) ) {
			return;
		}
		foreach ( $menu as $index => $item ) {
			if ( is_array( $item ) && isset( $item[2] ) && 'css-my-timecard' === $item[2] ) {
				$menu[ $index ][2] = $url;
				break;
			}
		}
	}

	/**
	 * Fallback when the menu slug is opened as an admin screen.
	 *
	 * @return void
	 */
	public function redirect_to_timecard() {
		if ( ! self::current_user_is_employee() ) {
			wp_die( esc_html__( 'You do not have a timecard on this site.', 'css-timeclock-addon' ), '', array( 'response' => 403 ) );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * @param WP_Admin_Bar $bar Toolbar.
	 * @return void
	 */
	public function register_admin_bar( $bar ) {
		$manager = function_exists( 'is_user_logged_in' ) && is_user_logged_in() && Css_Tc_Plugin::user_can_manage();
		if ( ! self::show_for_staff( self::enabled( 'reach_admin_bar' ), self::current_user_is_employee(), $manager ) ) {
			return;
		}
		$url = self::url();
		if ( '' === $url || ! is_object( $bar ) || ! method_exists( $bar, 'add_node' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'css-tc-my-timecard',
				'title' => __( 'My Timecard', 'css-timeclock-addon' ),
				'href'  => $url,
				'meta'  => array(
					'class' => 'css-tc-my-timecard',
				),
			)
		);
	}

	/**
	 * @param string   $items Menu HTML.
	 * @param stdClass $args  Menu args.
	 * @return string
	 */
	public function nav_menu_items( $items, $args ) {
		$location = '';
		if ( is_object( $args ) && isset( $args->theme_location ) ) {
			$location = (string) $args->theme_location;
		} elseif ( is_array( $args ) && isset( $args['theme_location'] ) ) {
			$location = (string) $args['theme_location'];
		}
		$settings = css_tc_addon()->get_settings();
		$wanted   = isset( $settings['reach_nav_location'] ) ? (string) $settings['reach_nav_location'] : 'primary';
		$logged   = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		return self::nav_item_html( $items, $location, $wanted, self::enabled( 'reach_nav_menu' ), $logged, self::url() );
	}

	/**
	 * @return bool
	 */
	private function dashboard_visible() {
		return self::show_for_employee( self::enabled( 'reach_dashboard' ), self::current_user_is_employee() );
	}

	/**
	 * @return string
	 */
	private static function detected_permalink() {
		$id = self::detected_page_id();
		if ( $id < 1 || ! function_exists( 'get_permalink' ) ) {
			return '';
		}
		$link = get_permalink( $id );
		return $link ? (string) $link : '';
	}

	/**
	 * @return int
	 */
	private static function detected_page_id() {
		$cached = function_exists( 'get_transient' ) ? get_transient( self::PAGE_CACHE ) : false;
		if ( false !== $cached ) {
			$id = (int) $cached;
			if ( $id > 0 && self::page_still_matches( $id ) ) {
				return $id;
			}
			if ( 0 === $id ) {
				return 0;
			}
			self::forget_page_cache();
		}
		$id = self::query_published_page_id();
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::PAGE_CACHE, (int) $id, DAY_IN_SECONDS );
		}
		return (int) $id;
	}

	/**
	 * @param int $page_id Page.
	 * @return bool
	 */
	private static function page_still_matches( $page_id ) {
		if ( ! function_exists( 'get_post' ) ) {
			return false;
		}
		$post = get_post( (int) $page_id );
		if ( ! $post || 'publish' !== $post->post_status || 'page' !== $post->post_type ) {
			return false;
		}
		return self::content_has_shortcode( $post->post_content );
	}

	/**
	 * @return int
	 */
	private static function query_published_page_id() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return 0;
		}
		$like = '%' . $wpdb->esc_like( '[css_tc_my_times' ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_status, post_content FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 20",
				$like
			)
		);
		if ( ! is_array( $rows ) ) {
			return 0;
		}
		$candidates = array();
		foreach ( $rows as $row ) {
			$candidates[] = array(
				'id'      => (int) $row->ID,
				'status'  => (string) $row->post_status,
				'content' => (string) $row->post_content,
			);
		}
		return self::choose_page_id( $candidates );
	}
}
