<?php
/**
 * Builds an employee timecard from AIO shift posts for one pay period.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Timecard view model, day flags, and the period correction form.
 */
class Css_Tc_Timecard {

	const FLAG_META = 'css_tc_flagged_dates';

	/**
	 * @param int                 $user_id Employee user ID.
	 * @param array<string,mixed> $period  Pay period from Css_Tc_Pay_Periods.
	 * @return array<string,mixed>
	 */
	public function build( $user_id, $period ) {
		$user_id = (int) $user_id;
		$shifts   = $this->shifts_for_period( $user_id, $period );
		$pending  = css_tc_addon()->corrections->pending_by_date( $user_id, $period['start'], $period['end'] );
		$approved = css_tc_addon()->corrections->dates_with_status( $user_id, $period['start'], $period['end'], 'private' );
		$flags    = $this->flagged_dates( $user_id );

		$by_date = array();
		foreach ( $shifts as $shift ) {
			$date = (string) $shift['work_date'];
			if ( '' === $date ) {
				continue;
			}
			if ( ! isset( $by_date[ $date ] ) ) {
				$by_date[ $date ] = array();
			}
			$by_date[ $date ][] = $shift;
		}

		$codes    = new Css_Tc_Pay_Codes();
		$defs     = $codes->definitions();
		$buckets  = array();
		foreach ( $defs as $slug => $def ) {
			$buckets[ $slug ] = 0;
		}
		if ( ! isset( $buckets[ Css_Tc_Pay_Codes::REGULAR ] ) ) {
			$buckets[ Css_Tc_Pay_Codes::REGULAR ] = 0;
		}

		$total_seconds = 0;
		$week_rows     = array();

		foreach ( $period['weeks'] as $week ) {
			$days          = array();
			$week_seconds  = 0;
			$cursor        = $week['start'];
			$guard         = 0;
			while ( $cursor <= $week['end'] && $guard < 7 ) {
				$list = isset( $by_date[ $cursor ] ) ? $by_date[ $cursor ] : array();
				$day_seconds = 0;
				// Stored punches only. A pending correction's proposed times are not added here.
				foreach ( $list as $shift ) {
					if ( $shift['seconds'] > 0 ) {
						$day_seconds += (int) $shift['seconds'];
						$code = $codes->code_for_shift( $shift );
						if ( ! isset( $buckets[ $code ] ) ) {
							$buckets[ $code ] = 0;
						}
						$buckets[ $code ] += (int) $shift['seconds'];
					}
				}
				$week_seconds  += $day_seconds;
				$total_seconds += $day_seconds;
				$days[] = $this->day_cell( $cursor, $list, $day_seconds, $period, $pending, $flags, $approved );
				$cursor = css_tc_addon()->time->shift_date( $cursor, 1 );
				++$guard;
			}

			$week_rows[] = array(
				'index'   => (int) $week['index'],
				'label'   => (string) $week['label'],
				'range'   => (string) $week['range'],
				'start'   => (string) $week['start'],
				'end'     => (string) $week['end'],
				'seconds' => $week_seconds,
				'hm'      => css_tc_addon()->time->format_duration( $week_seconds ),
				'days'    => $days,
			);
		}

		$pay_rows = array();
		foreach ( $defs as $slug => $def ) {
			$seconds = isset( $buckets[ $slug ] ) ? (int) $buckets[ $slug ] : 0;
			if ( Css_Tc_Pay_Codes::REGULAR !== $slug && $seconds < 1 ) {
				continue;
			}
			$pay_rows[] = array(
				'slug'    => $slug,
				'label'   => isset( $def['label'] ) ? (string) $def['label'] : $slug,
				'seconds' => $seconds,
				'hm'      => css_tc_addon()->time->format_duration( $seconds ),
			);
		}

		$long_count = 0;
		foreach ( $week_rows as $week_row ) {
			foreach ( $week_row['days'] as $day_row ) {
				foreach ( $day_row['shifts'] as $pair ) {
					if ( ! empty( $pair['is_long'] ) ) {
						++$long_count;
					}
				}
			}
		}

		return array(
			'user_id'          => $user_id,
			'employee_name'    => css_tc_addon()->employees->display_name( $user_id ),
			'initials'         => css_tc_addon()->employees->initials( $user_id ),
			'period'           => $period,
			'total_seconds'    => $total_seconds,
			'total_hm'         => css_tc_addon()->time->format_duration( $total_seconds ),
			'pay_codes'        => $pay_rows,
			'weeks'            => $week_rows,
			'long_shift_count' => $long_count,
			'long_shift_max'   => css_tc_addon()->punches->long_shift_hours(),
		);
	}

	/**
	 * Shifts whose site-local clock-in (or clock-out, if clock-in is missing)
	 * falls in the period. The query is padded so UTC storage still matches
	 * site-local days near midnight.
	 *
	 * @param int                 $user_id Employee.
	 * @param array<string,mixed> $period  Period.
	 * @return array<int,array<string,mixed>>
	 */
	public function shifts_for_period( $user_id, $period ) {
		$user_id = (int) $user_id;
		$time    = css_tc_addon()->time;
		$from    = $time->shift_date( (string) $period['start'], -2 ) . ' 00:00:00';
		$to      = $time->shift_date( (string) $period['end'], 2 ) . ' 23:59:59';

		$query = new WP_Query(
			array(
				'post_type'      => Css_Tc_Punches::POST_TYPE,
				'author'         => $user_id,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => 'employee_clock_in_time',
						'value'   => array( $from, $to ),
						'compare' => 'BETWEEN',
						'type'    => 'CHAR',
					),
					array(
						'key'     => 'employee_clock_out_time',
						'value'   => array( $from, $to ),
						'compare' => 'BETWEEN',
						'type'    => 'CHAR',
					),
				),
			)
		);

		$rows = array();
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$row = css_tc_addon()->punches->shift_row( $post );
				if ( ! $row || '' === $row['work_date'] ) {
					continue;
				}
				if ( $row['work_date'] < $period['start'] || $row['work_date'] > $period['end'] ) {
					continue;
				}
				$rows[] = $row;
			}
		}
		wp_reset_postdata();

		usort(
			$rows,
			static function ( $a, $b ) {
				$cmp = strcmp( (string) $a['work_date'], (string) $b['work_date'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return strcmp( (string) $a['clock_in_raw'], (string) $b['clock_in_raw'] );
			}
		);

		return $rows;
	}

	/**
	 * @param string                           $date     Y-m-d.
	 * @param array<int,array<string,mixed>>   $shifts   Shifts that day.
	 * @param int                              $seconds  Counted seconds.
	 * @param array<string,mixed>              $period   Period.
	 * @param array<string,array<int,mixed>>   $pending  Pending corrections by date.
	 * @param string[]                         $flags    Flagged Y-m-d dates.
	 * @param array<string,bool>              $approved Dates with an approved correction.
	 * @return array<string,mixed>
	 */
	private function day_cell( $date, $shifts, $seconds, $period, $pending, $flags, $approved ) {
		$time       = css_tc_addon()->time;
		$day_obj    = $time->date_immutable( $date );
		$needs      = false;
		$has_long   = false;
		$open_fresh = false;
		$open_stale = false;
		$missing_in = false;
		$pairs      = array();

		foreach ( $shifts as $shift ) {
			if ( ! empty( $shift['is_open'] ) || ! empty( $shift['is_missing_in'] ) || ! empty( $shift['is_long'] ) ) {
				$needs = true;
			}
			if ( ! empty( $shift['is_long'] ) ) {
				$has_long = true;
			}
			if ( ! empty( $shift['is_open'] ) && ! empty( $shift['is_stale_open'] ) ) {
				$open_stale = true;
			} elseif ( ! empty( $shift['is_open'] ) ) {
				$open_fresh = true;
			}
			if ( ! empty( $shift['is_missing_in'] ) ) {
				$missing_in = true;
			}
			$pairs[] = array(
				'id'            => (int) $shift['id'],
				'in_display'    => (string) $shift['clock_in_clock'],
				'out_display'   => (string) $shift['clock_out_clock'],
				'in_hms'        => (string) $shift['clock_in_hms'],
				'out_hms'       => (string) $shift['clock_out_hms'],
				'duration'      => (string) $shift['time_total'],
				'is_open'       => ! empty( $shift['is_open'] ),
				'is_stale'      => ! empty( $shift['is_stale_open'] ),
				'is_missing_in' => ! empty( $shift['is_missing_in'] ),
				'is_long'       => ! empty( $shift['is_long'] ),
				'out_next_day'  => ! empty( $shift['out_next_day'] ),
			);
		}

		$day_pending = isset( $pending[ $date ] ) ? $pending[ $date ] : array();
		$flagged     = in_array( $date, $flags, true );
		if ( $flagged || ! empty( $day_pending ) ) {
			$needs = true;
		}

		$in_period = ( $date >= $period['start'] && $date <= $period['end'] );
		$hm        = $seconds > 0 ? $time->format_duration( $seconds ) : '';
		$status    = '';
		if ( $open_fresh ) {
			$status = __( 'Still clocked in', 'css-timeclock-addon' );
		} elseif ( $open_stale ) {
			$status = __( 'Missed clock-out', 'css-timeclock-addon' );
		} elseif ( $missing_in ) {
			$status = __( 'No clock-in', 'css-timeclock-addon' );
		}

		return array(
			'date'             => $date,
			'day_num'          => $day_obj ? $day_obj->format( 'j' ) : '',
			'weekday'          => $time->format_weekday( $date ),
			'weekday_short'    => $time->format_weekday_short( $date ),
			'date_label'       => $time->format_day_label( $date ),
			'in_period'        => $in_period,
			'seconds'          => (int) $seconds,
			'hm'               => $hm,
			'status_label'     => $status,
			'has_time'         => ( '' !== $hm || '' !== $status ),
			'shifts'           => $pairs,
			'needs_correction' => $needs && ! empty( $period['is_open'] ),
			'has_long'         => $has_long,
			'pending'          => ! empty( $day_pending ),
			'pending_count'    => count( $day_pending ),
			'has_approved'     => isset( $approved[ $date ] ),
			'flagged'          => $flagged,
			'is_today'         => ( $date === $time->site_today() ),
		);
	}

	/**
	 * @param int $user_id Employee.
	 * @return string[]
	 */
	public function flagged_dates( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::FLAG_META, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $date ) {
			$date = (string) $date;
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$out[] = $date;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Editable lines for every day in the current pay period.
	 *
	 * @param int $user_id Employee.
	 * @return array<string,mixed>|null
	 */
	public function form_days( $user_id ) {
		$user_id = (int) $user_id;
		$period  = css_tc_addon()->pay_periods->current_period();
		if ( ! $period ) {
			return null;
		}

		$shifts  = $this->shifts_for_period( $user_id, $period );
		$pending = css_tc_addon()->corrections->pending_by_date( $user_id, $period['start'], $period['end'] );
		$by_date = array();
		foreach ( $shifts as $shift ) {
			$by_date[ $shift['work_date'] ][] = $shift;
		}

		$time = css_tc_addon()->time;
		$days = array();
		$cursor = $period['start'];
		$guard  = 0;
		while ( $cursor <= $period['end'] && $guard < 16 ) {
			$lines = array();
			$list  = isset( $by_date[ $cursor ] ) ? $by_date[ $cursor ] : array();
			$day_pending = isset( $pending[ $cursor ] ) ? $pending[ $cursor ] : array();
			$used_pending = array();

			foreach ( $list as $shift ) {
				$overlay = null;
				foreach ( $day_pending as $index => $suggestion ) {
					if ( (int) $suggestion['shift_id'] === (int) $shift['id'] ) {
						$overlay = $suggestion;
						$used_pending[ $index ] = true;
						break;
					}
				}
				$lines[] = array(
					'shift_id'      => (int) $shift['id'],
					'correction_id' => $overlay ? (int) $overlay['id'] : 0,
					'in_hms'        => $overlay && '' !== $overlay['proposed_in_hms'] ? $overlay['proposed_in_hms'] : (string) $shift['clock_in_hms'],
					'out_hms'       => $overlay && ( '' !== $overlay['proposed_out_hms'] || ! empty( $overlay['out_next_day'] ) ) ? $overlay['proposed_out_hms'] : (string) $shift['clock_out_hms'],
					'out_next_day'  => $overlay ? ! empty( $overlay['out_next_day'] ) : ! empty( $shift['out_next_day'] ),
					'reason'        => $overlay ? (string) $overlay['reason'] : '',
					'is_open'       => ! empty( $shift['is_open'] ),
					'is_stale'      => ! empty( $shift['is_stale_open'] ),
					'is_missing_in' => ! empty( $shift['is_missing_in'] ),
					'is_long'       => ! empty( $shift['is_long'] ),
					'pending'       => (bool) $overlay,
				);
			}

			foreach ( $day_pending as $index => $suggestion ) {
				if ( isset( $used_pending[ $index ] ) || (int) $suggestion['shift_id'] > 0 ) {
					continue;
				}
				$lines[] = array(
					'shift_id'      => 0,
					'correction_id' => (int) $suggestion['id'],
					'in_hms'        => (string) $suggestion['proposed_in_hms'],
					'out_hms'       => (string) $suggestion['proposed_out_hms'],
					'out_next_day'  => ! empty( $suggestion['out_next_day'] ),
					'reason'        => (string) $suggestion['reason'],
					'is_open'       => false,
					'is_stale'      => false,
					'is_missing_in' => false,
					'is_long'       => false,
					'pending'       => true,
				);
			}

			if ( empty( $lines ) ) {
				$lines[] = array(
					'shift_id'      => 0,
					'correction_id' => 0,
					'in_hms'        => '',
					'out_hms'       => '',
					'reason'        => '',
					'out_next_day'  => false,
					'is_open'       => false,
					'is_stale'      => false,
					'is_missing_in' => false,
					'is_long'       => false,
					'pending'       => false,
				);
			}

			$has_pending = false;
			foreach ( $lines as $line ) {
				if ( ! empty( $line['pending'] ) ) {
					$has_pending = true;
					break;
				}
			}

			$days[] = array(
				'date'        => $cursor,
				'day_num'     => $time->date_immutable( $cursor ) ? $time->date_immutable( $cursor )->format( 'j' ) : '',
				'weekday'     => $time->format_weekday( $cursor ),
				'date_label'  => $time->format_day_label( $cursor ),
				'lines'       => $lines,
				'has_pending' => $has_pending,
			);
			$cursor = $time->shift_date( $cursor, 1 );
			++$guard;
		}

		return array(
			'period' => $period,
			'days'   => $days,
			'name'   => css_tc_addon()->employees->display_name( $user_id ),
		);
	}

	/**
	 * Flag or unflag a day in the current pay period.
	 *
	 * @param int    $user_id Employee.
	 * @param string $date    Y-m-d.
	 * @param bool   $on      Whether the flag should be set.
	 * @return true|WP_Error
	 */
	public function set_flag( $user_id, $date, $on ) {
		$date = sanitize_text_field( (string) $date );
		$open = css_tc_addon()->pay_periods->assert_open_date( $date );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$flags = $this->flagged_dates( $user_id );
		$has   = in_array( $date, $flags, true );
		if ( $on && ! $has ) {
			$flags[] = $date;
		} elseif ( ! $on && $has ) {
			$flags = array_values(
				array_filter(
					$flags,
					static function ( $item ) use ( $date ) {
						return $item !== $date;
					}
				)
			);
		}

		update_user_meta( (int) $user_id, self::FLAG_META, $flags );
		return true;
	}

	/**
	 * Remove one date from the employee's request flags. Closed periods are allowed.
	 *
	 * @param int    $user_id Employee.
	 * @param string $date    Y-m-d.
	 * @return void
	 */
	public function clear_flag( $user_id, $date ) {
		$date = sanitize_text_field( (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return;
		}

		$flags = $this->flagged_dates( $user_id );
		if ( ! in_array( $date, $flags, true ) ) {
			return;
		}

		$flags = array_values(
			array_filter(
				$flags,
				static function ( $item ) use ( $date ) {
					return $item !== $date;
				}
			)
		);
		update_user_meta( (int) $user_id, self::FLAG_META, $flags );
	}
}
