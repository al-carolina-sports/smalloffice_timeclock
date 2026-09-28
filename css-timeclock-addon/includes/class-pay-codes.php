<?php
/**
 * Pay codes for timecard summaries.
 *
 * Regular is always on. Overtime and Holiday appear when their settings are on;
 * others can be added with the
 * css_tc_pay_codes and css_tc_shift_pay_code filters. Overtime is added when
 * the admin turns on the overtime rule (see Css_Tc_Overtime).
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pay-code registry.
 */
class Css_Tc_Pay_Codes {

	const REGULAR  = 'regular';
	const OVERTIME = 'overtime';
	const HOLIDAY  = 'holiday';

	/**
	 * @return array<string,array{label:string,order:int}>
	 */
	public function definitions() {
		$codes = array(
			self::REGULAR => array(
				'label' => __( 'Regular', 'css-timeclock-addon' ),
				'order' => 10,
			),
		);
		if ( css_tc_addon()->overtime->rule()['enabled'] ) {
			$codes[ self::OVERTIME ] = array(
				'label' => __( 'Overtime', 'css-timeclock-addon' ),
				'order' => 20,
			);
		}

		if ( css_tc_addon()->holidays->enabled() ) {
			$codes[ self::HOLIDAY ] = array(
				'label' => __( 'Holiday', 'css-timeclock-addon' ),
				'order' => 30,
			);
		}

		/**
		 * Register additional pay codes.
		 *
		 * @param array<string,array{label:string,order:int}> $codes Code slug => definition.
		 */
		$filtered = apply_filters( 'css_tc_pay_codes', $codes );
		if ( ! is_array( $filtered ) || ! isset( $filtered[ self::REGULAR ] ) ) {
			return $codes;
		}

		return $filtered;
	}

	/**
	 * Pay code for one shift. Unknown codes fall back to Regular.
	 *
	 * @param array<string,mixed> $shift Shift row.
	 * @return string
	 */
	public function code_for_shift( $shift ) {
		/**
		 * Classify a shift. Return a slug from css_tc_pay_codes.
		 *
		 * @param string              $code  Default regular.
		 * @param array<string,mixed> $shift Shift row.
		 */
		$code = apply_filters( 'css_tc_shift_pay_code', self::REGULAR, $shift );
		$code = is_string( $code ) ? sanitize_key( $code ) : self::REGULAR;
		$defs = $this->definitions();
		if ( ! isset( $defs[ $code ] ) ) {
			return self::REGULAR;
		}
		return $code;
	}
}
