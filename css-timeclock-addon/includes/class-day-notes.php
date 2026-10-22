<?php
/**
 * Manager notes on one employee day. Display and audit only.
 *
 * A note never creates a shift, a correction, or paid time. Pay, overtime,
 * and PTO totals do not read this meta.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User meta css_tc_day_notes and css_tc_day_note_audit.
 */
class Css_Tc_Day_Notes {

	const META       = 'css_tc_day_notes';
	const AUDIT_META = 'css_tc_day_note_audit';
	const NONCE      = 'css_tc_day_note';
	const MAX_CHARS  = 500;
	const AUDIT_KEEP = 200;

	/**
	 * Short labels a manager can drop into the note.
	 *
	 * @return string[]
	 */
	public static function presets() {
		return array(
			__( 'Out sick', 'css-timeclock-addon' ),
			__( 'Personal day', 'css-timeclock-addon' ),
			__( 'No call/no show', 'css-timeclock-addon' ),
			__( 'Excused', 'css-timeclock-addon' ),
		);
	}

	/**
	 * Managers and administrators may edit. The target must be a time-clock
	 * employee and must not be an administrator. An employee without
	 * css_tc_manage or manage_options cannot edit, including their own note.
	 *
	 * @param bool $actor_can_manage        Current user is a manager or administrator.
	 * @param int  $actor_id                Current user ID.
	 * @param int  $target_id               Employee being noted.
	 * @param bool $target_is_employee      Target passes is_employee().
	 * @param bool $target_is_administrator Target has the administrator role.
	 * @return bool
	 */
	public static function may_edit( $actor_can_manage, $actor_id, $target_id, $target_is_employee, $target_is_administrator ) {
		if ( ! $actor_can_manage || (int) $actor_id < 1 || (int) $target_id < 1 ) {
			return false;
		}
		if ( $target_is_administrator || ! $target_is_employee ) {
			return false;
		}
		return true;
	}

	/**
	 * sanitize_textarea_field, then at most 500 characters.
	 *
	 * @param mixed $text Raw note.
	 * @return string
	 */
	public static function sanitize_text( $text ) {
		$text = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( (string) $text ) : trim( (string) $text );
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) > self::MAX_CHARS ) {
				$text = mb_substr( $text, 0, self::MAX_CHARS );
			}
			return $text;
		}
		if ( strlen( $text ) > self::MAX_CHARS ) {
			$text = substr( $text, 0, self::MAX_CHARS );
		}
		return $text;
	}

	/**
	 * @param mixed $raw Stored user meta.
	 * @return array<string,array{text:string,by:int,at:int,updated_by:int,updated_at:int}>
	 */
	public static function normalize_map( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $date => $note ) {
			$date = (string) $date;
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! is_array( $note ) ) {
				continue;
			}
			$text = isset( $note['text'] ) ? self::sanitize_text( $note['text'] ) : '';
			if ( '' === $text ) {
				continue;
			}
			$by = isset( $note['by'] ) ? (int) $note['by'] : 0;
			$at = isset( $note['at'] ) ? (int) $note['at'] : 0;
			$out[ $date ] = array(
				'text'       => $text,
				'by'         => $by,
				'at'         => $at,
				'updated_by' => isset( $note['updated_by'] ) ? (int) $note['updated_by'] : $by,
				'updated_at' => isset( $note['updated_at'] ) ? (int) $note['updated_at'] : $at,
			);
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Apply one edit in memory. An empty note deletes that day. No change
	 * returns a null audit entry. Does not write posts.
	 *
	 * @param mixed  $map      Stored map.
	 * @param string $date     Y-m-d.
	 * @param mixed  $text     Raw note.
	 * @param int    $actor_id Manager.
	 * @param int    $at       Unix time.
	 * @return array{map:array<string,array<string,mixed>>,audit:array<string,mixed>|null,changed:bool}
	 */
	public static function apply_change( $map, $date, $text, $actor_id, $at ) {
		$map      = self::normalize_map( $map );
		$date     = (string) $date;
		$text     = self::sanitize_text( $text );
		$actor_id = (int) $actor_id;
		$at       = (int) $at;
		$before   = isset( $map[ $date ]['text'] ) ? (string) $map[ $date ]['text'] : '';
		if ( $text === $before ) {
			return array(
				'map'     => $map,
				'audit'   => null,
				'changed' => false,
			);
		}
		if ( '' === $text ) {
			unset( $map[ $date ] );
		} else {
			$existing     = isset( $map[ $date ] ) ? $map[ $date ] : null;
			$map[ $date ] = array(
				'text'       => $text,
				'by'         => $existing ? (int) $existing['by'] : $actor_id,
				'at'         => $existing ? (int) $existing['at'] : $at,
				'updated_by' => $actor_id,
				'updated_at' => $at,
			);
			ksort( $map );
		}
		return array(
			'map'     => $map,
			'changed' => true,
			'audit'   => array(
				'date'   => $date,
				'before' => $before,
				'after'  => $text,
				'by'     => $actor_id,
				'at'     => $at,
			),
		);
	}

	/**
	 * Keep the last 200 audit rows.
	 *
	 * @param mixed               $log   Stored log.
	 * @param array<string,mixed> $entry date, before, after, by, at.
	 * @return array<int,array<string,mixed>>
	 */
	public static function append_audit( $log, $entry ) {
		$rows   = array();
		$source = is_array( $log ) ? array_values( $log ) : array();
		foreach ( $source as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['date'] ) ) {
				continue;
			}
			$rows[] = array(
				'date'   => (string) $row['date'],
				'before' => isset( $row['before'] ) ? (string) $row['before'] : '',
				'after'  => isset( $row['after'] ) ? (string) $row['after'] : '',
				'by'     => isset( $row['by'] ) ? (int) $row['by'] : 0,
				'at'     => isset( $row['at'] ) ? (int) $row['at'] : 0,
			);
		}
		$rows[] = array(
			'date'   => (string) $entry['date'],
			'before' => (string) $entry['before'],
			'after'  => (string) $entry['after'],
			'by'     => (int) $entry['by'],
			'at'     => (int) $entry['at'],
		);
		if ( count( $rows ) > self::AUDIT_KEEP ) {
			$rows = array_slice( $rows, -1 * self::AUDIT_KEEP );
		}
		return $rows;
	}

	/**
	 * "Y-m-d: text" joined for a pay period. Notes do not add hours.
	 *
	 * @param mixed  $map   Stored map.
	 * @param string $start Period start Y-m-d.
	 * @param string $end   Period end Y-m-d.
	 * @return string
	 */
	public static function format_period_notes( $map, $start, $end ) {
		$parts = array();
		foreach ( self::normalize_map( $map ) as $date => $note ) {
			if ( $date < (string) $start || $date > (string) $end ) {
				continue;
			}
			$parts[] = $date . ': ' . $note['text'];
		}
		return implode( '; ', $parts );
	}

	/**
	 * A calendar cell is empty only when it has no punches, paid markers, or note.
	 *
	 * @param mixed $day Day cell from the timecard.
	 * @return bool
	 */
	public static function day_has_content( $day ) {
		if ( ! is_array( $day ) ) {
			return false;
		}
		if ( ! empty( $day['shifts'] ) || ! empty( $day['has_time'] ) || ! empty( $day['holiday'] ) || ! empty( $day['leave'] ) ) {
			return true;
		}
		if ( ! empty( $day['note'] ) && is_array( $day['note'] ) && '' !== (string) ( isset( $day['note']['text'] ) ? $day['note']['text'] : '' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param int $user_id Employee.
	 * @return array<string,array<string,mixed>>
	 */
	public function for_user( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META, true );
		return self::normalize_map( $raw );
	}

	/**
	 * @param int    $user_id Employee.
	 * @param string $date    Y-m-d.
	 * @return array<string,mixed>|null
	 */
	public function for_date( $user_id, $date ) {
		$map = $this->for_user( $user_id );
		return isset( $map[ $date ] ) ? $map[ $date ] : null;
	}

	/**
	 * Rows for the day editor, newest first.
	 *
	 * @param int    $user_id Employee.
	 * @param string $date    Y-m-d.
	 * @return array<int,array<string,mixed>>
	 */
	public function audit_for_date( $user_id, $date ) {
		$log = get_user_meta( (int) $user_id, self::AUDIT_META, true );
		if ( ! is_array( $log ) ) {
			return array();
		}
		$rows = array();
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) || (string) ( isset( $entry['date'] ) ? $entry['date'] : '' ) !== (string) $date ) {
				continue;
			}
			$rows[] = $entry;
		}
		return array_reverse( $rows );
	}

	/**
	 * Text plus "by <Manager>, <date>" for the timecard. Display only.
	 *
	 * @param array<string,mixed>|null $note Stored note.
	 * @return array<string,mixed>|null
	 */
	public function present( $note ) {
		if ( ! is_array( $note ) || '' === (string) ( isset( $note['text'] ) ? $note['text'] : '' ) ) {
			return null;
		}
		$who  = ! empty( $note['updated_by'] ) ? (int) $note['updated_by'] : (int) ( isset( $note['by'] ) ? $note['by'] : 0 );
		$when = ! empty( $note['updated_at'] ) ? (int) $note['updated_at'] : (int) ( isset( $note['at'] ) ? $note['at'] : 0 );
		$name = css_tc_addon()->employees->display_name( $who );
		if ( '' === $name ) {
			/* translators: %d: user ID */
			$name = sprintf( __( 'User %d', 'css-timeclock-addon' ), $who );
		}
		return array(
			'text'     => (string) $note['text'],
			'by'       => $who,
			'by_name'  => $name,
			'at'       => $when,
			'at_label' => $this->format_date( $when ),
		);
	}

	/**
	 * @param int $at Unix time.
	 * @return string
	 */
	public function format_date( $at ) {
		$at = (int) $at;
		if ( $at < 1 ) {
			return '';
		}
		$format = function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'M j, Y' ) : 'M j, Y';
		if ( '' === $format ) {
			$format = 'M j, Y';
		}
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( $format, $at );
		}
		return date( $format, $at ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	/**
	 * @param int $at Unix time.
	 * @return string
	 */
	public function format_when( $at ) {
		$at = (int) $at;
		if ( $at < 1 ) {
			return '';
		}
		$date = function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'M j, Y' ) : 'M j, Y';
		$time = function_exists( 'get_option' ) ? (string) get_option( 'time_format', 'g:i a' ) : 'g:i a';
		$format = trim( $date . ' ' . $time );
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( $format, $at );
		}
		return date( $format, $at ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	/**
	 * Save or clear one day. Writes user meta only: no shift, no correction.
	 * Open and closed pay periods are both allowed. A future day is not.
	 *
	 * @param int    $employee_id Employee.
	 * @param int    $actor_id    Manager.
	 * @param string $date        Y-m-d.
	 * @param mixed  $text        Raw note. Empty deletes.
	 * @return true|WP_Error
	 */
	public function save( $employee_id, $actor_id, $date, $text ) {
		$started     = microtime( true );
		$employee_id = (int) $employee_id;
		$actor_id    = (int) $actor_id;
		$date        = sanitize_text_field( (string) $date );
		$user        = get_userdata( $employee_id );
		$is_admin    = $user && in_array( 'administrator', (array) $user->roles, true );
		$allowed     = self::may_edit(
			Css_Tc_Plugin::user_can_manage(),
			(int) get_current_user_id(),
			$employee_id,
			css_tc_addon()->employees->is_employee( $employee_id ),
			$is_admin
		);
		if ( ! $allowed || (int) get_current_user_id() !== $actor_id ) {
			return new WP_Error( 'css_tc_forbidden', __( 'You do not have permission to edit this day note.', 'css-timeclock-addon' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'css_tc_bad_date', __( 'Choose a valid day.', 'css-timeclock-addon' ) );
		}
		if ( $date > css_tc_addon()->time->site_today() ) {
			return new WP_Error( 'css_tc_future', __( 'You can note a day through today. Later days open when they arrive.', 'css-timeclock-addon' ) );
		}

		$result = self::apply_change( $this->for_user( $employee_id ), $date, $text, $actor_id, time() );
		if ( $result['changed'] ) {
			if ( empty( $result['map'] ) ) {
				delete_user_meta( $employee_id, self::META );
			} else {
				update_user_meta( $employee_id, self::META, $result['map'] );
			}
			if ( is_array( $result['audit'] ) ) {
				$log = get_user_meta( $employee_id, self::AUDIT_META, true );
				update_user_meta( $employee_id, self::AUDIT_META, self::append_audit( $log, $result['audit'] ) );
			}
		}
		Css_Tc_Plugin::log_timing( 'day_note employee=' . $employee_id . ' date=' . $date, $started );
		return true;
	}
}
