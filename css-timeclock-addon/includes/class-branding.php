<?php
/**
 * Admin rebrand: SMOTC labels, and hide Codebangers on AIO Lite screens.
 *
 * Does not edit All in One Time Clock Lite files. Markup matched to AIO Lite 2.1.0.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu labels, plugin list names, and AIO admin chrome.
 */
class Css_Tc_Branding {

	const BRAND     = 'SMOTC';
	const CORE_NAME = 'SMOTC Core';

	/**
	 * AIO Lite 2.1.0 admin page slugs that render its own screens.
	 *
	 * @return string[]
	 */
	public static function aio_admin_slugs() {
		return array(
			'aio-tc-lite',
			'aio-monitoring-sub',
			'aio-employees-sub',
			'aio-department-sub',
			'aio-shifts-sub',
			'aio-reports-sub',
		);
	}

	/**
	 * @return void
	 */
	public static function register() {
		$self = new self();
		add_action( 'admin_menu', array( $self, 'rebrand_admin_menus' ), 999 );
		add_action( 'admin_init', array( $self, 'redirect_codebangers_help_tab' ), 1 );
		add_action( 'admin_init', array( $self, 'maybe_buffer_aio_screen' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $self, 'enqueue' ) );
		add_filter( 'all_plugins', array( $self, 'filter_plugins' ) );
		add_filter( 'plugin_row_meta', array( $self, 'filter_plugin_row_meta' ), 10, 2 );
		add_filter( 'admin_title', array( $self, 'filter_admin_title' ), 99, 2 );
	}

	/**
	 * Rename AIO's top-level menu, brand-named submenus, and this addon's menus.
	 *
	 * Runs after AIO (priority 10) and this addon's own menu (priority 25).
	 *
	 * @return void
	 */
	public function rebrand_admin_menus() {
		global $menu, $submenu;
		self::rebrand_menu_arrays( $menu, $submenu );
	}

	/**
	 * @param array<int,array<int,mixed>>|null          $menu    Global $menu.
	 * @param array<string,array<int,array<int,mixed>>> $submenu Global $submenu.
	 * @return void
	 */
	public static function rebrand_menu_arrays( &$menu, &$submenu ) {
		if ( is_array( $menu ) ) {
			foreach ( $menu as $index => $item ) {
				if ( ! is_array( $item ) || ! isset( $item[2] ) || 'aio-tc-lite' !== $item[2] ) {
					continue;
				}
				$menu[ $index ][0] = self::BRAND;
				if ( array_key_exists( 3, $menu[ $index ] ) ) {
					$menu[ $index ][3] = self::BRAND;
				}
			}
		}

		if ( ! is_array( $submenu ) ) {
			return;
		}

		foreach ( $submenu as $parent => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $index => $item ) {
				if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
					continue;
				}
				$slug    = (string) $item[2];
				$is_ours = ( 'css-tc-addon' === $slug );
				$is_brand_submenu = ( 'aio-tc-lite' === $parent && self::label_is_legacy_brand( isset( $item[0] ) ? $item[0] : '' ) );
				if ( ! $is_ours && ! $is_brand_submenu ) {
					continue;
				}
				$submenu[ $parent ][ $index ][0] = self::BRAND;
				if ( array_key_exists( 3, $submenu[ $parent ][ $index ] ) ) {
					$page_title = $submenu[ $parent ][ $index ][3];
					if ( $is_ours || self::label_is_legacy_brand( $page_title ) ) {
						$submenu[ $parent ][ $index ][3] = self::BRAND;
					}
				}
			}
		}
	}

	/**
	 * Product names that should read as SMOTC. Full label only, so "Settings" stays.
	 *
	 * @param mixed $label Menu or title text, possibly with markup.
	 * @return bool
	 */
	public static function label_is_legacy_brand( $label ) {
		$text = html_entity_decode( strip_tags( (string) $label ), ENT_QUOTES, 'UTF-8' );
		$text = strtolower( trim( preg_replace( '/\s+/', ' ', $text ) ) );
		$known = array(
			'time clock',
			'time clock lite',
			'time clock kiosk',
			'all in one time clock',
			'all in one time clock lite',
			'aio time clock',
			'aio time clock lite',
			'css time clock addon',
			'kiosk & pins',
		);
		return in_array( $text, $known, true );
	}

	/**
	 * Help is only the Codebangers support banner (support.jpg + codebangers.com/support/).
	 *
	 * @return void
	 */
	public function redirect_codebangers_help_tab() {
		if ( wp_doing_ajax() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( 'aio-tc-lite' !== self::request_page() || 'help' !== self::request_tab() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=aio-tc-lite&tab=general_settings' ) );
		exit;
	}

	/**
	 * Strip the logo and support banner before the admin page is sent.
	 *
	 * The logo is inline HTML in AIO's page templates, not an action, so the
	 * buffer is limited to those admin page slugs.
	 *
	 * @return void
	 */
	public function maybe_buffer_aio_screen() {
		static $started = false;
		if ( $started ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( ! in_array( self::request_page(), self::aio_admin_slugs(), true ) ) {
			return;
		}
		$started = true;
		ob_start( array( __CLASS__, 'filter_aio_admin_html' ) );
	}

	/**
	 * @param string $html Admin HTML.
	 * @return string
	 */
	public static function filter_aio_admin_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$updated = preg_replace(
			'#<a\s+[^>]*href=(["\'])https?://codebangers\.com/?\1[^>]*>\s*<img\b[^>]*logo\.png[^>]*>\s*</a>(\s*<hr\s*/?>)?#i',
			'',
			$html
		);
		if ( null === $updated ) {
			return $html;
		}
		$html = $updated;

		$updated = preg_replace(
			'#<div class="aio-support-section">\s*<div class="support-image">.*?</div>\s*<div class="support-content">.*?</div>\s*</div>#is',
			'',
			$html
		);
		if ( is_string( $updated ) ) {
			$html = $updated;
		}

		$updated = preg_replace(
			'#<a\s+[^>]*href=(["\'])[^"\']*\btab=help\b[^"\']*\1[^>]*>.*?</a>#is',
			'',
			$html
		);
		if ( is_string( $updated ) ) {
			$html = $updated;
		}

		$updated = preg_replace(
			'#(<h1\b[^>]*>)\s*All in One Time Clock Lite\s*(</h1>)#i',
			'$1' . self::BRAND . '$2',
			$html
		);
		if ( is_string( $updated ) ) {
			$html = $updated;
		}

		$html = str_replace(
			array(
				'Thanks again for using the All In One Time Clock Lite',
				'Thanks again for using the All in One Time Clock Lite',
				'You have recently updated the AIO Time Clock Lite.',
			),
			array(
				'Thanks again for using ' . self::BRAND,
				'Thanks again for using ' . self::BRAND,
				'You have recently updated ' . self::BRAND . '.',
			),
			$html
		);

		return $html;
	}

	/**
	 * CSS/JS fallback if a host flushes the admin buffer early.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		$hook = (string) $hook;
		$hit  = false;
		foreach ( self::aio_admin_slugs() as $slug ) {
			if ( false !== strpos( $hook, $slug ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit && in_array( self::request_page(), self::aio_admin_slugs(), true ) ) {
			$hit = true;
		}
		if ( ! $hit ) {
			return;
		}

		wp_enqueue_style(
			'css-tc-aio-branding',
			CSS_TC_ADDON_URL . 'admin/css/aio-branding.css',
			array(),
			CSS_TC_ADDON_ASSET_VERSION
		);

		wp_enqueue_script(
			'css-tc-aio-branding',
			CSS_TC_ADDON_URL . 'admin/js/aio-branding.js',
			array(),
			CSS_TC_ADDON_ASSET_VERSION,
			true
		);
	}

	/**
	 * Plugins screen: this addon is SMOTC, AIO Lite is SMOTC Core.
	 *
	 * @param array<string,array<string,mixed>> $plugins Plugin rows.
	 * @return array<string,array<string,mixed>>
	 */
	public function filter_plugins( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		foreach ( $plugins as $file => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			if ( self::is_our_plugin_file( (string) $file ) ) {
				$plugins[ $file ]['Name']  = self::BRAND;
				$plugins[ $file ]['Title'] = self::BRAND;
				continue;
			}
			if ( ! self::is_aio_plugin_file( (string) $file, $data ) ) {
				continue;
			}
			$plugins[ $file ]['Name']       = self::CORE_NAME;
			$plugins[ $file ]['Title']      = self::CORE_NAME;
			$plugins[ $file ]['Author']     = '';
			$plugins[ $file ]['AuthorName'] = '';
			$plugins[ $file ]['AuthorURI']  = '';
			$plugins[ $file ]['PluginURI']  = '';
		}

		return $plugins;
	}

	/**
	 * Drop the wordpress.org "View details" row for AIO. That modal is the
	 * Codebangers listing, and its slug is merged in after all_plugins.
	 *
	 * @param string[] $meta Existing row meta HTML.
	 * @param string   $file Plugin basename.
	 * @return string[]
	 */
	public function filter_plugin_row_meta( $meta, $file ) {
		if ( ! self::is_aio_plugin_file( (string) $file, array() ) ) {
			return $meta;
		}
		$clean = array();
		foreach ( (array) $meta as $item ) {
			if ( ! is_string( $item ) ) {
				$clean[] = $item;
				continue;
			}
			if ( false !== stripos( $item, 'codebangers.com' ) ) {
				continue;
			}
			if ( false !== stripos( $item, 'plugin=aio-time-clock-lite' ) || false !== stripos( $item, 'plugin-information' ) ) {
				continue;
			}
			$clean[] = $item;
		}
		return $clean;
	}

	/**
	 * @param string $admin_title Full admin title.
	 * @param string $title       Page title.
	 * @return string
	 */
	public function filter_admin_title( $admin_title, $title ) {
		unset( $title );
		$page = self::request_page();
		$ours = ( 'css-tc-addon' === $page );
		$aio  = in_array( $page, self::aio_admin_slugs(), true );
		if ( ! $ours && ! $aio ) {
			return $admin_title;
		}
		$phrases = array(
			'All in One Time Clock Lite',
			'All In One Time Clock Lite',
			'All in One Time Clock',
			'All In One Time Clock',
			'AIO Time Clock Lite',
			'AIO Time Clock',
			'CSS Time Clock Addon',
			'Time Clock Kiosk',
			'Time Clock Lite',
			'Kiosk & PINs',
			'Kiosk &amp; PINs',
		);
		return str_replace( $phrases, self::BRAND, (string) $admin_title );
	}

	/**
	 * @param string $file Plugin basename.
	 * @return bool
	 */
	public static function is_our_plugin_file( $file ) {
		$file = (string) $file;
		if ( defined( 'CSS_TC_ADDON_BASENAME' ) && CSS_TC_ADDON_BASENAME === $file ) {
			return true;
		}
		return 'css-timeclock-addon.php' === basename( $file );
	}

	/**
	 * @param string              $file Plugin basename.
	 * @param array<string,mixed> $data Header fields.
	 * @return bool
	 */
	public static function is_aio_plugin_file( $file, $data ) {
		if ( 'aio-time-clock-lite.php' === basename( (string) $file ) ) {
			return true;
		}
		$name = ( is_array( $data ) && isset( $data['Name'] ) ) ? (string) $data['Name'] : '';
		return '' !== $name && 0 === stripos( $name, 'All in One Time Clock Lite' );
	}

	/**
	 * @return string
	 */
	private static function request_page() {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		$page = wp_unslash( $_GET['page'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_string( $page ) ) {
			return '';
		}
		return sanitize_key( $page );
	}

	/**
	 * @return string
	 */
	private static function request_tab() {
		if ( ! isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		$tab = wp_unslash( $_GET['tab'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_string( $tab ) ) {
			return '';
		}
		return sanitize_key( $tab );
	}
}
