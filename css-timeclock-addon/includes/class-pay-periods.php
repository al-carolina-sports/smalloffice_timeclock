<?php
/**
 * Pay periods for hours, overtime, PTO, and corrections.
 * Weeks are Monday–Sunday. Default is biweekly from Monday 2026-09-07.
 * Css_Tc_Payday reads these same bounds from containing() when its schedule
 * follows the timecard pay period. Do not change this math to follow a payday.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the current, previous, and older pay periods from addon settings.
 */
class Css_Tc_Pay_Periods {

	const DEFAULT_ANCHOR = '2026-09-07';
	const DEFAULT_LENGTH = 'biweekly';

	/**
	 * @return Css_Tc_Time
	 */
	private function time() {
		return css_tc_addon()->time;
	}

	/**
	 * @return string weekly|biweekly
	 */
	public function length() {
		$settings = css_tc_addon()->get_settings();
		$length   = isset( $settings['pay_period_length'] ) ? (string) $settings['pay_period_length'] : self::DEFAULT_LENGTH;
		return 'weekly' === $length ? 'weekly' : 'biweekly';
	}

	/**
	 * @return int 7 or 14.
	 */
	public function length_days() {
		return 'weekly' === $this->length() ? 7 : 14;
	}

	/**
	 * Anchor Monday (Y-m-d). Invalid stored values fall back to the default.
	 *
	 * @return string
	 */
	public function anchor() {
		$settings = css_tc_addon()->get_settings();
		$anchor   = isset( $settings['pay_period_anchor'] ) ? (string) $settings['pay_period_anchor'] : self::DEFAULT_ANCHOR;
		if ( ! $this->is_monday( $anchor ) ) {
			return self::DEFAULT_ANCHOR;
		}
		return $anchor;
	}

	/**
	 * @param string $date Y-m-d.
	 * @return bool
	 */
	public function is_monday( $date ) {
		$day = $this->time()->date_immutable( $date );
		return $day && '1' === $day->format( 'N' );
	}

	/**
	 * Monday–Sunday period that contains a calendar day.
	 *
	 * period_for_date() and Pay & Payday bands that follow the timecard
	 * schedule both call this, so the start and end cannot drift apart.
	 * A non-Monday anchor falls back to DEFAULT_ANCHOR, same as anchor().
	 *
	 * @param string            $date     Y-m-d.
	 * @param string            $length   weekly|biweekly.
	 * @param string            $anchor   Monday Y-m-d.
	 * @param DateTimeZone|null $timezone Site timezone. UTC when omitted.
	 * @return array{start:string,end:string,length:int,index:int}|null
	 */
	public static function containing( $date, $length, $anchor, $timezone = null ) {
		if ( ! $timezone instanceof DateTimeZone ) {
			$timezone = new DateTimeZone( 'UTC' );
		}
		$anchor_day = self::ymd_in_zone( $anchor, $timezone );
		if ( ! $anchor_day || '1' !== $anchor_day->format( 'N' ) ) {
			$anchor_day = self::ymd_in_zone( self::DEFAULT_ANCHOR, $timezone );
		}
		$day = self::ymd_in_zone( $date, $timezone );
		if ( ! $anchor_day || ! $day ) {
			return null;
		}

		$len   = ( 'weekly' === $length ) ? 7 : 14;
		$diff  = (int) $anchor_day->diff( $day )->format( '%r%a' );
		$index = (int) floor( $diff / $len );
		$start = $anchor_day->modify( ( $index * $len >= 0 ? '+' : '' ) . ( $index * $len ) . ' days' );
		$end   = $start->modify( '+' . ( $len - 1 ) . ' days' );

		return array(
			'start'  => $start->format( 'Y-m-d' ),
			'end'    => $end->format( 'Y-m-d' ),
			'length' => $len,
			'index'  => $index,
		);
	}

	/**
	 * @param string       $date     Y-m-d.
	 * @param DateTimeZone $timezone Zone.
	 * @return DateTimeImmutable|null Midnight in $timezone.
	 */
	private static function ymd_in_zone( $date, $timezone ) {
		$date = trim( (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$dt     = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $dt || ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) ) {
			return null;
		}
		return $dt;
	}

	/**
	 * Period that contains a site calendar day.
	 *
	 * @param string $date Y-m-d in the site timezone.
	 * @return array<string,mixed>|null
	 */
	public function period_for_date( $date ) {
		$bounds = self::containing( $date, $this->length(), $this->anchor(), $this->time()->timezone() );
		if ( ! $bounds ) {
			return null;
		}
		return $this->describe( $bounds['start'], $bounds['end'] );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function current_period() {
		return $this->period_for_date( $this->time()->site_today() );
	}

	/**
	 * The period immediately before $period.
	 *
	 * @param array<string,mixed> $period Period from describe().
	 * @return array<string,mixed>|null
	 */
	public function period_before( $period ) {
		if ( empty( $period['start'] ) ) {
			return null;
		}
		$prev = $this->time()->shift_date( (string) $period['start'], -1 );
		return $this->period_for_date( $prev );
	}

	/**
	 * Current, previous, then older periods (read-only). No future periods.
	 *
	 * @param int $older How many periods before the previous one.
	 * @return array<int,array<string,mixed>>
	 */
	public function dropdown_periods( $older = 6 ) {
		$current = $this->current_period();
		if ( ! $current ) {
			return array();
		}

		$list   = array( $current );
		$cursor = $current;
		$total  = 1 + max( 0, (int) $older );
		for ( $i = 0; $i < $total; $i++ ) {
			$cursor = $this->period_before( $cursor );
			if ( ! $cursor ) {
				break;
			}
			$list[] = $cursor;
		}

		return $list;
	}

	/**
	 * @param string $start Y-m-d.
	 * @return array<string,mixed>|null
	 */
	public function period_by_start( $start ) {
		$period = $this->period_for_date( $start );
		if ( ! $period || $period['start'] !== $start ) {
			return null;
		}
		return $period;
	}

	/**
	 * Only the current pay period accepts corrections.
	 *
	 * @param string $date Y-m-d site day.
	 * @return bool
	 */
	public function is_open_date( $date ) {
		$current = $this->current_period();
		if ( ! $current || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return false;
		}
		return $date >= $current['start'] && $date <= $current['end'];
	}

	/**
	 * @param string $date Y-m-d.
	 * @return true|WP_Error
	 */
	public function assert_open_date( $date ) {
		if ( $this->is_open_date( $date ) ) {
			return true;
		}
		return new WP_Error(
			'css_tc_closed_period',
			__( 'That day is in a closed pay period. Past pay periods are display-only.', 'css-timeclock-addon' )
		);
	}

	/**
	 * A correction may change a shift only when its clock-in day is in the open period.
	 *
	 * Clock-out may fall on the next calendar day (overnight) even if that day
	 * is the first day of the following period.
	 *
	 * @param string $clock_in_stored  Proposed or original UTC clock-in. Required.
	 * @param string $clock_out_stored Proposed UTC clock-out, may be empty.
	 * @return true|WP_Error
	 */
	public function assert_shift_times_open( $clock_in_stored, $clock_out_stored = '' ) {
		$time    = $this->time();
		$in_date = $time->site_date_of( $clock_in_stored );
		$open    = $this->assert_open_date( $in_date );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		if ( '' === (string) $clock_out_stored ) {
			return true;
		}

		$out_date = $time->site_date_of( $clock_out_stored );
		if ( '' === $out_date ) {
			return new WP_Error( 'css_tc_bad_date', __( 'Clock-out time is not valid.', 'css-timeclock-addon' ) );
		}

		$current = $this->current_period();
		$latest  = $time->shift_date( (string) $current['end'], 1 );
		if ( $out_date < $current['start'] || $out_date > $latest ) {
			return new WP_Error(
				'css_tc_closed_period',
				__( 'That clock-out would change time outside the current pay period.', 'css-timeclock-addon' )
			);
		}

		return true;
	}

	/**
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array<string,mixed>
	 */
	private function describe( $start, $end ) {
		$time    = $this->time();
		$today   = $time->site_today();
		$current = ( $today >= $start && $today <= $end );
		$previous = false;
		if ( ! $current ) {
			$next_start = $time->shift_date( $end, 1 );
			$next_end   = $time->shift_date( $next_start, $this->length_days() - 1 );
			$previous   = ( $today >= $next_start && $today <= $next_end );
		}

		$range = $time->format_mdy( $start ) . ' - ' . $time->format_mdy( $end );
		if ( $current ) {
			$kind  = 'current';
			$label = sprintf(
				/* translators: %s: date range m/d/Y - m/d/Y */
				__( 'Current Pay Period, %s', 'css-timeclock-addon' ),
				$range
			);
		} elseif ( $previous ) {
			$kind  = 'previous';
			$label = sprintf(
				/* translators: %s: date range m/d/Y - m/d/Y */
				__( 'Previous Pay Period, %s', 'css-timeclock-addon' ),
				$range
			);
		} else {
			$kind  = 'older';
			$label = sprintf(
				/* translators: %s: date range m/d/Y - m/d/Y */
				__( 'Pay Period, %s', 'css-timeclock-addon' ),
				$range
			);
		}

		return array(
			'start'       => $start,
			'end'         => $end,
			'range'       => $range,
			'label'       => $label,
			'kind'        => $kind,
			'is_current'  => $current,
			'is_previous' => $previous,
			'is_open'     => $current,
			'weeks'       => $this->weeks( $start, $end ),
		);
	}

	/**
	 * Monday–Sunday chunks inside the period. The anchor is a Monday and the
	 * length is 7 or 14, so each chunk is one calendar week.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array<int,array<string,string>>
	 */
	private function weeks( $start, $end ) {
		$time  = $this->time();
		$weeks = array();
		$cursor = $start;
		$index  = 1;
		$guard  = 0;

		while ( $cursor <= $end && $guard < 6 ) {
			$week_end = $time->shift_date( $cursor, 6 );
			if ( $week_end > $end ) {
				$week_end = $end;
			}
			$weeks[] = array(
				'index' => $index,
				'start' => $cursor,
				'end'   => $week_end,
				'label' => sprintf(
					/* translators: %d: week number inside the pay period */
					__( 'Week %d', 'css-timeclock-addon' ),
					$index
				),
				'range' => $time->format_mdy( $cursor ) . ' - ' . $time->format_mdy( $week_end ),
			);
			$cursor = $time->shift_date( $week_end, 1 );
			++$index;
			++$guard;
		}

		return $weeks;
	}
}
