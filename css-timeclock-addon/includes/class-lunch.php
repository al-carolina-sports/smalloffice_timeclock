<?php
/**
 * Missed-lunch alerts.
 *
 * A clock-out checks that work day. The day is flagged when the hours already
 * on the timecard are more than the threshold and no meal was recorded.
 * All in One Time Clock Lite stores a meal as a clock-out and a later clock-in.
 * A gap of at least the configured minutes counts. A break length stored on
 * the shift counts the same way. This does not change hours, overtime, or pay.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lunch threshold, timecard flag, and the one email per employee per day.
 */
class Css_Tc_Lunch {

	const LOG_META = 'css_tc_lunch_alerts';

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'lunch_alert_enabled'        => 0,
			'lunch_alert_hours'          => 4,
			'lunch_alert_break_minutes'  => 30,
			'lunch_alert_email_employee' => 1,
			'lunch_alert_email_admins'   => 1,
			'lunch_alert_extra'          => '',
			'lunch_alert_subject'        => 'No lunch recorded: {name} on {date}',
			'lunch_alert_body'           => "{name} worked {hours} on {date}. No lunch was recorded.",
		);
	}

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'css_tc_after_clock_out', array( $this, 'on_clock_out' ), 10, 3 );
	}

	/**
	 * @return bool
	 */
	public function enabled() {
		$rules = self::rules_from( $this->settings() );
		return ! empty( $rules['enabled'] );
	}

	/**
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array<string,mixed>
	 */
	public static function rules_from( $settings ) {
		$base = self::defaults();
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$hours = isset( $settings['lunch_alert_hours'] ) ? (float) $settings['lunch_alert_hours'] : (float) $base['lunch_alert_hours'];
		if ( $hours < 1 ) {
			$hours = 1;
		}
		if ( $hours > 16 ) {
			$hours = 16;
		}
		$minutes = isset( $settings['lunch_alert_break_minutes'] ) ? (int) $settings['lunch_alert_break_minutes'] : (int) $base['lunch_alert_break_minutes'];
		if ( $minutes < 1 ) {
			$minutes = 1;
		}
		if ( $minutes > 240 ) {
			$minutes = 240;
		}
		$subject = isset( $settings['lunch_alert_subject'] ) ? trim( (string) $settings['lunch_alert_subject'] ) : '';
		$body    = isset( $settings['lunch_alert_body'] ) ? trim( (string) $settings['lunch_alert_body'] ) : '';
		if ( '' === $subject ) {
			$subject = (string) $base['lunch_alert_subject'];
		}
		if ( '' === $body ) {
			$body = (string) $base['lunch_alert_body'];
		}
		return array(
			'enabled'         => empty( $settings['lunch_alert_enabled'] ) ? 0 : 1,
			'hours'           => $hours,
			'break_seconds'   => $minutes * 60,
			'break_minutes'   => $minutes,
			'email_employee'  => empty( $settings['lunch_alert_email_employee'] ) ? 0 : 1,
			'email_admins'    => empty( $settings['lunch_alert_email_admins'] ) ? 0 : 1,
			'extra'           => isset( $settings['lunch_alert_extra'] ) ? (string) $settings['lunch_alert_extra'] : '',
			'subject'         => $subject,
			'body'            => $body,
		);
	}

	/**
	 * @param array<string,mixed> $input Raw field values.
	 * @return array{settings:array<string,mixed>,invalid:string[]}
	 */
	public static function sanitize_input( $input ) {
		$base = self::defaults();
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$parsed = self::parse_extra( isset( $input['lunch_alert_extra'] ) ? (string) $input['lunch_alert_extra'] : '' );
		$rules  = self::rules_from(
			array(
				'lunch_alert_enabled'        => empty( $input['lunch_alert_enabled'] ) ? 0 : 1,
				'lunch_alert_hours'          => isset( $input['lunch_alert_hours'] ) ? $input['lunch_alert_hours'] : $base['lunch_alert_hours'],
				'lunch_alert_break_minutes'  => isset( $input['lunch_alert_break_minutes'] ) ? $input['lunch_alert_break_minutes'] : $base['lunch_alert_break_minutes'],
				'lunch_alert_email_employee' => empty( $input['lunch_alert_email_employee'] ) ? 0 : 1,
				'lunch_alert_email_admins'   => empty( $input['lunch_alert_email_admins'] ) ? 0 : 1,
				'lunch_alert_extra'          => implode( "\n", $parsed['emails'] ),
				'lunch_alert_subject'        => isset( $input['lunch_alert_subject'] ) ? self::plain( $input['lunch_alert_subject'], 180 ) : '',
				'lunch_alert_body'           => isset( $input['lunch_alert_body'] ) ? self::plain( $input['lunch_alert_body'], 2000 ) : '',
			)
		);
		return array(
			'settings' => array(
				'lunch_alert_enabled'        => $rules['enabled'],
				'lunch_alert_hours'          => $rules['hours'],
				'lunch_alert_break_minutes'  => $rules['break_minutes'],
				'lunch_alert_email_employee' => $rules['email_employee'],
				'lunch_alert_email_admins'   => $rules['email_admins'],
				'lunch_alert_extra'          => $rules['extra'],
				'lunch_alert_subject'        => $rules['subject'],
				'lunch_alert_body'           => $rules['body'],
			),
			'invalid'  => $parsed['invalid'],
		);
	}

	/**
	 * @param string $text  Address list.
	 * @return array{emails:string[],invalid:string[]}
	 */
	public static function parse_extra( $text ) {
		$emails  = array();
		$invalid = array();
		$seen    = array();
		$parts   = preg_split( '/[\s,;]+/', str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
		if ( ! is_array( $parts ) ) {
			return array(
				'emails'  => array(),
				'invalid' => array(),
			);
		}
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}
			if ( ! self::is_email_address( $part ) ) {
				$invalid[] = $part;
				continue;
			}
			$key = strtolower( $part );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$emails[]     = $part;
			if ( count( $emails ) >= 20 ) {
				break;
			}
		}
		return array(
			'emails'  => $emails,
			'invalid' => $invalid,
		);
	}

	/**
	 * @param string $email Address.
	 * @return bool
	 */
	public static function is_email_address( $email ) {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * @param array<string,mixed> $rules          Rules from rules_from().
	 * @param string              $employee_email Employee address.
	 * @param string[]            $admin_emails   Time Clock Admin addresses.
	 * @return string[]
	 */
	public static function recipients( $rules, $employee_email, $admin_emails ) {
		$list = array();
		$seen = array();
		$add  = static function ( $email ) use ( &$list, &$seen ) {
			$email = trim( (string) $email );
			if ( ! Css_Tc_Lunch::is_email_address( $email ) ) {
				return;
			}
			$key = strtolower( $email );
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$list[]       = $email;
		};
		if ( ! empty( $rules['email_employee'] ) ) {
			$add( $employee_email );
		}
		if ( ! empty( $rules['email_admins'] ) && is_array( $admin_emails ) ) {
			foreach ( $admin_emails as $email ) {
				$add( $email );
			}
		}
		$extra = self::parse_extra( isset( $rules['extra'] ) ? (string) $rules['extra'] : '' );
		foreach ( $extra['emails'] as $email ) {
			$add( $email );
		}
		return $list;
	}

	/**
	 * @param string $template Subject or body.
	 * @param string $name     Employee name.
	 * @param string $date     Work day.
	 * @param string $hours    Hours already shown on the timecard.
	 * @return string
	 */
	public static function render( $template, $name, $date, $hours ) {
		return strtr(
			(string) $template,
			array(
				'{name}'  => (string) $name,
				'{date}'  => (string) $date,
				'{hours}' => (string) $hours,
			)
		);
	}

	/**
	 * Whole minutes, same rounding the timecard uses for H:MM.
	 *
	 * @param int $seconds Seconds.
	 * @return int
	 */
	public static function shown_minutes( $seconds ) {
		if ( class_exists( 'Css_Tc_Time' ) ) {
			return Css_Tc_Time::whole_minutes( $seconds );
		}
		$seconds = (int) $seconds;
		if ( $seconds < 0 ) {
			$seconds = 0;
		}
		return (int) round( $seconds / 60 );
	}

	/**
	 * @param int                              $worked_seconds Seconds already counted for the day.
	 * @param array<int,array<string,mixed>>   $segments       in_ts, out_ts, break_seconds, is_meal, open.
	 * @param array<string,mixed>              $rules          Rules from rules_from().
	 * @return array{missed:bool,has_lunch:bool,worked_seconds:int,minutes:int}
	 */
	public static function assess( $worked_seconds, $segments, $rules ) {
		$worked  = max( 0, (int) $worked_seconds );
		$minutes = self::shown_minutes( $worked );
		$limit   = (int) round( (float) $rules['hours'] * 60 );
		$minimum = max( 1, (int) $rules['break_seconds'] );
		$lunch   = self::has_lunch( $segments, $minimum );
		return array(
			'missed'         => ( $minutes > $limit && ! $lunch ),
			'has_lunch'      => $lunch,
			'worked_seconds' => $worked,
			'minutes'        => $minutes,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $segments Segments.
	 * @param int                            $minimum  Seconds that count as a meal.
	 * @return bool
	 */
	public static function has_lunch( $segments, $minimum ) {
		if ( ! is_array( $segments ) ) {
			return false;
		}
		$rows = array();
		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}
			if ( ! empty( $segment['is_meal'] ) ) {
				return true;
			}
			$break_seconds = isset( $segment['break_seconds'] ) ? (int) $segment['break_seconds'] : 0;
			if ( $break_seconds >= $minimum ) {
				return true;
			}
			$rows[] = $segment;
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				$ai = isset( $a['in_ts'] ) ? (int) $a['in_ts'] : 0;
				$bi = isset( $b['in_ts'] ) ? (int) $b['in_ts'] : 0;
				if ( $ai === $bi ) {
					return 0;
				}
				return ( $ai < $bi ) ? -1 : 1;
			}
		);
		$previous_out = 0;
		foreach ( $rows as $segment ) {
			$in_ts  = isset( $segment['in_ts'] ) ? (int) $segment['in_ts'] : 0;
			$out_ts = isset( $segment['out_ts'] ) ? (int) $segment['out_ts'] : 0;
			$open   = ! empty( $segment['open'] );
			if ( $previous_out > 0 && $in_ts > 0 && ! $open ) {
				$gap = $in_ts - $previous_out;
				if ( $gap >= $minimum ) {
					return true;
				}
			}
			if ( ! $open && $out_ts > 0 ) {
				$previous_out = $out_ts;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed>|null $log  Stored log.
	 * @param string                   $date Y-m-d.
	 * @return bool
	 */
	public static function was_sent( $log, $date ) {
		return is_array( $log ) && isset( $log[ $date ]['ok'] ) && ! empty( $log[ $date ]['ok'] );
	}

	/**
	 * @param array<string,mixed>|null $log   Stored log.
	 * @param string                   $date  Y-m-d.
	 * @param array<string,mixed>      $entry What was sent.
	 * @return array<string,mixed>
	 */
	public static function remember( $log, $date, $entry ) {
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[ $date ] = $entry;
		if ( count( $log ) > 400 ) {
			ksort( $log );
			$log = array_slice( $log, count( $log ) - 400, 400, true );
		}
		return $log;
	}

	/**
	 * Timecard flag. Hours on the day are the seconds the timecard already summed.
	 *
	 * @param array<int,array<string,mixed>> $shifts  Shift rows for one work day.
	 * @param int                            $seconds Counted seconds.
	 * @return bool
	 */
	public function day_is_missed( $shifts, $seconds ) {
		if ( ! $this->enabled() ) {
			return false;
		}
		$result = self::assess( (int) $seconds, $this->segments_for( $shifts ), self::rules_from( $this->settings() ) );
		return ! empty( $result['missed'] );
	}

	/**
	 * @param int    $shift_id Shift that just closed.
	 * @param int    $user_id  Employee.
	 * @param string $source   pin_kiosk|name_kiosk|aio|switch.
	 * @return void
	 */
	public function on_clock_out( $shift_id, $user_id, $source ) {
		if ( 'switch' === (string) $source || ! $this->enabled() ) {
			return;
		}
		$user_id  = (int) $user_id;
		$shift_id = (int) $shift_id;
		if ( $user_id < 1 || $shift_id < 1 || ! function_exists( 'get_post' ) ) {
			return;
		}
		$post = get_post( $shift_id );
		if ( ! $post || ! isset( css_tc_addon()->punches ) ) {
			return;
		}
		$row = css_tc_addon()->punches->shift_row( $post );
		if ( ! is_array( $row ) || empty( $row['work_date'] ) ) {
			return;
		}
		$date = (string) $row['work_date'];
		$log  = $this->log_for( $user_id );
		if ( self::was_sent( $log, $date ) ) {
			return;
		}
		$shifts = $this->shifts_on_date( $user_id, $date );
		$worked = 0;
		foreach ( $shifts as $shift ) {
			$worked += max( 0, (int) $shift['seconds'] );
		}
		$rules  = self::rules_from( $this->settings() );
		$result = self::assess( $worked, $this->segments_for( $shifts, $user_id, $date ), $rules );
		if ( empty( $result['missed'] ) ) {
			return;
		}
		$sent = $this->send( $user_id, $date, $worked, $rules );
		if ( empty( $sent['ok'] ) ) {
			return;
		}
		update_user_meta( $user_id, self::LOG_META, self::remember( $log, $date, $sent ) );
	}

	/**
	 * Break minutes stored on a shift, if a breaks add-on wrote one.
	 * The value is minutes. A type of lunch or meal counts on its own.
	 *
	 * @param array<string,mixed> $meta Post meta.
	 * @return array{break_seconds:int,is_meal:bool}
	 */
	public static function break_from_meta( $meta ) {
		$out = array(
			'break_seconds' => 0,
			'is_meal'       => false,
		);
		if ( ! is_array( $meta ) ) {
			return $out;
		}
		foreach ( array( 'break_type', 'aio_break_type', 'shift_break_type' ) as $key ) {
			if ( ! isset( $meta[ $key ] ) ) {
				continue;
			}
			$kind = strtolower( trim( (string) $meta[ $key ] ) );
			if ( 'lunch' === $kind || 'meal' === $kind ) {
				$out['is_meal'] = true;
			}
		}
		foreach ( array( 'employee_break_time', 'break_length', 'aio_break_length' ) as $key ) {
			if ( ! isset( $meta[ $key ] ) || ! is_numeric( $meta[ $key ] ) ) {
				continue;
			}
			$minutes = (float) $meta[ $key ];
			if ( $minutes > 0 && $minutes <= 1440 ) {
				$out['break_seconds'] = (int) round( $minutes * 60 );
				break;
			}
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $shifts Shift rows.
	 * @param int                            $user_id Employee, for the breaks filter.
	 * @param string                         $date    Y-m-d.
	 * @return array<int,array<string,mixed>>
	 */
	public function segments_for( $shifts, $user_id = 0, $date = '' ) {
		$segments = array();
		if ( is_array( $shifts ) ) {
			foreach ( $shifts as $shift ) {
				if ( ! is_array( $shift ) ) {
					continue;
				}
				$meta  = $this->meta_for_shift( $shift );
				$break = self::break_from_meta( $meta );
				$in    = $this->timestamp_of( isset( $shift['clock_in_raw'] ) ? $shift['clock_in_raw'] : '' );
				$out   = $this->timestamp_of( isset( $shift['clock_out_raw'] ) ? $shift['clock_out_raw'] : '' );
				$segments[] = array(
					'in_ts'         => $in,
					'out_ts'        => $out,
					'open'          => ! empty( $shift['is_open'] ),
					'break_seconds' => $break['break_seconds'],
					'is_meal'       => $break['is_meal'],
				);
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$extra = apply_filters( 'css_tc_lunch_segments', array(), (int) $user_id, (string) $date, $shifts );
			if ( is_array( $extra ) ) {
				foreach ( $extra as $segment ) {
					if ( is_array( $segment ) ) {
						$segments[] = $segment;
					}
				}
			}
		}
		return $segments;
	}

	/**
	 * @param int    $user_id Employee.
	 * @param string $date    Y-m-d.
	 * @param int    $worked  Seconds.
	 * @param array<string,mixed> $rules Rules.
	 * @return array<string,mixed>
	 */
	private function send( $user_id, $date, $worked, $rules ) {
		$name    = $this->employee_name( $user_id );
		$label   = $this->date_label( $date );
		$hours   = class_exists( 'Css_Tc_Time' ) ? css_tc_addon()->time->format_duration( $worked ) : self::fallback_hours( $worked );
		$subject = str_replace( array( "\r", "\n" ), ' ', self::render( $rules['subject'], $name, $label, $hours ) );
		$body    = self::render( $rules['body'], $name, $label, $hours );
		$to      = self::recipients( $rules, $this->employee_email( $user_id ), $this->admin_emails() );
		$sent    = array();
		$failed  = array();
		foreach ( $to as $email ) {
			$ok = function_exists( 'wp_mail' ) ? wp_mail( $email, $subject, $body ) : false;
			if ( $ok ) {
				$sent[] = $email;
			} else {
				$failed[] = $email;
			}
		}
		return array(
			'ok'       => ! empty( $sent ),
			'sent_at'  => gmdate( 'Y-m-d H:i:s' ),
			'to'       => $sent,
			'failed'   => $failed,
			'subject'  => $subject,
			'body'     => $body,
			'hours'    => $hours,
			'date'     => $date,
		);
	}

	/**
	 * @param int $user_id Employee.
	 * @return array<string,mixed>
	 */
	private function log_for( $user_id ) {
		if ( ! function_exists( 'get_user_meta' ) ) {
			return array();
		}
		$log = get_user_meta( (int) $user_id, self::LOG_META, true );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * @param int    $user_id Employee.
	 * @param string $date    Y-m-d.
	 * @return array<int,array<string,mixed>>
	 */
	private function shifts_on_date( $user_id, $date ) {
		if ( ! isset( css_tc_addon()->punches ) ) {
			return array();
		}
		$rows = css_tc_addon()->punches->shifts_since( (int) $user_id, $date );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['work_date'] ) && (string) $row['work_date'] === $date ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $shift Shift row.
	 * @return array<string,mixed>
	 */
	private function meta_for_shift( $shift ) {
		if ( empty( $shift['id'] ) || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		$meta = get_post_meta( (int) $shift['id'] );
		$flat = array();
		if ( ! is_array( $meta ) ) {
			return $flat;
		}
		foreach ( $meta as $key => $values ) {
			$flat[ $key ] = is_array( $values ) ? reset( $values ) : $values;
		}
		return $flat;
	}

	/**
	 * @param string $stored Y-m-d H:i:s UTC.
	 * @return int
	 */
	private function timestamp_of( $stored ) {
		$stored = trim( (string) $stored );
		if ( '' === $stored ) {
			return 0;
		}
		if ( isset( css_tc_addon()->time ) ) {
			$parsed = css_tc_addon()->time->parse_stored( $stored );
			if ( $parsed ) {
				return $parsed->getTimestamp();
			}
		}
		$fallback = strtotime( $stored . ' UTC' );
		return $fallback ? (int) $fallback : 0;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function settings() {
		if ( function_exists( 'css_tc_addon' ) ) {
			$addon = css_tc_addon();
			if ( is_object( $addon ) && method_exists( $addon, 'get_settings' ) ) {
				return $addon->get_settings();
			}
		}
		return self::defaults();
	}

	/**
	 * @param int $user_id Employee.
	 * @return string
	 */
	private function employee_name( $user_id ) {
		if ( isset( css_tc_addon()->employees ) ) {
			return css_tc_addon()->employees->display_name( (int) $user_id );
		}
		return '';
	}

	/**
	 * @param int $user_id Employee.
	 * @return string
	 */
	private function employee_email( $user_id ) {
		if ( ! function_exists( 'get_userdata' ) ) {
			return '';
		}
		$user = get_userdata( (int) $user_id );
		return ( $user && ! empty( $user->user_email ) ) ? (string) $user->user_email : '';
	}

	/**
	 * @return string[]
	 */
	private function admin_emails() {
		if ( ! function_exists( 'get_users' ) || ! class_exists( 'Css_Tc_Plugin' ) ) {
			return array();
		}
		$users = get_users(
			array(
				'role__in' => Css_Tc_Plugin::manager_roles(),
				'number'   => 200,
				'fields'   => array( 'user_email' ),
			)
		);
		$out = array();
		foreach ( $users as $user ) {
			if ( is_object( $user ) && ! empty( $user->user_email ) ) {
				$out[] = (string) $user->user_email;
			}
		}
		return $out;
	}

	/**
	 * @param string $date Y-m-d.
	 * @return string
	 */
	private function date_label( $date ) {
		if ( isset( css_tc_addon()->time ) ) {
			$label = css_tc_addon()->time->format_day_label( $date );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return (string) $date;
	}

	/**
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function fallback_hours( $seconds ) {
		$minutes = self::shown_minutes( $seconds );
		$hours   = (int) floor( $minutes / 60 );
		$remain  = $minutes % 60;
		return $hours . ':' . str_pad( (string) $remain, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * @param mixed $text  Raw text.
	 * @param int   $limit Max length.
	 * @return string
	 */
	private static function plain( $text, $limit ) {
		$text = trim( strip_tags( str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) ) );
		if ( strlen( $text ) > $limit ) {
			$text = substr( $text, 0, $limit );
		}
		return $text;
	}
}
