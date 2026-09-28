<?php
/**
 * Configurable overtime rule: hours past a threshold within a window of
 * whole weeks are overtime.
 *
 * Examples:
 *   40 hours per 1 week  — US FLSA default.
 *   80 hours per 2 weeks — a biweekly averaging rule.
 *   44 hours per 1 week  — several Canadian provinces.
 *
 * Windows are made of the pay period's own Monday–Sunday weeks, counted from
 * the period start, so a window never spans two pay periods.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Overtime settings and the pure split math.
 */
class Css_Tc_Overtime {

	const MAX_WEEKS = 2;

	/**
	 * Current rule from settings.
	 *
	 * @return array{enabled:bool,hours:float,weeks:int,threshold_seconds:int}
	 */
	public function rule() {
		$settings = css_tc_addon()->get_settings();
		$enabled  = ! empty( $settings['overtime_enabled'] );
		$hours    = isset( $settings['overtime_hours'] ) ? (float) $settings['overtime_hours'] : 40.0;
		$weeks    = isset( $settings['overtime_weeks'] ) ? (int) $settings['overtime_weeks'] : 1;

		$period_weeks = (int) ( css_tc_addon()->pay_periods->length_days() / 7 );
		$weeks        = self::clamp_weeks( $weeks, $period_weeks );

		if ( $hours <= 0 ) {
			$enabled = false;
		}

		$scope = isset( $settings['overtime_scope'] ) && 'per_company' === $settings['overtime_scope'] ? 'per_company' : 'combined';

		return array(
			'scope'             => $scope,
			'enabled'           => $enabled,
			'hours'             => $hours,
			'weeks'             => $weeks,
			'threshold_seconds' => (int) round( $hours * HOUR_IN_SECONDS ),
		);
	}

	/**
	 * Window length must fit evenly inside the pay period.
	 *
	 * @param int $weeks        Requested weeks per window.
	 * @param int $period_weeks Weeks in one pay period.
	 * @return int
	 */
	public static function clamp_weeks( $weeks, $period_weeks ) {
		$weeks        = max( 1, min( self::MAX_WEEKS, (int) $weeks ) );
		$period_weeks = max( 1, (int) $period_weeks );
		if ( $weeks > $period_weeks || 0 !== $period_weeks % $weeks ) {
			return 1;
		}
		return $weeks;
	}

	/**
	 * Split worked seconds into overtime per week.
	 *
	 * Weeks are grouped into windows of $window_weeks, in order. Within a
	 * window, overtime starts in the week where the running total passes the
	 * threshold, so a 30h + 50h biweekly window with an 80h threshold has no
	 * overtime, while 1-week windows at 40h give 0h + 10h.
	 *
	 * Pure function: no WordPress calls, so it can be tested directly.
	 *
	 * @param int[] $week_seconds      Worked seconds per week, in order.
	 * @param int   $threshold_seconds Regular limit per window.
	 * @param int   $window_weeks      Weeks per window.
	 * @return int[] Overtime seconds per week, same keys as input order.
	 */
	public static function split( $week_seconds, $threshold_seconds, $window_weeks ) {
		$week_seconds      = array_values( array_map( 'intval', (array) $week_seconds ) );
		$threshold_seconds = max( 0, (int) $threshold_seconds );
		$window_weeks      = max( 1, (int) $window_weeks );
		$overtime          = array_fill( 0, count( $week_seconds ), 0 );

		$count = count( $week_seconds );
		for ( $start = 0; $start < $count; $start += $window_weeks ) {
			$running = 0;
			for ( $i = $start; $i < min( $count, $start + $window_weeks ); $i++ ) {
				$worked = max( 0, $week_seconds[ $i ] );
				$before = $running;
				$running += $worked;
				if ( $running > $threshold_seconds ) {
					$overtime[ $i ] = $running - max( $before, $threshold_seconds );
				}
			}
		}

		return $overtime;
	}

	/**
	 * Overtime per work segment, in time order.
	 *
	 * Each segment has a week index, worked seconds and a group (company).
	 * Within each window the running total grows segment by segment; the
	 * part of a segment past the threshold is overtime. So overtime lands on
	 * whichever company's hours crossed the limit.
	 *
	 * With $per_group, each company keeps its own running total, so hours
	 * are not added up across companies.
	 *
	 * Pure function: no WordPress calls.
	 *
	 * @param array<int|string,array{week:int,seconds:int,group:int|string}> $segments          Time order.
	 * @param int                                                           $threshold_seconds Regular limit per window.
	 * @param int                                                           $window_weeks      Weeks per window.
	 * @param bool                                                          $per_group         Separate totals per group.
	 * @return array<int|string,int> Overtime seconds keyed like $segments.
	 */
	public static function allocate( $segments, $threshold_seconds, $window_weeks, $per_group = false ) {
		$threshold_seconds = max( 0, (int) $threshold_seconds );
		$window_weeks      = max( 1, (int) $window_weeks );
		$running           = array();
		$out               = array();
		foreach ( $segments as $key => $segment ) {
			$window = (int) floor( max( 0, (int) $segment['week'] ) / $window_weeks );
			$bucket = $window . '|' . ( $per_group ? (string) $segment['group'] : '*' );
			$before = isset( $running[ $bucket ] ) ? $running[ $bucket ] : 0;
			$worked = max( 0, (int) $segment['seconds'] );
			$after  = $before + $worked;
			$running[ $bucket ] = $after;
			$out[ $key ] = $after > $threshold_seconds ? $after - max( $before, $threshold_seconds ) : 0;
		}
		return $out;
	}

	/**
	 * Short description for screens, e.g. "Overtime after 40 hours per week".
	 *
	 * @param array<string,mixed>|null $rule Rule from rule().
	 * @return string
	 */
	public function describe( $rule = null ) {
		$rule = $rule ? $rule : $this->rule();
		if ( empty( $rule['enabled'] ) ) {
			return __( 'Overtime is not calculated. All hours are Regular.', 'css-timeclock-addon' );
		}
		$hours = rtrim( rtrim( number_format( (float) $rule['hours'], 2, '.', '' ), '0' ), '.' );
		if ( 1 === (int) $rule['weeks'] ) {
			/* translators: %s: hours */
			return sprintf( __( 'Overtime after %s hours per week.', 'css-timeclock-addon' ), $hours );
		}
		/* translators: 1: hours, 2: number of weeks */
		return sprintf( __( 'Overtime after %1$s hours per %2$d weeks.', 'css-timeclock-addon' ), $hours, (int) $rule['weeks'] );
	}
}
