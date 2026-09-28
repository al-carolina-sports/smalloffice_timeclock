<?php
/**
 * Compatibility fixes for All in One Time Clock Lite.
 *
 * AIO Lite registers its "department" user taxonomy with
 * update_count_callback "aio_lite_update_department_count" but never
 * defines that function. Once any department exists, saving a user profile
 * with a department selected makes WordPress call it and the request dies
 * with a fatal error (call_user_func(): ... not found). Supply it here,
 * only if AIO has not.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'aio_lite_update_department_count' ) ) {
	/**
	 * Count users in each department term (same as WordPress's generic count).
	 *
	 * @param int[]       $terms    Term taxonomy IDs.
	 * @param WP_Taxonomy $taxonomy Taxonomy object.
	 * @return void
	 */
	function aio_lite_update_department_count( $terms, $taxonomy ) {
		_update_generic_term_count( $terms, $taxonomy );
	}
}
