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
				$days[] = $this->day_cell( $cursor, $list, $day_seconds, $period, $pending, $approved );
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

		// Overtime: hours past the configured threshold per window of weeks,
		// allocated segment by segment in time order so each company is
		// charged for the overtime its hours caused. Worked seconds of every
		// code count toward the threshold; overtime is taken out of Regular.
		$rule     = css_tc_addon()->overtime->rule();
		$segments = array();
		foreach ( $week_rows as $w => $week_row ) {
			foreach ( $shifts as $shift ) {
				if ( $shift['seconds'] > 0 && empty( $shift['exclude_from_overtime'] ) && $shift['work_date'] >= $week_row['start'] && $shift['work_date'] <= $week_row['end'] ) {
					$segments[ (int) $shift['id'] ] = array(
						'week'          => (int) $w,
						'seconds'       => (int) $shift['seconds'],
						'group'         => (int) $shift['company_id'],
						'shift_id'      => (int) $shift['id'],
						'work_date'     => (string) $shift['work_date'],
						'department_id' => (int) $shift['department_id'],
						'location_id'   => (int) $shift['location_id'],
						'company_id'    => (int) $shift['company_id'],
						'label'         => (string) $shift['assignment'],
						'overtime'      => 0,
					);
				}
			}
		}
		$overtime_total = 0;
		if ( $rule['enabled'] ) {
			$alloc = Css_Tc_Overtime::allocate( $segments, $rule['threshold_seconds'], $rule['weeks'], 'per_company' === $rule['scope'] );
			foreach ( $alloc as $sid => $ot ) {
				$segments[ $sid ]['overtime'] = (int) $ot;
				$overtime_total += (int) $ot;
			}
		}
		foreach ( $week_rows as $i => $week_row ) {
			$ot = 0;
			foreach ( $segments as $segment ) {
				if ( $segment['week'] === $i ) {
					$ot += $segment['overtime'];
				}
			}
			$week_rows[ $i ]['overtime_seconds'] = $ot;
			$week_rows[ $i ]['overtime_hm']      = $ot > 0 ? css_tc_addon()->time->format_duration( $ot ) : '';
		}
		if ( $rule['enabled'] ) {
			$overtime_total = min( $overtime_total, (int) $buckets[ Css_Tc_Pay_Codes::REGULAR ] );
			$buckets[ Css_Tc_Pay_Codes::REGULAR ] -= $overtime_total;
			$buckets[ Css_Tc_Pay_Codes::OVERTIME ] = $overtime_total;
		}

		$by_company = array();
		foreach ( $segments as $segment ) {
			$cid = $segment['company_id'];
			if ( ! isset( $by_company[ $cid ] ) ) {
				$by_company[ $cid ] = array(
					'company_id' => $cid,
					'name'       => $cid > 0 ? css_tc_addon()->organization->company_name( $cid ) : __( 'No company', 'css-timeclock-addon' ),
					'total'      => 0,
					'overtime'   => 0,
					'regular'    => 0,
				);
			}
			$by_company[ $cid ]['total']    += $segment['seconds'];
			$by_company[ $cid ]['overtime'] += $segment['overtime'];
			$by_company[ $cid ]['regular']   = $by_company[ $cid ]['total'] - $by_company[ $cid ]['overtime'];
		}

		// Paid holidays: a fixed number of hours per observed holiday, not
		// worked time, so they never count toward overtime. Charged to the
		// employee's home department (its company) when departments are on.
		$holiday_seconds = 0;
		$holiday_days    = array();
		$holiday_dept    = 0;
		if ( css_tc_addon()->holidays->enabled() ) {
			$holiday_days = css_tc_addon()->holidays->for_employee( $user_id, (string) $period['start'], (string) $period['end'] );
			foreach ( $holiday_days as $info ) {
				$holiday_seconds += (int) $info['seconds'];
			}
			$buckets[ Css_Tc_Pay_Codes::HOLIDAY ] = $holiday_seconds;
			if ( css_tc_addon()->organization->enabled() ) {
				$holiday_dept = css_tc_addon()->organization->home( $user_id );
			}
			$from = css_tc_addon()->holidays->eligible_from( $user_id );
			foreach ( $week_rows as $w => $week_row ) {
				$week_holiday = 0;
				foreach ( $week_row['days'] as $d => $day ) {
					if ( ! isset( $holiday_days[ $day['date'] ] ) ) {
						continue;
					}
					$info = $holiday_days[ $day['date'] ];
					$note = '';
					if ( ! $info['eligible'] && 'leave' === ( $info['blocked'] ?? '' ) ) {
						$note = __( 'on leave, not paid (a manager can add it under Time off)', 'css-timeclock-addon' );
					} elseif ( ! $info['eligible'] && 'inactive' === ( $info['blocked'] ?? '' ) ) {
						$note = __( 'after last day worked, not paid', 'css-timeclock-addon' );
					} elseif ( ! $info['eligible'] ) {
						$hire = css_tc_addon()->holidays->hire_date( $user_id );
						$note = ( '' !== $hire && $day['date'] < $hire )
							? __( 'before hire date, not paid', 'css-timeclock-addon' )
							/* translators: %s: date holiday pay starts */
							: sprintf( __( 'introductory period, not paid (holiday pay starts %s)', 'css-timeclock-addon' ), css_tc_addon()->time->format_day_label( $from ) );
					}
					$week_rows[ $w ]['days'][ $d ]['holiday']         = implode( ' · ', $info['names'] );
					$week_rows[ $w ]['days'][ $d ]['holiday_seconds'] = (int) $info['seconds'];
					$week_rows[ $w ]['days'][ $d ]['holiday_hm']      = $info['seconds'] > 0 ? css_tc_addon()->time->format_duration( (int) $info['seconds'] ) : '';
					$week_rows[ $w ]['days'][ $d ]['holiday_note']    = $note;
					$week_holiday += (int) $info['seconds'];
				}
				$week_rows[ $w ]['holiday_seconds'] = $week_holiday;
				$week_rows[ $w ]['holiday_hm']      = $week_holiday > 0 ? css_tc_addon()->time->format_duration( $week_holiday ) : '';
			}
			if ( $holiday_seconds > 0 ) {
				$cid = $holiday_dept > 0 && css_tc_addon()->organization->department( $holiday_dept ) ? (int) css_tc_addon()->organization->department( $holiday_dept )['company_id'] : 0;
				if ( ! isset( $by_company[ $cid ] ) ) {
					$by_company[ $cid ] = array(
						'company_id' => $cid,
						'name'       => $cid > 0 ? css_tc_addon()->organization->company_name( $cid ) : __( 'No company', 'css-timeclock-addon' ),
						'total'      => 0,
						'overtime'   => 0,
						'regular'    => 0,
					);
				}
				$by_company[ $cid ]['holiday'] = $holiday_seconds;
			}
		}
		// Approved PTO and sick time: paid, not worked, never overtime.
		// Pending requests are shown on the day but add no hours.
		$leave_seconds = array( 'pto' => 0, 'sick' => 0 );
		if ( css_tc_addon()->leave->any_enabled() ) {
			$leave_days = css_tc_addon()->leave->by_date( $user_id, (string) $period['start'], (string) $period['end'] );
			foreach ( $week_rows as $w => $week_row ) {
				$week_leave = 0;
				foreach ( $week_row['days'] as $d => $day ) {
					$items = array();
					foreach ( $leave_days[ $day['date'] ] ?? array() as $row ) {
						$items[] = array(
							'type'    => $row['type'],
							'label'   => Css_Tc_Leave::label( $row['type'] ),
							'hm'      => css_tc_addon()->time->format_duration( (int) $row['seconds'] ),
							'pending' => 'pending' === $row['status'],
						);
						if ( 'approved' === $row['status'] && 'holiday' === $row['type'] ) {
							// Holiday added by a manager: Holiday pay code, like an automatic holiday.
							$holiday_seconds                     += (int) $row['seconds'];
							$buckets[ Css_Tc_Pay_Codes::HOLIDAY ] = ( $buckets[ Css_Tc_Pay_Codes::HOLIDAY ] ?? 0 ) + (int) $row['seconds'];
							$week_leave                          += (int) $row['seconds'];
						} elseif ( 'approved' === $row['status'] && isset( $leave_seconds[ $row['type'] ] ) ) {
							$leave_seconds[ $row['type'] ] += (int) $row['seconds'];
							$week_leave                   += (int) $row['seconds'];
						}
					}
					$week_rows[ $w ]['days'][ $d ]['leave'] = $items;
				}
				$week_rows[ $w ]['leave_seconds'] = $week_leave;
				$week_rows[ $w ]['leave_hm']      = $week_leave > 0 ? css_tc_addon()->time->format_duration( $week_leave ) : '';
			}
			foreach ( $leave_seconds as $code => $sec ) {
				if ( css_tc_addon()->leave->type_enabled( $code ) ) {
					$buckets[ $code ] = $sec;
				}
			}
			$leave_total = array_sum( $leave_seconds );
			if ( $leave_total > 0 ) {
				if ( 0 === $holiday_dept && css_tc_addon()->organization->enabled() ) {
					$holiday_dept = css_tc_addon()->organization->home( $user_id );
				}
				$cid = $holiday_dept > 0 && css_tc_addon()->organization->department( $holiday_dept ) ? (int) css_tc_addon()->organization->department( $holiday_dept )['company_id'] : 0;
				if ( ! isset( $by_company[ $cid ] ) ) {
					$by_company[ $cid ] = array(
						'company_id' => $cid,
						'name'       => $cid > 0 ? css_tc_addon()->organization->company_name( $cid ) : __( 'No company', 'css-timeclock-addon' ),
						'total'      => 0,
						'overtime'   => 0,
						'regular'    => 0,
					);
				}
				$by_company[ $cid ]['leave'] = $leave_total;
			}
		}
		$paid_seconds = $total_seconds + $holiday_seconds + array_sum( $leave_seconds );

		$pay_rows = array();
		foreach ( $defs as $slug => $def ) {
			$seconds = isset( $buckets[ $slug ] ) ? (int) $buckets[ $slug ] : 0;
			$always  = in_array( $slug, array( Css_Tc_Pay_Codes::REGULAR, Css_Tc_Pay_Codes::OVERTIME, Css_Tc_Pay_Codes::HOLIDAY, Css_Tc_Pay_Codes::PTO, Css_Tc_Pay_Codes::SICK ), true );
			if ( ! $always && $seconds < 1 ) {
				continue;
			}
			$pay_rows[] = array(
				'slug'    => $slug,
				'label'   => isset( $def['label'] ) ? (string) $def['label'] : $slug,
				'seconds' => $seconds,
				'hm'      => css_tc_addon()->time->format_duration( $seconds ),
			);
		}

		// A long switched chain counts once, not once per segment.
		$long_chains = array();
		foreach ( $shifts as $shift ) {
			if ( ! empty( $shift['is_long'] ) ) {
				$long_chains[ (string) $shift['chain_key'] ] = true;
			}
		}
		$long_count = count( $long_chains );
		$excluded   = 0;
		foreach ( $shifts as $shift ) {
			if ( ! empty( $shift['exclude_from_overtime'] ) ) {
				$excluded += max( 0, (int) $shift['seconds'] );
			}
		}

		return array(
			'user_id'          => $user_id,
			'employee_name'    => css_tc_addon()->employees->display_name( $user_id ),
			'initials'         => css_tc_addon()->employees->initials( $user_id ),
			'period'           => $period,
			'total_seconds'    => $total_seconds,
			'total_hm'         => css_tc_addon()->time->format_duration( $total_seconds ),
			'holiday_seconds'  => $holiday_seconds,
			'holiday_department_id' => $holiday_dept,
			'leave_seconds'    => $leave_seconds,
			'nonwork_department_id' => $holiday_dept,
			'paid_seconds'     => $paid_seconds,
			'paid_hm'          => css_tc_addon()->time->format_duration( $paid_seconds ),
			'pay_codes'        => $pay_rows,
			'weeks'            => $week_rows,
			'long_shift_count' => $long_count,
			'overtime_seconds' => $overtime_total,
			'overtime_excluded_seconds' => $excluded,
			'overtime_excluded_hm'      => $excluded > 0 ? css_tc_addon()->time->format_duration( $excluded ) : '',
			'segments'         => array_values( $segments ),
			'by_company'       => array_values( $by_company ),
			'overtime_rule'    => $rule,
			'overtime_note'    => css_tc_addon()->overtime->describe( $rule ),
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

		// Switched segments form one continuous shift. Flag the whole chain as
		// long when the chain is, even if each segment is short.
		$chains = array();
		foreach ( $rows as $row ) {
			$chains[ $row['chain_key'] ] = ( isset( $chains[ $row['chain_key'] ] ) ? $chains[ $row['chain_key'] ] : 0 ) + (int) $row['seconds'];
		}
		$long_limit = css_tc_addon()->punches->long_shift_hours() * HOUR_IN_SECONDS;
		foreach ( $rows as $i => $row ) {
			if ( $chains[ $row['chain_key'] ] > $long_limit ) {
				$rows[ $i ]['is_long'] = true;
			}
			$rows[ $i ]['exclude_from_overtime'] = Css_Tc_Overtime::exclude_from_overtime( $rows[ $i ] );
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				$cmp = strcmp( (string) $a['work_date'], (string) $b['work_date'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return Css_Tc_Time::compare_shift_rows( $a, $b );
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
	 * @param array<string,bool>              $approved Dates with an approved correction.
	 * @return array<string,mixed>
	 */
	private function day_cell( $date, $shifts, $seconds, $period, $pending, $approved ) {
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
				'is_long'          => ! empty( $shift['is_long'] ),
				'is_out_before_in' => ! empty( $shift['is_out_before_in'] ),
				'out_next_day'     => ! empty( $shift['out_next_day'] ),
				'exclude_from_overtime' => ! empty( $shift['exclude_from_overtime'] ),
				'department_id'    => (int) $shift['department_id'],
				'assignment'       => (string) $shift['assignment'],
				'switched'         => ! empty( $shift['switched'] ),
			);
		}

		$day_pending = isset( $pending[ $date ] ) ? $pending[ $date ] : array();
		if ( ! empty( $day_pending ) ) {
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
					'department_id' => ( $overlay && ! empty( $overlay['proposed_department_id'] ) ) ? (int) $overlay['proposed_department_id'] : (int) $shift['department_id'],
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
					'department_id' => ! empty( $suggestion['proposed_department_id'] ) ? (int) $suggestion['proposed_department_id'] : 0,
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
					'department_id' => 0,
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
				'is_future'   => ( $cursor > $time->site_today() ),
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
	 * The timecard does not read this list. A pending correction drives the
	 * Pending badge and Cancel request. The handler stays so a cached
	 * Request change form does not fail.
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

	/**
	 * Remove one date when no pending correction remains for that employee and day.
	 *
	 * @param int    $user_id Employee.
	 * @param string $date    Y-m-d.
	 * @return void
	 */
	public function clear_flag_if_no_pending( $user_id, $date ) {
		if ( css_tc_addon()->corrections->pending_for_day( $user_id, $date ) ) {
			return;
		}
		$this->clear_flag( $user_id, $date );
	}
}
