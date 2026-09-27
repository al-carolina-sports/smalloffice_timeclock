<?php
/**
 * Prebuilt manager reports. Replaces AIO Lite's Reports screen, which prints
 * the stored UTC digits as if they were local time.
 *
 * Reports:
 *   - Pay period summary: hours per employee (Regular, Overtime, other codes,
 *     per-week totals) with items that need attention, plus department totals.
 *   - Shift detail: every shift in the period, in the site timezone, with
 *     clock-in/out IP addresses and flags.
 * Both download as CSV.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report builder, screen, and CSV export.
 */
class Css_Tc_Reports {

	const PAGE_SLUG  = 'css-tc-reports';
	const AIO_SLUG   = 'aio-reports-sub';
	const CSV_ACTION = 'css_tc_report_csv';
	const NONE_DEPT  = '_none';

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
		add_action( 'admin_post_' . self::CSV_ACTION, array( $this, 'download_csv' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Take over AIO's Reports slug so existing links keep working. Without
	 * AIO, add a Reports page under Settings next to Timecards.
	 *
	 * @return void
	 */
	public function add_menu() {
		if ( Css_Tc_Plugin::aio_is_active() && function_exists( 'get_plugin_page_hookname' ) ) {
			$hook = get_plugin_page_hookname( self::AIO_SLUG, 'aio-tc-lite' );
			if ( '' !== $hook && has_action( $hook ) ) {
				remove_all_actions( $hook );
				add_action( $hook, array( $this, 'render' ) );
				return;
			}
			add_submenu_page(
				'aio-tc-lite',
				__( 'Reports', 'css-timeclock-addon' ),
				__( 'Reports', 'css-timeclock-addon' ),
				'edit_posts',
				self::PAGE_SLUG,
				array( $this, 'render' )
			);
			return;
		}

		add_options_page(
			__( 'Timeclock Reports', 'css-timeclock-addon' ),
			__( 'Timeclock Reports', 'css-timeclock-addon' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @param string $hook Admin hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		$hook = (string) $hook;
		if ( false === strpos( $hook, self::AIO_SLUG ) && false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'css-tc-admin', CSS_TC_ADDON_URL . 'admin/css/admin.css', array(), CSS_TC_ADDON_VERSION );
	}

	/**
	 * Department terms, slug => name.
	 *
	 * @return array<string,string>
	 */
	public function departments() {
		if ( ! taxonomy_exists( 'department' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'department',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[ (string) $term->slug ] = (string) $term->name;
		}
		return $out;
	}

	/**
	 * Employees, sorted by name, optionally limited to one department slug
	 * (or NONE_DEPT for employees without one).
	 *
	 * @param string $department Department slug, NONE_DEPT, or ''.
	 * @return WP_User[]
	 */
	public function employees( $department = '' ) {
		$employees = css_tc_addon()->employees->list_for_admin();
		$list      = array();
		foreach ( $employees as $user ) {
			if ( '' !== $department ) {
				$slugs = $this->department_slugs( (int) $user->ID );
				if ( self::NONE_DEPT === $department ? ! empty( $slugs ) : ! in_array( $department, $slugs, true ) ) {
					continue;
				}
			}
			$list[] = $user;
		}
		usort(
			$list,
			static function ( $a, $b ) {
				return strcasecmp(
					css_tc_addon()->employees->display_name( (int) $a->ID ),
					css_tc_addon()->employees->display_name( (int) $b->ID )
				);
			}
		);
		return $list;
	}

	/**
	 * @param int $user_id User.
	 * @return string[]
	 */
	private function department_slugs( $user_id ) {
		if ( ! taxonomy_exists( 'department' ) ) {
			return array();
		}
		$slugs = wp_get_object_terms( (int) $user_id, 'department', array( 'fields' => 'slugs' ) );
		return is_wp_error( $slugs ) ? array() : array_map( 'strval', $slugs );
	}

	/**
	 * Hours per employee for one pay period.
	 *
	 * @param array<string,mixed> $period     Pay period.
	 * @param string              $department Department filter.
	 * @return array<string,mixed>
	 */
	public function period_summary( $period, $department = '' ) {
		$time    = css_tc_addon()->time;
		$codes   = ( new Css_Tc_Pay_Codes() )->definitions();
		$rows    = array();
		$totals  = array(
			'total'  => 0,
			'weeks'  => array(),
			'codes'  => array_fill_keys( array_keys( $codes ), 0 ),
		);
		$by_dept = array();

		foreach ( $this->employees( $department ) as $user ) {
			$sheet = css_tc_addon()->timecard->build( (int) $user->ID, $period );

			$code_seconds = array_fill_keys( array_keys( $codes ), 0 );
			foreach ( $sheet['pay_codes'] as $code ) {
				$code_seconds[ $code['slug'] ] = (int) $code['seconds'];
			}

			$week_seconds = array();
			$open = 0;
			$missed = 0;
			$missing_in = 0;
			$pending = 0;
			$shift_count = 0;
			foreach ( $sheet['weeks'] as $w => $week ) {
				$week_seconds[ $w ] = (int) $week['seconds'];
				foreach ( $week['days'] as $day ) {
					$pending += (int) $day['pending_count'];
					foreach ( $day['shifts'] as $shift ) {
						++$shift_count;
						if ( $shift['is_open'] && $shift['is_stale'] ) {
							++$missed;
						} elseif ( $shift['is_open'] ) {
							++$open;
						}
						if ( $shift['is_missing_in'] ) {
							++$missing_in;
						}
					}
				}
			}

			$dept_name = css_tc_addon()->employees->department( (int) $user->ID );
			$attention = array();
			if ( $sheet['long_shift_count'] > 0 ) {
				/* translators: %d: count */
				$attention[] = sprintf( _n( '%d long shift', '%d long shifts', $sheet['long_shift_count'], 'css-timeclock-addon' ), $sheet['long_shift_count'] );
			}
			if ( $missed > 0 ) {
				/* translators: %d: count */
				$attention[] = sprintf( _n( '%d missed clock-out', '%d missed clock-outs', $missed, 'css-timeclock-addon' ), $missed );
			}
			if ( $missing_in > 0 ) {
				/* translators: %d: count */
				$attention[] = sprintf( _n( '%d missing clock-in', '%d missing clock-ins', $missing_in, 'css-timeclock-addon' ), $missing_in );
			}
			if ( $pending > 0 ) {
				/* translators: %d: count */
				$attention[] = sprintf( _n( '%d pending request', '%d pending requests', $pending, 'css-timeclock-addon' ), $pending );
			}
			if ( $open > 0 ) {
				$attention[] = __( 'clocked in now', 'css-timeclock-addon' );
			}

			$row = array(
				'user_id'      => (int) $user->ID,
				'name'         => css_tc_addon()->employees->display_name( (int) $user->ID ),
				'department'   => $dept_name,
				'shifts'       => $shift_count,
				'codes'        => $code_seconds,
				'weeks'        => $week_seconds,
				'total'        => (int) $sheet['total_seconds'],
				'attention'    => $attention,
				'timecard_url' => Css_Tc_Admin::timecards_url(
					array(
						'employee' => (int) $user->ID,
						'period'   => (string) $period['start'],
					)
				),
			);
			$rows[] = $row;

			$totals['total'] += $row['total'];
			foreach ( $code_seconds as $slug => $sec ) {
				$totals['codes'][ $slug ] += $sec;
			}
			foreach ( $week_seconds as $w => $sec ) {
				$totals['weeks'][ $w ] = ( isset( $totals['weeks'][ $w ] ) ? $totals['weeks'][ $w ] : 0 ) + $sec;
			}

			$key = '' !== $dept_name ? $dept_name : __( 'No department', 'css-timeclock-addon' );
			if ( ! isset( $by_dept[ $key ] ) ) {
				$by_dept[ $key ] = array(
					'name'      => $key,
					'employees' => 0,
					'codes'     => array_fill_keys( array_keys( $codes ), 0 ),
					'total'     => 0,
				);
			}
			++$by_dept[ $key ]['employees'];
			$by_dept[ $key ]['total'] += $row['total'];
			foreach ( $code_seconds as $slug => $sec ) {
				$by_dept[ $key ]['codes'][ $slug ] += $sec;
			}
		}
		ksort( $by_dept, SORT_NATURAL | SORT_FLAG_CASE );

		$weeks = array();
		foreach ( $period['weeks'] as $w => $week ) {
			$weeks[ $w ] = array(
				'label' => (string) $week['label'],
				'range' => (string) $week['range'],
			);
		}

		return array(
			'period'      => $period,
			'codes'       => $codes,
			'weeks'       => $weeks,
			'rows'        => $rows,
			'totals'      => $totals,
			'departments' => array_values( $by_dept ),
			'rule_note'   => css_tc_addon()->overtime->describe(),
			'timezone'    => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : $time->timezone()->getName(),
		);
	}

	/**
	 * Every shift in the period, in site time.
	 *
	 * @param array<string,mixed> $period     Pay period.
	 * @param string              $department Department filter.
	 * @param int                 $user_id    Optional single employee.
	 * @return array<int,array<string,mixed>>
	 */
	public function shift_detail( $period, $department = '', $user_id = 0 ) {
		$time = css_tc_addon()->time;
		$rows = array();
		foreach ( $this->employees( $department ) as $user ) {
			if ( $user_id && (int) $user->ID !== (int) $user_id ) {
				continue;
			}
			$name = css_tc_addon()->employees->display_name( (int) $user->ID );
			$dept = css_tc_addon()->employees->department( (int) $user->ID );
			foreach ( css_tc_addon()->timecard->shifts_for_period( (int) $user->ID, $period ) as $shift ) {
				$flags = array();
				if ( $shift['is_open'] ) {
					$flags[] = $shift['is_stale_open'] ? __( 'Missed clock-out', 'css-timeclock-addon' ) : __( 'Clocked in', 'css-timeclock-addon' );
				}
				if ( $shift['is_missing_in'] ) {
					$flags[] = __( 'No clock-in', 'css-timeclock-addon' );
				}
				if ( $shift['is_long'] ) {
					$flags[] = __( 'Long shift', 'css-timeclock-addon' );
				}
				if ( $shift['is_out_before_in'] ) {
					$flags[] = __( 'Out before in', 'css-timeclock-addon' );
				}
				if ( $shift['seconds'] > 0 && $shift['seconds'] < MINUTE_IN_SECONDS ) {
					$flags[] = __( 'Under 1 minute', 'css-timeclock-addon' );
				}
				$source = (string) get_post_meta( (int) $shift['id'], 'css_tc_kiosk_source', true );
				$rows[] = array(
					'user_id'    => (int) $user->ID,
					'name'       => $name,
					'department' => $dept,
					'date'       => (string) $shift['work_date'],
					'weekday'    => $time->format_weekday_short( (string) $shift['work_date'] ),
					'in'         => (string) $shift['clock_in_clock'],
					'out'        => (string) $shift['clock_out_clock'],
					'next_day'   => ! empty( $shift['out_next_day'] ),
					'seconds'    => (int) $shift['seconds'],
					'ip_in'      => (string) get_post_meta( (int) $shift['id'], 'ip_address_in', true ),
					'ip_out'     => (string) get_post_meta( (int) $shift['id'], 'ip_address_out', true ),
					'source'     => '' !== $source ? $source : 'aio',
					'flags'      => $flags,
				);
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				$cmp = strcasecmp( $a['name'], $b['name'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return strcmp( $a['date'] . $a['in'], $b['date'] . $b['in'] );
			}
		);
		return $rows;
	}

	/**
	 * Decimal hours for payroll entry, e.g. 8.5.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function decimal_hours( $seconds ) {
		return number_format( max( 0, (int) $seconds ) / HOUR_IN_SECONDS, 2, '.', '' );
	}

	/**
	 * Request filters, validated.
	 *
	 * @param array<string,mixed> $source $_GET or $_POST.
	 * @return array{report:string,period:array<string,mixed>|null,department:string,employee:int}
	 */
	public function filters( $source ) {
		$report = isset( $source['report'] ) ? sanitize_key( wp_unslash( $source['report'] ) ) : 'summary';
		if ( ! in_array( $report, array( 'summary', 'shifts' ), true ) ) {
			$report = 'summary';
		}

		$start  = isset( $source['period'] ) ? sanitize_text_field( wp_unslash( $source['period'] ) ) : '';
		$period = css_tc_addon()->pay_periods->period_by_start( $start );
		if ( ! $period ) {
			// Default to the last finished period: that is the one payroll needs.
			$current = css_tc_addon()->pay_periods->current_period();
			$period  = $current ? css_tc_addon()->pay_periods->period_before( $current ) : null;
		}

		$department = isset( $source['department'] ) ? sanitize_key( wp_unslash( $source['department'] ) ) : '';
		if ( '' !== $department && self::NONE_DEPT !== $department && ! isset( $this->departments()[ $department ] ) ) {
			$department = '';
		}

		return array(
			'report'     => $report,
			'period'     => $period,
			'department' => $department,
			'employee'   => isset( $source['employee'] ) ? absint( $source['employee'] ) : 0,
		);
	}

	/**
	 * Reports screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view timeclock reports.', 'css-timeclock-addon' ) );
		}

		$filters     = $this->filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$periods     = css_tc_addon()->pay_periods->dropdown_periods( 12 );
		$departments = $this->departments();
		$employees   = $this->employees();
		$page_slug   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::PAGE_SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$summary     = null;
		$shifts      = null;
		if ( $filters['period'] ) {
			if ( 'shifts' === $filters['report'] ) {
				$shifts = $this->shift_detail( $filters['period'], $filters['department'], $filters['employee'] );
			} else {
				$summary = $this->period_summary( $filters['period'], $filters['department'] );
			}
		}
		$csv_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => self::CSV_ACTION,
					'report'     => $filters['report'],
					'period'     => $filters['period'] ? $filters['period']['start'] : '',
					'department' => $filters['department'],
					'employee'   => $filters['employee'],
				),
				admin_url( 'admin-post.php' )
			),
			self::CSV_ACTION
		);

		include CSS_TC_ADDON_DIR . 'admin/views/reports-page.php';
	}

	/**
	 * CSV download.
	 *
	 * @return void
	 */
	public function download_csv() {
		if ( ! Css_Tc_Plugin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to download timeclock reports.', 'css-timeclock-addon' ), 403 );
		}
		check_admin_referer( self::CSV_ACTION );

		$filters = $this->filters( $_GET );
		if ( ! $filters['period'] ) {
			wp_die( esc_html__( 'Choose a pay period.', 'css-timeclock-addon' ), 400 );
		}
		$period = $filters['period'];
		$lines  = 'shifts' === $filters['report']
			? $this->shift_csv_lines( $this->shift_detail( $period, $filters['department'], $filters['employee'] ) )
			: $this->summary_csv_lines( $this->period_summary( $period, $filters['department'] ) );

		$name = sprintf(
			'timeclock-%s-%s-to-%s.csv',
			'shifts' === $filters['report'] ? 'shifts' : 'pay-period',
			$period['start'],
			$period['end']
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel reads UTF-8 with a BOM.
		foreach ( $lines as $line ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $line ), ',', '"', '' );
		}
		fclose( $out );
		exit;
	}

	/**
	 * @param array<string,mixed> $summary Summary.
	 * @return array<int,array<int,string>>
	 */
	private function summary_csv_lines( $summary ) {
		$head = array( 'Employee', 'Department', 'Shifts' );
		foreach ( $summary['codes'] as $def ) {
			$head[] = $def['label'] . ' (hours)';
		}
		$head[] = 'Total (hours)';
		foreach ( $summary['weeks'] as $week ) {
			$head[] = $week['label'] . ' ' . $week['range'] . ' (hours)';
		}
		$head[] = 'Needs attention';

		$lines = array( $head );
		foreach ( $summary['rows'] as $row ) {
			$line = array( $row['name'], $row['department'], (string) $row['shifts'] );
			foreach ( array_keys( $summary['codes'] ) as $slug ) {
				$line[] = self::decimal_hours( $row['codes'][ $slug ] );
			}
			$line[] = self::decimal_hours( $row['total'] );
			foreach ( array_keys( $summary['weeks'] ) as $w ) {
				$line[] = self::decimal_hours( isset( $row['weeks'][ $w ] ) ? $row['weeks'][ $w ] : 0 );
			}
			$line[]  = implode( '; ', $row['attention'] );
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Shift rows.
	 * @return array<int,array<int,string>>
	 */
	private function shift_csv_lines( $rows ) {
		$lines = array( array( 'Employee', 'Department', 'Date', 'Day', 'Clock in', 'Clock out', 'Out next day', 'Hours', 'IP in', 'IP out', 'Source', 'Flags' ) );
		foreach ( $rows as $row ) {
			$lines[] = array(
				$row['name'],
				$row['department'],
				$row['date'],
				$row['weekday'],
				$row['in'],
				$row['out'],
				$row['next_day'] ? 'yes' : '',
				self::decimal_hours( $row['seconds'] ),
				$row['ip_in'],
				$row['ip_out'],
				$row['source'],
				implode( '; ', $row['flags'] ),
			);
		}
		return $lines;
	}

	/**
	 * Stop spreadsheet formula injection from names or other text.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
