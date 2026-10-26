<?php
/**
 * Pay & Payday display calendar.
 *
 * Periods and paydays drawn on calendars. This does not change the Monday
 * hours engine in Css_Tc_Pay_Periods (timecards, overtime, PTO, corrections).
 *
 * A saved history row applies from its effective date forward. The first row
 * also covers earlier dates. A pay period that already started keeps the
 * schedule that was in effect on its start date.
 *
 * Date math uses UTC calendar days so a DST change cannot skip or repeat a date.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payday periods, the 14-day payday series, and the month grid.
 */
class Css_Tc_Payday {

	const OPTION = 'css_tc_payday_history';

	const SERIES_INTERVAL = 14;

	/**
	 * Empty schedule. Nothing is shown until a manager saves the tab.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_config() {
		return array(
			'frequency'       => 'biweekly',
			'anchor'          => '',
			'week_start'      => 0,
			'payday_mode'     => 'weekday',
			'payday_weekday'  => 5,
			'payday_days'     => 6,
			'payday_anchor'   => '',
			'shift'           => 'previous',
			'holidays'        => '',
			'holiday_set'     => array( 'exact' => array(), 'annual' => array() ),
			'show_employee'   => 1,
			'show_admin'      => 1,
			'show_closes'     => 1,
			'color_a'         => '#2271b1',
			'color_b'         => '#8c5ac8',
			'marker'          => 'badge',
			'marker_color'    => '#00a32a',
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function frequencies() {
		return array(
			'weekly'              => __( 'Weekly', 'css-timeclock-addon' ),
			'biweekly'            => __( 'Bi-weekly (every 2 weeks)', 'css-timeclock-addon' ),
			'semimonthly_1_15'    => __( 'Semi-monthly (1st & 15th)', 'css-timeclock-addon' ),
			'semimonthly_15_eom'  => __( 'Semi-monthly (15th & last day)', 'css-timeclock-addon' ),
			'monthly'             => __( 'Monthly', 'css-timeclock-addon' ),
		);
	}

	/**
	 * @param int $dow 0 Sunday … 6 Saturday.
	 * @return string
	 */
	public static function weekday_name( $dow ) {
		$names = array(
			__( 'Sunday', 'css-timeclock-addon' ),
			__( 'Monday', 'css-timeclock-addon' ),
			__( 'Tuesday', 'css-timeclock-addon' ),
			__( 'Wednesday', 'css-timeclock-addon' ),
			__( 'Thursday', 'css-timeclock-addon' ),
			__( 'Friday', 'css-timeclock-addon' ),
			__( 'Saturday', 'css-timeclock-addon' ),
		);
		$dow = (int) $dow;
		return isset( $names[ $dow ] ) ? $names[ $dow ] : $names[0];
	}

	/**
	 * @param int $dow 0 Sunday … 6 Saturday.
	 * @return string
	 */
	public static function weekday_short( $dow ) {
		$names = array(
			__( 'Sun', 'css-timeclock-addon' ),
			__( 'Mon', 'css-timeclock-addon' ),
			__( 'Tue', 'css-timeclock-addon' ),
			__( 'Wed', 'css-timeclock-addon' ),
			__( 'Thu', 'css-timeclock-addon' ),
			__( 'Fri', 'css-timeclock-addon' ),
			__( 'Sat', 'css-timeclock-addon' ),
		);
		$dow = (int) $dow;
		return isset( $names[ $dow ] ) ? $names[ $dow ] : $names[0];
	}

	/**
	 * @param int $month 1–12.
	 * @return string
	 */
	public static function month_short( $month ) {
		$names = array(
			1  => __( 'Jan', 'css-timeclock-addon' ),
			2  => __( 'Feb', 'css-timeclock-addon' ),
			3  => __( 'Mar', 'css-timeclock-addon' ),
			4  => __( 'Apr', 'css-timeclock-addon' ),
			5  => __( 'May', 'css-timeclock-addon' ),
			6  => __( 'Jun', 'css-timeclock-addon' ),
			7  => __( 'Jul', 'css-timeclock-addon' ),
			8  => __( 'Aug', 'css-timeclock-addon' ),
			9  => __( 'Sep', 'css-timeclock-addon' ),
			10 => __( 'Oct', 'css-timeclock-addon' ),
			11 => __( 'Nov', 'css-timeclock-addon' ),
			12 => __( 'Dec', 'css-timeclock-addon' ),
		);
		$month = (int) $month;
		return isset( $names[ $month ] ) ? $names[ $month ] : '';
	}

	/**
	 * @param int $month 1–12.
	 * @return string
	 */
	public static function month_name( $month ) {
		$names = array(
			1  => __( 'January', 'css-timeclock-addon' ),
			2  => __( 'February', 'css-timeclock-addon' ),
			3  => __( 'March', 'css-timeclock-addon' ),
			4  => __( 'April', 'css-timeclock-addon' ),
			5  => __( 'May', 'css-timeclock-addon' ),
			6  => __( 'June', 'css-timeclock-addon' ),
			7  => __( 'July', 'css-timeclock-addon' ),
			8  => __( 'August', 'css-timeclock-addon' ),
			9  => __( 'September', 'css-timeclock-addon' ),
			10 => __( 'October', 'css-timeclock-addon' ),
			11 => __( 'November', 'css-timeclock-addon' ),
			12 => __( 'December', 'css-timeclock-addon' ),
		);
		$month = (int) $month;
		return isset( $names[ $month ] ) ? $names[ $month ] : '';
	}

	/**
	 * @param string $ymd Y-m-d.
	 * @return bool
	 */
	public static function is_ymd( $ymd ) {
		if ( ! is_string( $ymd ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
			return false;
		}
		$year = (int) $m[1];
		if ( $year < 2000 || $year > 2100 ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], $year );
	}

	/**
	 * @param string $ymd Y-m-d.
	 * @return DateTimeImmutable|null UTC calendar day.
	 */
	public static function day( $ymd ) {
		if ( ! self::is_ymd( $ymd ) ) {
			return null;
		}
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, new DateTimeZone( 'UTC' ) );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $ymd ) {
			return null;
		}
		return $dt;
	}

	/**
	 * @param string $ymd  Y-m-d.
	 * @param int    $days Days to add. Negative steps backward.
	 * @return string Y-m-d or ''.
	 */
	public static function add_ymd( $ymd, $days ) {
		$dt = self::day( $ymd );
		if ( ! $dt ) {
			return '';
		}
		$days = (int) $days;
		$sign = $days >= 0 ? '+' : '';
		return $dt->modify( $sign . $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Signed whole days from $from to $to.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int|null
	 */
	public static function diff_days( $from, $to ) {
		$a = self::day( $from );
		$b = self::day( $to );
		if ( ! $a || ! $b ) {
			return null;
		}
		return (int) $a->diff( $b )->format( '%r%a' );
	}

	/**
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @param int $delta Months to add.
	 * @return array{0:int,1:int} Year and month.
	 */
	public static function shift_month( $year, $month, $delta ) {
		$index = ( (int) $year * 12 + ( (int) $month - 1 ) ) + (int) $delta;
		$y     = (int) floor( $index / 12 );
		$m     = $index % 12;
		if ( $m < 0 ) {
			$m += 12;
		}
		return array( $y, $m + 1 );
	}

	/**
	 * Year-month-day, clamped to the real last day of that month.
	 *
	 * @param int $year  Year.
	 * @param int $month Month. Values outside 1–12 roll into other years.
	 * @param int $day   Day of month.
	 * @return string Y-m-d or ''.
	 */
	public static function ymd( $year, $month, $day ) {
		list( $year, $month ) = self::shift_month( (int) $year, (int) $month, 0 );
		if ( $year < 2000 || $year > 2100 ) {
			return '';
		}
		$first = self::day( sprintf( '%04d-%02d-01', $year, $month ) );
		if ( ! $first ) {
			return '';
		}
		$dim = (int) $first->format( 't' );
		$day = (int) $day;
		if ( $day < 1 ) {
			$day = 1;
		}
		if ( $day > $dim ) {
			$day = $dim;
		}
		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * @param string $value    Raw color.
	 * @param string $fallback #rrggbb.
	 * @return string #rrggbb.
	 */
	public static function hex_color( $value, $fallback ) {
		$value = strtolower( trim( (string) $value ) );
		if ( preg_match( '/^#([0-9a-f]{6})$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $m ) ) {
			$c = $m[1];
			return '#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
		}
		return $fallback;
	}

	/**
	 * Mix a color toward white. $mix 0 keeps the color, 1 is white.
	 *
	 * @param string $hex Hex color.
	 * @param float  $mix Amount of white.
	 * @return string #rrggbb.
	 */
	public static function tint( $hex, $mix ) {
		$hex = self::hex_color( $hex, '#2271b1' );
		$r   = hexdec( substr( $hex, 1, 2 ) );
		$g   = hexdec( substr( $hex, 3, 2 ) );
		$b   = hexdec( substr( $hex, 5, 2 ) );
		$mix = (float) $mix;
		if ( $mix < 0 ) {
			$mix = 0;
		}
		if ( $mix > 1 ) {
			$mix = 1;
		}
		$r = (int) round( $r + ( 255 - $r ) * $mix );
		$g = (int) round( $g + ( 255 - $g ) * $mix );
		$b = (int) round( $b + ( 255 - $b ) * $mix );
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/**
	 * @param string $text Holiday textarea.
	 * @return array{exact:array<string,bool>,annual:array<string,bool>,invalid:string[],text:string}
	 */
	public static function parse_holidays( $text ) {
		$exact   = array();
		$annual  = array();
		$invalid = array();
		$kept    = array();
		$text    = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})\b/', $line, $m ) && self::is_ymd( $m[1] ) ) {
				$exact[ $m[1] ] = true;
				$kept[]         = $line;
				continue;
			}
			if ( preg_match( '/^(\d{2})-(\d{2})\b/', $line, $m ) && checkdate( (int) $m[1], (int) $m[2], 2024 ) ) {
				$annual[ $m[1] . '-' . $m[2] ] = true;
				$kept[]                        = $line;
				continue;
			}
			$invalid[] = $line;
		}
		return array(
			'exact'   => $exact,
			'annual'  => $annual,
			'invalid' => $invalid,
			'text'    => implode( "\n", $kept ),
		);
	}

	/**
	 * @param array<string,mixed> $input Raw config.
	 * @return array<string,mixed>
	 */
	public static function normalize_config( $input ) {
		$base = self::default_config();
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$freq = isset( $input['frequency'] ) ? (string) $input['frequency'] : $base['frequency'];
		if ( ! isset( self::frequencies()[ $freq ] ) ) {
			$freq = $base['frequency'];
		}
		$anchor = isset( $input['anchor'] ) ? (string) $input['anchor'] : '';
		if ( ! self::is_ymd( $anchor ) ) {
			$anchor = '';
		}
		$week = isset( $input['week_start'] ) ? (int) $input['week_start'] : 0;
		if ( $week < 0 || $week > 6 ) {
			$week = 0;
		}
		$mode = isset( $input['payday_mode'] ) ? (string) $input['payday_mode'] : 'weekday';
		if ( ! in_array( $mode, array( 'weekday', 'days', 'on_end', 'anchor' ), true ) ) {
			$mode = 'weekday';
		}
		$fixed = ( 'semimonthly_1_15' === $freq || 'semimonthly_15_eom' === $freq || 'monthly' === $freq );
		if ( 'on_end' === $mode && ! $fixed ) {
			$mode = 'weekday';
		}
		$dow = isset( $input['payday_weekday'] ) ? (int) $input['payday_weekday'] : 5;
		if ( $dow < 0 || $dow > 6 ) {
			$dow = 5;
		}
		$days = isset( $input['payday_days'] ) ? (int) $input['payday_days'] : 6;
		if ( $days < 0 ) {
			$days = 0;
		}
		if ( $days > 90 ) {
			$days = 90;
		}
		$pay_anchor = isset( $input['payday_anchor'] ) ? (string) $input['payday_anchor'] : '';
		if ( ! self::is_ymd( $pay_anchor ) ) {
			$pay_anchor = '';
		}
		$shift = isset( $input['shift'] ) ? (string) $input['shift'] : 'previous';
		if ( ! in_array( $shift, array( 'previous', 'next', 'keep' ), true ) ) {
			$shift = 'previous';
		}
		$marker = isset( $input['marker'] ) ? (string) $input['marker'] : 'badge';
		if ( ! in_array( $marker, array( 'badge', 'icon', 'star' ), true ) ) {
			$marker = 'badge';
		}
		$holidays = isset( $input['holidays'] ) ? (string) $input['holidays'] : '';
		if ( strlen( $holidays ) > 4000 ) {
			$holidays = substr( $holidays, 0, 4000 );
		}
		$parsed = self::parse_holidays( $holidays );
		$flag   = static function ( $key, $default ) use ( $input ) {
			if ( ! array_key_exists( $key, $input ) ) {
				return $default;
			}
			return empty( $input[ $key ] ) ? 0 : 1;
		};

		return array(
			'frequency'      => $freq,
			'anchor'         => $anchor,
			'week_start'     => $week,
			'payday_mode'    => $mode,
			'payday_weekday' => $dow,
			'payday_days'    => $days,
			'payday_anchor'  => $pay_anchor,
			'shift'          => $shift,
			'holidays'       => $holidays,
			'holiday_set'    => array(
				'exact'  => $parsed['exact'],
				'annual' => $parsed['annual'],
			),
			'show_employee'  => $flag( 'show_employee', 1 ),
			'show_admin'     => $flag( 'show_admin', 1 ),
			'show_closes'    => $flag( 'show_closes', 1 ),
			'color_a'        => self::hex_color( isset( $input['color_a'] ) ? $input['color_a'] : '', '#2271b1' ),
			'color_b'        => self::hex_color( isset( $input['color_b'] ) ? $input['color_b'] : '', '#8c5ac8' ),
			'marker'         => $marker,
			'marker_color'   => self::hex_color( isset( $input['marker_color'] ) ? $input['marker_color'] : '', '#00a32a' ),
		);
	}

	/**
	 * @param array<string,mixed> $fields Unslashed field values plus effective.
	 * @return array{config:array<string,mixed>,effective:string,errors:string[],invalid:string[]}
	 */
	public static function sanitize_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		$config    = self::normalize_config( $fields );
		$errors    = array();
		$parsed    = self::parse_holidays( isset( $fields['holidays'] ) ? (string) $fields['holidays'] : '' );
		$effective = isset( $fields['effective'] ) ? (string) $fields['effective'] : '';
		if ( ! self::is_ymd( $config['anchor'] ) ) {
			$errors[] = 'anchor';
		}
		if ( 'anchor' === $config['payday_mode'] && ! self::is_ymd( $config['payday_anchor'] ) ) {
			$errors[] = 'payday_anchor';
		}
		if ( ! empty( $parsed['invalid'] ) ) {
			$errors[] = 'holidays';
		}
		if ( ! self::is_ymd( $effective ) ) {
			$errors[] = 'effective';
		}
		return array(
			'config'    => $config,
			'effective' => self::is_ymd( $effective ) ? $effective : '',
			'errors'    => $errors,
			'invalid'   => $parsed['invalid'],
		);
	}

	/**
	 * @param string[] $errors  Error codes from sanitize_fields().
	 * @param string[] $invalid Holiday lines.
	 * @return string
	 */
	public static function save_error_message( $errors, $invalid ) {
		if ( in_array( 'anchor', $errors, true ) ) {
			return __( 'Enter the pay period start date as YYYY-MM-DD.', 'css-timeclock-addon' );
		}
		if ( in_array( 'payday_anchor', $errors, true ) ) {
			return __( 'Enter a known payday as YYYY-MM-DD for the every-14-days rule.', 'css-timeclock-addon' );
		}
		if ( in_array( 'effective', $errors, true ) ) {
			return __( 'Enter the date this schedule starts affecting pay periods, as YYYY-MM-DD.', 'css-timeclock-addon' );
		}
		if ( in_array( 'holidays', $errors, true ) ) {
			return sprintf(
				/* translators: %s: holiday lines that could not be read */
				__( 'Holiday lines must be YYYY-MM-DD or MM-DD. Could not read: %s', 'css-timeclock-addon' ),
				implode( ', ', array_slice( (array) $invalid, 0, 5 ) )
			);
		}
		return __( 'Could not save pay and payday.', 'css-timeclock-addon' );
	}

	/**
	 * @param mixed $raw Stored option or a list of rows.
	 * @return array<int,array{effective:string,saved_at:string,config:array<string,mixed>}>
	 */
	public static function normalize_history( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$by = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['effective'] ) || empty( $row['config'] ) || ! is_array( $row['config'] ) ) {
				continue;
			}
			$effective = (string) $row['effective'];
			if ( ! self::is_ymd( $effective ) ) {
				continue;
			}
			$config = self::normalize_config( $row['config'] );
			if ( ! self::is_ymd( $config['anchor'] ) ) {
				continue;
			}
			$by[ $effective ] = array(
				'effective' => $effective,
				'saved_at'  => isset( $row['saved_at'] ) ? (string) $row['saved_at'] : '',
				'config'    => $config,
			);
		}
		ksort( $by );
		return array_values( $by );
	}

	/**
	 * @param array<int,array<string,mixed>> $history   Existing rows.
	 * @param string                         $effective Y-m-d.
	 * @param array<string,mixed>            $config    Schedule.
	 * @param string                         $saved_at  Timestamp label.
	 * @return array<int,array<string,mixed>>
	 */
	public static function with_snapshot( $history, $effective, $config, $saved_at ) {
		$rows   = self::normalize_history( $history );
		$rows[] = array(
			'effective' => (string) $effective,
			'saved_at'  => (string) $saved_at,
			'config'    => self::normalize_config( $config ),
		);
		return self::normalize_history( $rows );
	}

	/**
	 * Drop the derived holiday set before the option is stored.
	 *
	 * @param array<int,array<string,mixed>> $history Rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function storage_rows( $history ) {
		$out = array();
		foreach ( self::normalize_history( $history ) as $row ) {
			$config = $row['config'];
			unset( $config['holiday_set'] );
			$out[] = array(
				'effective' => $row['effective'],
				'saved_at'  => $row['saved_at'],
				'config'    => $config,
			);
		}
		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function load_history() {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$raw = get_option( self::OPTION, array() );
		return self::normalize_history( $raw );
	}

	/**
	 * Latest saved display settings. Null when nothing has been saved.
	 *
	 * @param array<int,array<string,mixed>> $history Rows.
	 * @return array<string,mixed>|null
	 */
	public static function display_config( $history ) {
		$history = self::normalize_history( $history );
		if ( ! $history ) {
			return null;
		}
		$last = $history[ count( $history ) - 1 ];
		return $last['config'];
	}

	/**
	 * @param array<int,array<string,mixed>> $history Rows.
	 * @param string                         $mode    employee|admin.
	 * @return bool
	 */
	public static function visible( $history, $mode ) {
		$config = self::display_config( $history );
		if ( ! $config ) {
			return false;
		}
		if ( 'admin' === $mode ) {
			return ! empty( $config['show_admin'] );
		}
		return ! empty( $config['show_employee'] );
	}

	/**
	 * @param array<string,mixed> $config Schedule.
	 * @param string              $ymd    Y-m-d.
	 * @return bool
	 */
	public static function is_holiday( $config, $ymd ) {
		$set = ( isset( $config['holiday_set'] ) && is_array( $config['holiday_set'] ) )
			? $config['holiday_set']
			: self::parse_holidays( isset( $config['holidays'] ) ? (string) $config['holidays'] : '' );
		if ( isset( $set['exact'][ $ymd ] ) ) {
			return true;
		}
		$md = substr( (string) $ymd, 5, 5 );
		return isset( $set['annual'][ $md ] );
	}

	/**
	 * @param array<string,mixed> $config Schedule.
	 * @param string              $ymd    Y-m-d.
	 * @return bool
	 */
	public static function is_blocked( $config, $ymd ) {
		$dt = self::day( $ymd );
		if ( ! $dt ) {
			return false;
		}
		$dow = (int) $dt->format( 'w' );
		if ( 0 === $dow || 6 === $dow ) {
			return true;
		}
		return self::is_holiday( $config, $ymd );
	}

	/**
	 * Move one payday off a weekend or holiday. The 14-day series itself stays put.
	 *
	 * @param string              $ymd    Raw payday.
	 * @param array<string,mixed> $config Schedule.
	 * @return string Y-m-d or ''.
	 */
	public static function shift_payday( $ymd, $config ) {
		if ( ! self::is_ymd( $ymd ) ) {
			return '';
		}
		$how = isset( $config['shift'] ) ? (string) $config['shift'] : 'previous';
		if ( 'keep' === $how ) {
			return $ymd;
		}
		$step   = ( 'next' === $how ) ? 1 : -1;
		$cursor = $ymd;
		for ( $i = 0; $i < 21; $i++ ) {
			if ( ! self::is_blocked( $config, $cursor ) ) {
				return $cursor;
			}
			$cursor = self::add_ymd( $cursor, $step );
		}
		return $cursor;
	}

	/**
	 * @param string $anchor   Known payday.
	 * @param string $date     Y-m-d.
	 * @param int    $interval Days. The payday anchor uses 14.
	 * @return bool
	 */
	public static function is_series_payday( $anchor, $date, $interval = self::SERIES_INTERVAL ) {
		$diff = self::diff_days( $anchor, $date );
		$interval = (int) $interval;
		if ( null === $diff || $interval < 1 ) {
			return false;
		}
		return 0 === ( $diff % $interval );
	}

	/**
	 * Earliest series payday on or after $date. Built only from the payday anchor.
	 *
	 * @param string $anchor   Known payday.
	 * @param string $date     Y-m-d.
	 * @param int    $interval Days.
	 * @return string Y-m-d or ''.
	 */
	public static function series_on_or_after( $anchor, $date, $interval = self::SERIES_INTERVAL ) {
		$diff     = self::diff_days( $anchor, $date );
		$interval = (int) $interval;
		if ( null === $diff || $interval < 1 || ! self::is_ymd( $anchor ) ) {
			return '';
		}
		if ( $diff >= 0 ) {
			$k = intdiv( $diff + $interval - 1, $interval );
		} else {
			$k = intdiv( $diff, $interval );
		}
		return self::add_ymd( $anchor, $k * $interval );
	}

	/**
	 * Raw payday before weekend and holiday handling.
	 *
	 * @param array<string,mixed> $config Schedule.
	 * @param string              $end    Period end Y-m-d.
	 * @return string Y-m-d or ''.
	 */
	public static function raw_payday( $config, $end ) {
		$mode = isset( $config['payday_mode'] ) ? (string) $config['payday_mode'] : 'weekday';
		if ( 'on_end' === $mode ) {
			return $end;
		}
		if ( 'days' === $mode ) {
			return self::add_ymd( $end, (int) $config['payday_days'] );
		}
		if ( 'anchor' === $mode ) {
			$day_after = self::add_ymd( $end, 1 );
			return self::series_on_or_after( (string) $config['payday_anchor'], $day_after, self::SERIES_INTERVAL );
		}
		$want   = isset( $config['payday_weekday'] ) ? (int) $config['payday_weekday'] : 5;
		$cursor = self::add_ymd( $end, 1 );
		for ( $i = 0; $i < 7; $i++ ) {
			$dt = self::day( $cursor );
			if ( $dt && (int) $dt->format( 'w' ) === $want ) {
				return $cursor;
			}
			$cursor = self::add_ymd( $cursor, 1 );
		}
		return $cursor;
	}

	/**
	 * @param array<string,mixed> $config One schedule.
	 * @param string              $date   Y-m-d inside the period.
	 * @return array<string,mixed>|null
	 */
	public static function period_for( $config, $date ) {
		$config = self::normalize_config( $config );
		if ( ! self::is_ymd( $config['anchor'] ) || ! self::is_ymd( $date ) ) {
			return null;
		}
		$freq = $config['frequency'];
		if ( 'weekly' === $freq || 'biweekly' === $freq ) {
			$len   = ( 'weekly' === $freq ) ? 7 : 14;
			$diff  = self::diff_days( $config['anchor'], $date );
			$index = (int) floor( $diff / $len );
			$start = self::add_ymd( $config['anchor'], $index * $len );
			$end   = self::add_ymd( $start, $len - 1 );
		} elseif ( 'semimonthly_1_15' === $freq || 'semimonthly_15_eom' === $freq ) {
			$dt    = self::day( $date );
			$y     = (int) $dt->format( 'Y' );
			$m     = (int) $dt->format( 'n' );
			$day   = (int) $dt->format( 'j' );
			$split = ( 'semimonthly_1_15' === $freq ) ? 14 : 15;
			if ( $day <= $split ) {
				$start = self::ymd( $y, $m, 1 );
				$end   = self::ymd( $y, $m, $split );
				$half  = 0;
			} elseif ( 'semimonthly_1_15' === $freq ) {
				$start = self::ymd( $y, $m, 15 );
				$end   = self::ymd( $y, $m, 31 );
				$half  = 1;
			} else {
				$start = self::ymd( $y, $m, 16 );
				$end   = self::ymd( $y, $m, 31 );
				$half  = 1;
			}
			$index = ( ( $y * 12 ) + $m ) * 2 + $half;
		} else {
			$anchor_dom = (int) self::day( $config['anchor'] )->format( 'j' );
			$dt         = self::day( $date );
			$y          = (int) $dt->format( 'Y' );
			$m          = (int) $dt->format( 'n' );
			$start_this = self::ymd( $y, $m, $anchor_dom );
			if ( $date >= $start_this ) {
				$start = $start_this;
				list( $ny, $nm ) = self::shift_month( $y, $m, 1 );
				$next  = self::ymd( $ny, $nm, $anchor_dom );
			} else {
				list( $py, $pm ) = self::shift_month( $y, $m, -1 );
				$start = self::ymd( $py, $pm, $anchor_dom );
				$next  = $start_this;
			}
			$end   = self::add_ymd( $next, -1 );
			$index = ( (int) substr( $start, 0, 4 ) * 12 ) + (int) substr( $start, 5, 2 );
		}

		$length = (int) self::diff_days( $start, $end ) + 1;
		$raw    = self::raw_payday( $config, $end );
		$band   = ( ( $index % 2 ) + 2 ) % 2;
		return array(
			'start'      => $start,
			'end'        => $end,
			'length'     => $length,
			'index'      => $index,
			'band'       => $band,
			'frequency'  => $freq,
			'raw_payday' => $raw,
			'payday'     => self::shift_payday( $raw, $config ),
		);
	}

	/**
	 * Period that contains a date, honoring schedule history.
	 *
	 * @param array<int,array<string,mixed>> $history Rows.
	 * @param string                         $date    Y-m-d.
	 * @return array<string,mixed>|null
	 */
	public static function period_covering( $history, $date ) {
		$history = self::normalize_history( $history );
		if ( ! $history || ! self::is_ymd( $date ) ) {
			return null;
		}
		$period = null;
		foreach ( $history as $i => $row ) {
			if ( 0 === $i ) {
				$period = self::period_for( $row['config'], $date );
				if ( ! $period ) {
					return null;
				}
				$period['effective'] = $row['effective'];
				continue;
			}
			if ( $date < $row['effective'] ) {
				break;
			}
			if ( $period && $period['start'] < $row['effective'] && $date >= $period['start'] && $date <= $period['end'] ) {
				continue;
			}
			$period = self::period_for( $row['config'], $date );
			if ( ! $period ) {
				return null;
			}
			$period['effective'] = $row['effective'];
		}
		return $period;
	}

	/**
	 * The period containing $date, then the following ones.
	 *
	 * @param array<int,array<string,mixed>> $history Rows.
	 * @param string                         $date    Y-m-d.
	 * @param int                            $count   How many.
	 * @return array<int,array<string,mixed>>
	 */
	public static function upcoming( $history, $date, $count ) {
		$count = (int) $count;
		if ( $count < 1 ) {
			$count = 1;
		}
		$first = self::period_covering( $history, $date );
		if ( ! $first ) {
			return array();
		}
		$list   = array( $first );
		$cursor = $first;
		for ( $i = 1; $i < $count; $i++ ) {
			$next_day = self::add_ymd( $cursor['end'], 1 );
			$next     = self::period_covering( $history, $next_day );
			if ( ! $next || $next['start'] === $cursor['start'] ) {
				break;
			}
			$list[] = $next;
			$cursor = $next;
		}
		return $list;
	}

	/**
	 * @param array<string,mixed> $config Schedule.
	 * @param string              $today  Y-m-d.
	 * @return string Y-m-d or ''.
	 */
	public static function next_payday( $config, $today ) {
		$config = self::normalize_config( $config );
		if ( ! self::is_ymd( $today ) ) {
			return '';
		}
		if ( 'anchor' === $config['payday_mode'] ) {
			$raw = self::series_on_or_after( $config['payday_anchor'], $today, self::SERIES_INTERVAL );
			$pay = self::shift_payday( $raw, $config );
			if ( '' !== $pay && $pay >= $today ) {
				return $pay;
			}
			if ( '' === $raw ) {
				return '';
			}
			$raw = self::series_on_or_after( $config['payday_anchor'], self::add_ymd( $raw, 1 ), self::SERIES_INTERVAL );
			return self::shift_payday( $raw, $config );
		}
		$period = self::period_for( $config, $today );
		if ( ! $period ) {
			return '';
		}
		if ( '' !== $period['payday'] && $period['payday'] >= $today ) {
			return $period['payday'];
		}
		$next = self::period_for( $config, self::add_ymd( $period['end'], 1 ) );
		return $next ? (string) $next['payday'] : '';
	}

	/**
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return string
	 */
	public static function format_span( $start, $end ) {
		$left  = self::month_short( (int) substr( $start, 5, 2 ) ) . ' ' . (int) substr( $start, 8, 2 );
		$right = self::month_short( (int) substr( $end, 5, 2 ) ) . ' ' . (int) substr( $end, 8, 2 );
		if ( substr( $start, 0, 4 ) !== substr( $end, 0, 4 ) ) {
			$left  .= ', ' . substr( $start, 0, 4 );
			$right .= ', ' . substr( $end, 0, 4 );
		}
		return $left . ' – ' . $right;
	}

	/**
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return string
	 */
	public static function format_range( $start, $end ) {
		$span = self::format_span( $start, $end );
		if ( substr( $start, 0, 4 ) === substr( $end, 0, 4 ) ) {
			return $span . ', ' . substr( $end, 0, 4 );
		}
		return $span;
	}

	/**
	 * @param string $ymd Y-m-d.
	 * @return string Sun Oct 18, 2026.
	 */
	public static function format_long( $ymd ) {
		$dt = self::day( $ymd );
		if ( ! $dt ) {
			return '';
		}
		return self::weekday_short( (int) $dt->format( 'w' ) ) . ' ' . self::month_short( (int) $dt->format( 'n' ) ) . ' ' . $dt->format( 'j' ) . ', ' . $dt->format( 'Y' );
	}

	/**
	 * @param string $ymd Y-m-d.
	 * @return string Fri, Nov 6, 2026.
	 */
	public static function format_payday( $ymd ) {
		$dt = self::day( $ymd );
		if ( ! $dt ) {
			return '';
		}
		return self::weekday_short( (int) $dt->format( 'w' ) ) . ', ' . self::month_short( (int) $dt->format( 'n' ) ) . ' ' . $dt->format( 'j' ) . ', ' . $dt->format( 'Y' );
	}

	/**
	 * @param string $marker badge|icon|star.
	 * @return string
	 */
	public static function marker_text( $marker ) {
		if ( 'icon' === $marker ) {
			return '💰 ' . __( 'Payday', 'css-timeclock-addon' );
		}
		if ( 'star' === $marker ) {
			return '★ ' . __( 'Payday', 'css-timeclock-addon' );
		}
		return '$ ' . __( 'Payday', 'css-timeclock-addon' );
	}

	/**
	 * @param string $marker badge|icon|star.
	 * @return string
	 */
	public static function marker_glyph( $marker ) {
		if ( 'icon' === $marker ) {
			return '💰';
		}
		if ( 'star' === $marker ) {
			return '★';
		}
		return '$';
	}

	/**
	 * @param array<string,mixed> $config Schedule.
	 * @param string              $sample Y-m-d inside a period, usually the anchor.
	 * @return string
	 */
	public static function length_blurb( $config, $sample ) {
		$config = self::normalize_config( $config );
		if ( ! self::is_ymd( $sample ) ) {
			$sample = $config['anchor'];
		}
		$period = self::period_for( $config, $sample );
		if ( ! $period ) {
			return '';
		}
		return sprintf(
			/* translators: 1: number of days, 2: weekday the period starts, 3: weekday it ends */
			__( '%1$d days (auto from frequency) — %2$s through %3$s', 'css-timeclock-addon' ),
			(int) $period['length'],
			self::weekday_name( (int) self::day( $period['start'] )->format( 'w' ) ),
			self::weekday_name( (int) self::day( $period['end'] )->format( 'w' ) )
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $history Rows.
	 * @param string                         $today   Y-m-d.
	 * @return array<string,string>|null
	 */
	public static function summary( $history, $today ) {
		$period = self::period_covering( $history, $today );
		if ( ! $period ) {
			return null;
		}
		$history = self::normalize_history( $history );
		$config  = null;
		foreach ( $history as $row ) {
			if ( $row['effective'] === $period['effective'] ) {
				$config = $row['config'];
			}
		}
		if ( ! $config ) {
			$config = self::display_config( $history );
		}
		$payday = $config ? self::next_payday( $config, $today ) : '';
		if ( '' === $payday ) {
			$payday = (string) $period['payday'];
		}
		return array(
			'start'        => (string) $period['start'],
			'end'          => (string) $period['end'],
			'payday'       => $payday,
			'range'        => self::format_range( $period['start'], $period['end'] ),
			'payday_label' => self::format_payday( $payday ),
			'closes_label' => self::format_payday( $period['end'] ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $history Rows.
	 * @param string                         $today   Y-m-d.
	 * @return string
	 */
	public static function widget_line( $history, $today ) {
		$sum = self::summary( $history, $today );
		if ( ! $sum || '' === $sum['payday_label'] ) {
			return '';
		}
		return sprintf(
			/* translators: %s: next payday, with weekday */
			__( 'Next payday: %s', 'css-timeclock-addon' ),
			$sum['payday_label']
		);
	}

	/**
	 * @param int $seconds Worked seconds.
	 * @return string
	 */
	public static function hours_label( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds <= 0 ) {
			return '';
		}
		return number_format( $seconds / 3600, 1, '.', '' ) . ' h';
	}

	/**
	 * Visible grid for one month.
	 *
	 * @param array<int,array<string,mixed>> $history Rows.
	 * @param int                            $year    Year.
	 * @param int                            $month   Month.
	 * @return array{start:string,end:string,week_start:int}|null
	 */
	public static function grid_bounds( $history, $year, $month ) {
		$display = self::display_config( $history );
		if ( ! $display ) {
			return null;
		}
		$first = self::ymd( (int) $year, (int) $month, 1 );
		if ( '' === $first ) {
			return null;
		}
		$dim   = (int) self::day( $first )->format( 't' );
		$last  = self::ymd( (int) $year, (int) $month, $dim );
		$lead  = ( (int) self::day( $first )->format( 'w' ) - (int) $display['week_start'] + 7 ) % 7;
		$tail  = ( (int) $display['week_start'] + 6 - (int) self::day( $last )->format( 'w' ) + 7 ) % 7;
		$start = self::add_ymd( $first, -$lead );
		$end   = self::add_ymd( $last, $tail );
		return array(
			'start'      => $start,
			'end'        => $end,
			'week_start' => (int) $display['week_start'],
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $history Rows.
	 * @param int                            $year    Year.
	 * @param int                            $month   Month.
	 * @param string                         $today   Y-m-d.
	 * @param bool                           $is_admin Manager view.
	 * @param array<string,string>          $hours   Date => hours label.
	 * @param array<string,string>          $office  Date => holiday name.
	 * @return array<string,mixed>|null
	 */
	public static function month_view( $history, $year, $month, $today, $is_admin = false, $hours = array(), $office = array() ) {
		$history = self::normalize_history( $history );
		$bounds  = self::grid_bounds( $history, $year, $month );
		$display = self::display_config( $history );
		if ( ! $bounds || ! $display ) {
			return null;
		}
		$paydays = array();
		$scan    = self::add_ymd( $bounds['start'], -100 );
		$guard   = 0;
		while ( '' !== $scan && $scan <= $bounds['end'] && $guard < 24 ) {
			$found = self::period_covering( $history, $scan );
			if ( ! $found ) {
				break;
			}
			if ( '' !== $found['payday'] ) {
				$paydays[ $found['payday'] ] = true;
			}
			$next = self::add_ymd( $found['end'], 1 );
			if ( '' === $next || $next <= $scan ) {
				break;
			}
			$scan = $next;
			++$guard;
		}

		$cursor = $bounds['start'];
		$weeks  = array();
		$week   = array();
		$seen_h = false;
		while ( $cursor <= $bounds['end'] ) {
			$period = self::period_covering( $history, $cursor );
			$dt     = self::day( $cursor );
			$dow    = $dt ? (int) $dt->format( 'w' ) : 0;
			$label  = '';
			$band   = 0;
			$color  = $display['color_a'];
			if ( $period ) {
				$band  = (int) $period['band'];
				$color = ( 0 === $band ) ? $display['color_a'] : $display['color_b'];
				if ( $cursor === $period['start'] || $dow === (int) $display['week_start'] ) {
					$label = sprintf(
						/* translators: %s: short start and end, for example Nov 1 – Nov 14 */
						__( 'Pay period %s', 'css-timeclock-addon' ),
						self::format_span( $period['start'], $period['end'] )
					);
				}
			}
			$holiday = isset( $office[ $cursor ] ) ? (string) $office[ $cursor ] : '';
			if ( '' !== $holiday ) {
				$seen_h = true;
			}
			$week[] = array(
				'date'     => $cursor,
				'day'      => $dt ? $dt->format( 'j' ) : '',
				'in_month' => ( (int) substr( $cursor, 5, 2 ) === (int) $month ),
				'band'     => $band,
				'color'    => $color,
				'tint'     => $period ? self::tint( $color, 0.88 ) : '#ffffff',
				'label'    => $label,
				'bar'      => (bool) $period,
				'payday'   => isset( $paydays[ $cursor ] ),
				'closes'   => ( $period && $cursor === $period['end'] ),
				'hours'    => isset( $hours[ $cursor ] ) ? (string) $hours[ $cursor ] : '',
				'holiday'  => $holiday,
			);
			if ( count( $week ) === 7 ) {
				$weeks[] = $week;
				$week    = array();
			}
			$cursor = self::add_ymd( $cursor, 1 );
		}

		$heads = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$heads[] = self::weekday_short( ( (int) $display['week_start'] + $i ) % 7 );
		}

		return array(
			'title'        => self::month_name( (int) $month ) . ' ' . (int) $year,
			'prev'         => substr( self::add_ymd( self::ymd( (int) $year, (int) $month, 1 ), -1 ), 0, 7 ),
			'next'         => substr( self::ymd( (int) $year, (int) $month + 1, 1 ), 0, 7 ),
			'weekdays'     => $heads,
			'weeks'        => $weeks,
			'grid_start'   => $bounds['start'],
			'grid_end'     => $bounds['end'],
			'summary'      => self::summary( $history, $today ),
			'show_closes'  => $is_admin && ! empty( $display['show_closes'] ),
			'marker_text'  => self::marker_text( $display['marker'] ),
			'marker_glyph' => self::marker_glyph( $display['marker'] ),
			'marker_color' => $display['marker_color'],
			'color_a'      => $display['color_a'],
			'color_b'      => $display['color_b'],
			'tint_a'       => self::tint( $display['color_a'], 0.88 ),
			'tint_b'       => self::tint( $display['color_b'], 0.88 ),
			'has_holiday'  => $seen_h,
		);
	}

	/**
	 * Hours already calculated by the Monday timecard engine, for calendar labels only.
	 *
	 * @param int    $user_id Employee.
	 * @param string $start   Y-m-d.
	 * @param string $end     Y-m-d.
	 * @return array<string,string>
	 */
	public static function hours_map( $user_id, $start, $end ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! function_exists( 'css_tc_addon' ) || ! self::is_ymd( $start ) || ! self::is_ymd( $end ) ) {
			return array();
		}
		$plugin = css_tc_addon();
		if ( ! isset( $plugin->pay_periods, $plugin->timecard ) ) {
			return array();
		}
		$map    = array();
		$cursor = $start;
		$seen   = array();
		$guard  = 0;
		while ( $cursor <= $end && $guard < 8 ) {
			$period = $plugin->pay_periods->period_for_date( $cursor );
			if ( ! is_array( $period ) || empty( $period['start'] ) || isset( $seen[ $period['start'] ] ) ) {
				break;
			}
			$seen[ $period['start'] ] = true;
			$sheet = $plugin->timecard->build( $user_id, $period );
			if ( ! empty( $sheet['weeks'] ) && is_array( $sheet['weeks'] ) ) {
				foreach ( $sheet['weeks'] as $week ) {
					if ( empty( $week['days'] ) || ! is_array( $week['days'] ) ) {
						continue;
					}
					foreach ( $week['days'] as $day ) {
						$date    = isset( $day['date'] ) ? (string) $day['date'] : '';
						$seconds = isset( $day['seconds'] ) ? (int) $day['seconds'] : 0;
						if ( '' === $date || $seconds <= 0 || $date < $start || $date > $end ) {
							continue;
						}
						$map[ $date ] = self::hours_label( $seconds );
					}
				}
			}
			$next = ! empty( $period['end'] ) ? self::add_ymd( (string) $period['end'], 1 ) : '';
			if ( '' === $next || $next <= $cursor ) {
				break;
			}
			$cursor = $next;
			++$guard;
		}
		return $map;
	}

	/**
	 * Office-closed labels from the Holidays tab. These do not move payday.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array<string,string>
	 */
	public static function office_closed( $start, $end ) {
		if ( ! function_exists( 'css_tc_addon' ) ) {
			return array();
		}
		$holidays = css_tc_addon()->holidays;
		if ( ! is_object( $holidays ) || ! method_exists( $holidays, 'between' ) ) {
			return array();
		}
		$rows = $holidays->between( $start, $end );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $date => $names ) {
			if ( ! is_array( $names ) || ! $names ) {
				continue;
			}
			$out[ (string) $date ] = implode( ', ', array_map( 'strval', $names ) );
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $history  Rows.
	 * @param int                            $year     Year.
	 * @param int                            $month    Month.
	 * @param string                         $today    Y-m-d.
	 * @param int                            $user_id  Employee whose hours to show.
	 * @param bool                           $is_admin Manager view.
	 * @return array<string,mixed>|null
	 */
	public static function present_month( $history, $year, $month, $today, $user_id, $is_admin ) {
		$bounds = self::grid_bounds( $history, $year, $month );
		if ( ! $bounds ) {
			return null;
		}
		$hours  = self::hours_map( (int) $user_id, $bounds['start'], $bounds['end'] );
		$office = self::office_closed( $bounds['start'], $bounds['end'] );
		return self::month_view( $history, $year, $month, $today, $is_admin, $hours, $office );
	}
}
