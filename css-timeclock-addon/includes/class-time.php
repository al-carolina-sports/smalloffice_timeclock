<?php
/**
 * Timezone-safe reading and writing of AIO shift datetimes.
 *
 * What AIO Time Clock Lite 2.1.0 actually does (aio-time-clock-lite-actions.php):
 * - getCurrentTime() stores employee_clock_in_time / employee_clock_out_time with
 *   wp_date( 'Y-m-d H:i:s' ). That is a naive wall clock in wp_timezone(), with
 *   no offset stored on the post meta.
 * - cleanDate() / the shift list / monitoring display with
 *   date( $format, strtotime( $stored ) ). WordPress sets PHP's default
 *   timezone to UTC, so strtotime() and date() both treat the naive string as
 *   UTC and the digits are shown unchanged.
 *
 * This site's WordPress timezone has been UTC, so every punch written so far
 * (by AIO or by this addon, which also used wp_date()) is the UTC wall clock
 * of the absolute instant. A 5:05 PM America/New_York punch is stored as
 * 21:05:00. Showing those digits in UTC is why the timecard read 9:05 PM.
 *
 * Contract this addon keeps:
 * - Meta format stays Y-m-d H:i:s. Nothing rewrites existing rows.
 * - Those strings are UTC instants. New punches are written with gmdate /
 *   DateTimeImmutable in UTC, which matches today's wp_date() output while
 *   the site timezone is UTC. After an admin switches the site to
 *   America/New_York, the stored digits still mean the same absolute instant
 *   and displays move to Eastern time. We do not switch storage to wp_date()
 *   at that point: wp_date() would start writing Eastern wall clocks and the
 *   old UTC rows would be misread.
 * - Display, calendar days, and correction form parsing use wp_timezone().
 *   Form values are site-local and are converted back to UTC before save.
 *
 * AIO's own clock AJAX (aio_time_clock_lite_js clock_in / clock_out) still
 * calls getCurrentTime(), which is site-local once the timezone leaves UTC.
 * Css_Tc_Punches rewrites those two writes to UTC before they are stored.
 * The front-end page that embeds [show_aio_time_clock_lite], including
 * /time-clock/, redirects to an USOTC kiosk. Kiosk punches and approved
 * corrections are written in UTC directly and are not converted again.
 * Punches AIO already saved as Eastern wall-clock strings are not rewritten.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse, format, and convert shift datetimes.
 */
class Css_Tc_Time {

	/**
	 * Site timezone. Falls back for WordPress older than 5.3.
	 *
	 * @return DateTimeZone
	 */
	public function timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$named = get_option( 'timezone_string' );
		if ( is_string( $named ) && '' !== $named ) {
			try {
				return new DateTimeZone( $named );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				unset( $e );
			}
		}

		$offset = (float) get_option( 'gmt_offset', 0 );
		$sign   = $offset >= 0 ? '+' : '-';
		$hours  = (int) abs( $offset );
		$mins   = (int) round( ( abs( $offset ) - $hours ) * 60 );

		return new DateTimeZone( sprintf( '%s%02d:%02d', $sign, $hours, $mins ) );
	}

	/**
	 * @return DateTimeZone
	 */
	public function utc_zone() {
		return new DateTimeZone( 'UTC' );
	}

	/**
	 * Parse a stored Y-m-d H:i:s value as a UTC instant.
	 *
	 * @param string $mysql Stored datetime.
	 * @return DateTimeImmutable|null
	 */
	public function parse_stored( $mysql ) {
		$mysql = trim( (string) $mysql );
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/', $mysql, $m ) ) {
			return null;
		}

		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $m[1] . ' ' . $m[2], $this->utc_zone() );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $dt || ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) ) {
			return null;
		}

		return $dt;
	}

	/**
	 * Read a naive Y-m-d H:i:s as a site-local wall clock and return UTC storage.
	 *
	 * AIO's clock AJAX stores wp_date(), which is Eastern after the site
	 * timezone leaves UTC. Empty and non-datetime values are left alone.
	 *
	 * @param mixed $mysql Candidate meta value.
	 * @return string|null UTC Y-m-d H:i:s, or null when $mysql is not a datetime.
	 */
	public function site_naive_to_utc( $mysql ) {
		$mysql = trim( (string) $mysql );
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/', $mysql, $m ) ) {
			return null;
		}

		$dt     = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $m[1] . ' ' . $m[2], $this->timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $dt || ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) ) {
			return null;
		}

		return $this->format_stored( $dt );
	}

	/**
	 * UTC storage string for an instant.
	 *
	 * @param DateTimeInterface $instant Absolute time.
	 * @return string
	 */
	public function format_stored( DateTimeInterface $instant ) {
		if ( $instant instanceof DateTimeImmutable ) {
			$utc = $instant->setTimezone( $this->utc_zone() );
		} else {
			$utc = DateTimeImmutable::createFromMutable( $instant )->setTimezone( $this->utc_zone() );
		}
		return $utc->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Current instant as a UTC storage string.
	 *
	 * Identical to wp_date( 'Y-m-d H:i:s' ) while the site timezone is UTC.
	 *
	 * @return string
	 */
	public function now_stored() {
		$now = new DateTimeImmutable( 'now', $this->utc_zone() );
		return $now->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Format a stored UTC instant in the site timezone.
	 *
	 * @param string $mysql  Stored datetime.
	 * @param string $format PHP date format.
	 * @return string
	 */
	public function format_site( $mysql, $format ) {
		$dt = $this->parse_stored( $mysql );
		if ( ! $dt ) {
			return '';
		}

		$local = $dt->setTimezone( $this->timezone() );
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( $format, $local->getTimestamp() );
		}

		return $local->format( $format );
	}

	/**
	 * Calendar day (Y-m-d) of a stored instant in the site timezone.
	 *
	 * @param string $mysql Stored datetime.
	 * @return string
	 */
	public function site_date_of( $mysql ) {
		$dt = $this->parse_stored( $mysql );
		if ( ! $dt ) {
			return '';
		}
		return $dt->setTimezone( $this->timezone() )->format( 'Y-m-d' );
	}

	/**
	 * Site-local H:i:s of a stored instant.
	 *
	 * @param string $mysql Stored datetime.
	 * @return string
	 */
	public function site_hms( $mysql ) {
		$dt = $this->parse_stored( $mysql );
		if ( ! $dt ) {
			return '';
		}
		return $dt->setTimezone( $this->timezone() )->format( 'H:i:s' );
	}

	/**
	 * Site-local H:i of a stored instant.
	 *
	 * @param string $mysql Stored datetime.
	 * @return string
	 */
	public function site_hm( $mysql ) {
		$hms = $this->site_hms( $mysql );
		return '' === $hms ? '' : substr( $hms, 0, 5 );
	}

	/**
	 * @return string Today's Y-m-d in the site timezone.
	 */
	public function site_today() {
		$now = new DateTimeImmutable( 'now', $this->timezone() );
		return $now->format( 'Y-m-d' );
	}

	/**
	 * Add calendar days to a Y-m-d in the site timezone (DST-safe).
	 *
	 * @param string $date        Y-m-d.
	 * @param int    $offset_days Days to add.
	 * @return string
	 */
	public function shift_date( $date, $offset_days ) {
		$day = $this->date_immutable( $date );
		if ( ! $day ) {
			$day = new DateTimeImmutable( 'today', $this->timezone() );
		}
		if ( 0 === (int) $offset_days ) {
			return $day->format( 'Y-m-d' );
		}
		return $day->modify( ( (int) $offset_days >= 0 ? '+' : '' ) . (int) $offset_days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * @param string $date Y-m-d.
	 * @return DateTimeImmutable|null Midnight site-local.
	 */
	public function date_immutable( $date ) {
		$date = trim( (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $this->timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $dt || ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) ) {
			return null;
		}
		return $dt;
	}

	/**
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public function format_day_label( $date ) {
		$day = $this->date_immutable( $date );
		if ( ! $day ) {
			return (string) $date;
		}
		$format = get_option( 'date_format', 'Y-m-d' );
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( $format, $day->getTimestamp() );
		}
		return $day->format( $format );
	}

	/**
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public function format_weekday( $date ) {
		return $this->format_weekday_token( $date, 'l' );
	}

	/**
	 * Short weekday (Mon, Tue) for narrow timecard columns.
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public function format_weekday_short( $date ) {
		return $this->format_weekday_token( $date, 'D' );
	}

	/**
	 * @param string $date   Y-m-d.
	 * @param string $format PHP date token.
	 * @return string
	 */
	private function format_weekday_token( $date, $format ) {
		$day = $this->date_immutable( $date );
		if ( ! $day ) {
			return '';
		}
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( $format, $day->getTimestamp() );
		}
		return $day->format( $format );
	}

	/**
	 * m/d/Y label used on the timecard (ADP-style ranges).
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public function format_mdy( $date ) {
		$day = $this->date_immutable( $date );
		if ( ! $day ) {
			return (string) $date;
		}
		return $day->format( 'm/d/Y' );
	}

	/**
	 * Clock face in the site timezone, e.g. 08:40 AM.
	 *
	 * @param string $mysql Stored datetime.
	 * @return string
	 */
	public function format_clock( $mysql ) {
		$dt = $this->parse_stored( $mysql );
		if ( ! $dt ) {
			return '';
		}
		return $dt->setTimezone( $this->timezone() )->format( 'h:i A' );
	}

	/**
	 * Combine a site-local work date and time into a UTC storage string.
	 *
	 * Accepts H:i or H:i:s. When seconds are omitted and $original_stored is
	 * the same hour and minute in the site timezone, those seconds are kept.
	 *
	 * @param string $date            Y-m-d site calendar day.
	 * @param string $hm              H:i or H:i:s.
	 * @param bool   $next_day        Add one calendar day before converting.
	 * @param string $original_stored Existing UTC meta, used only to keep seconds.
	 * @return string Empty when the time is blank or invalid.
	 */
	public function combine_day_time( $date, $hm, $next_day = false, $original_stored = '' ) {
		$parts = $this->normalize_hms( $hm );
		if ( ! $parts || ! $this->date_immutable( $date ) ) {
			return '';
		}

		if ( $parts['seconds_omitted'] && '' !== $original_stored ) {
			$orig = $this->site_hms( $original_stored );
			if ( '' !== $orig && substr( $orig, 0, 5 ) === $parts['hm'] ) {
				$parts['s'] = (int) substr( $orig, 6, 2 );
			}
		}

		$day = $this->date_immutable( $date );
		if ( $next_day ) {
			$day = $day->modify( '+1 day' );
		}

		$local = $day->setTime( $parts['h'], $parts['m'], $parts['s'] );
		return $this->format_stored( $local );
	}

	/**
	 * @param string $hm Raw time.
	 * @return array{h:int,m:int,s:int,hm:string,seconds_omitted:bool}|null
	 */
	public function normalize_hms( $hm ) {
		$hm = trim( (string) $hm );
		if ( preg_match( '/^(\d{1,2}):(\d{2}):(\d{2})$/', $hm, $m ) ) {
			$hour = (int) $m[1];
			$min  = (int) $m[2];
			$sec  = (int) $m[3];
			if ( $hour >= 0 && $hour <= 23 && $min >= 0 && $min <= 59 && $sec >= 0 && $sec <= 59 ) {
				return array(
					'h'               => $hour,
					'm'               => $min,
					's'               => $sec,
					'hm'              => sprintf( '%02d:%02d', $hour, $min ),
					'seconds_omitted' => false,
				);
			}
			return null;
		}

		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $hm, $m ) ) {
			$hour = (int) $m[1];
			$min  = (int) $m[2];
			if ( $hour >= 0 && $hour <= 23 && $min >= 0 && $min <= 59 ) {
				return array(
					'h'               => $hour,
					'm'               => $min,
					's'               => 0,
					'hm'              => sprintf( '%02d:%02d', $hour, $min ),
					'seconds_omitted' => true,
				);
			}
		}

		return null;
	}

	/**
	 * @param string $hm Raw hour:minute.
	 * @return string H:i or empty.
	 */
	public function normalize_hour_minute( $hm ) {
		$parts = $this->normalize_hms( $hm );
		return $parts ? $parts['hm'] : '';
	}

	/**
	 * Elapsed whole seconds between two stored instants.
	 *
	 * @param string $start Stored start.
	 * @param string $end   Stored end.
	 * @return int Negative when the end is missing or before the start.
	 */
	public function elapsed_seconds( $start, $end ) {
		$start_dt = $this->parse_stored( $start );
		$end_dt   = $this->parse_stored( $end );
		if ( ! $start_dt || ! $end_dt ) {
			return -1;
		}
		return $end_dt->getTimestamp() - $start_dt->getTimestamp();
	}

	/**
	 * Hours:minutes for a second count. Hours are not zero-padded (8:05, 54:35).
	 *
	 * @param int $seconds Duration.
	 * @return string
	 */
	public function format_duration( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds < 0 ) {
			$seconds = 0;
		}
		// A finished shift of a few seconds is real time. Rounding it to 0:00
		// hides it. Anything under 30 seconds is shown as under a minute.
		// Those seconds still add into the longer totals.
		if ( $seconds > 0 && $seconds < 30 ) {
			return '<1 min';
		}
		$rounded = (int) round( $seconds / 60 );
		$hours   = (int) floor( $rounded / 60 );
		$minutes = $rounded % 60;
		return $hours . ':' . str_pad( (string) $minutes, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * @param string $start Stored start.
	 * @param string $end   Stored end.
	 * @return string
	 */
	public function elapsed_label( $start, $end ) {
		$seconds = $this->elapsed_seconds( $start, $end );
		if ( $seconds < 0 ) {
			return '0:00';
		}
		return $this->format_duration( $seconds );
	}

	/**
	 * Clock time (H:MM or H:MM:SS) to seconds from midnight.
	 *
	 * @param string $hms Clock time.
	 * @return int|null Null when the value is empty or not a clock time.
	 */
	public function hms_to_seconds( $hms ) {
		$hms = trim( (string) $hms );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $hms, $match ) ) {
			return null;
		}
		$hours   = (int) $match[1];
		$minutes = (int) $match[2];
		$seconds = isset( $match[3] ) ? (int) $match[3] : 0;
		if ( $hours > 23 || $minutes > 59 || $seconds > 59 ) {
			return null;
		}
		return ( $hours * HOUR_IN_SECONDS ) + ( $minutes * MINUTE_IN_SECONDS ) + $seconds;
	}

	/**
	 * Seconds between two clock times. The next-day flag adds 24 hours.
	 *
	 * @param string $in_hms   Clock-in.
	 * @param string $out_hms  Clock-out.
	 * @param bool   $next_day Clock-out is the following day.
	 * @return int Negative when a clock time is missing or the span is backwards.
	 */
	public function hms_span_seconds( $in_hms, $out_hms, $next_day = false ) {
		$in  = $this->hms_to_seconds( $in_hms );
		$out = $this->hms_to_seconds( $out_hms );
		if ( null === $in || null === $out ) {
			return -1;
		}
		$diff = $out - $in;
		if ( $next_day ) {
			$diff += DAY_IN_SECONDS;
		}
		if ( $diff < 0 ) {
			return -1;
		}
		return $diff;
	}

	/**
	 * Why a punch row cannot be saved, or an empty problem when it can.
	 *
	 * A blank new row and an existing shift with no clock-out are valid.
	 * Clock-out earlier than clock-in is valid only when the next-day box is checked.
	 *
	 * @param string $in_hms    Clock-in.
	 * @param string $out_hms   Clock-out.
	 * @param bool   $next_day  Clock-out is the following day.
	 * @param bool   $has_shift Row belongs to a stored shift.
	 * @return array{message:string,short:string,seconds:int}
	 */
	public function line_clock_problem( $in_hms, $out_hms, $next_day = false, $has_shift = false ) {
		$in_hms  = trim( (string) $in_hms );
		$out_hms = trim( (string) $out_hms );
		$none    = array(
			'message' => '',
			'short'   => '',
			'seconds' => -1,
		);

		if ( '' === $in_hms && '' === $out_hms && ! $has_shift ) {
			return $none;
		}

		if ( ( '' !== $in_hms && null === $this->hms_to_seconds( $in_hms ) ) || ( '' !== $out_hms && null === $this->hms_to_seconds( $out_hms ) ) ) {
			return array(
				'message' => __( 'Enter a valid clock time.', 'css-timeclock-addon' ),
				'short'   => '',
				'seconds' => -1,
			);
		}

		if ( '' === $in_hms ) {
			return array(
				'message' => __( 'Enter a clock-in time.', 'css-timeclock-addon' ),
				'short'   => '',
				'seconds' => -1,
			);
		}

		if ( '' === $out_hms ) {
			return $none;
		}

		$seconds = $this->hms_span_seconds( $in_hms, $out_hms, $next_day );
		if ( $seconds < 0 ) {
			return array(
				'message' => __( 'Clock-out is earlier than clock-in. Fix the time, or check "Clock-out is the next day" if the shift ended after midnight.', 'css-timeclock-addon' ),
				'short'   => __( 'Clock-out is earlier than clock-in', 'css-timeclock-addon' ),
				'seconds' => -1,
			);
		}

		return array(
			'message' => '',
			'short'   => '',
			'seconds' => $seconds,
		);
	}

	/**
	 * Zero-padded hours and minutes (08:30, 00:00). Missing time is --:--.
	 *
	 * Rounding matches format_duration(): nearest minute. A span under 30
	 * seconds rounds to 00:00.
	 *
	 * @param int $seconds Duration, or negative when the shift is incomplete.
	 * @return string
	 */
	public function format_hours_hm( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds < 0 ) {
			return '--:--';
		}
		$rounded = (int) round( $seconds / 60 );
		$hours   = (int) floor( $rounded / 60 );
		$minutes = $rounded % 60;
		return str_pad( (string) $hours, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( (string) $minutes, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Whether an open shift's clock-in is older than the configured maximum.
	 *
	 * @param string $clock_in Stored clock-in.
	 * @param int    $max_hours Maximum age still treated as clocked in.
	 * @return bool
	 */
	public function is_stale_open( $clock_in, $max_hours ) {
		$dt = $this->parse_stored( $clock_in );
		if ( ! $dt ) {
			return true;
		}
		$max_hours = (int) $max_hours;
		if ( $max_hours < 1 ) {
			$max_hours = 16;
		}
		$age = time() - $dt->getTimestamp();
		return $age > ( $max_hours * HOUR_IN_SECONDS );
	}
}
