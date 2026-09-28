<?php
/**
 * Remove add-on options, hashed PINs, and correction posts. Leaves AIO shift posts untouched.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'css_tc_addon_settings' );

$correction_ids = get_posts(
	array(
		'post_type'      => 'css_tc_correction',
		'post_status'    => 'any',
		'posts_per_page' => 500,
		'fields'         => 'ids',
	)
);
foreach ( $correction_ids as $correction_id ) {
	wp_delete_post( (int) $correction_id, true );
}

$user_ids = get_users(
	array(
		'meta_key'     => 'css_tc_pin_hash',
		'meta_compare' => 'EXISTS',
		'fields'       => 'ID',
		'number'       => 5000,
	)
);

foreach ( $user_ids as $user_id ) {
	delete_user_meta( (int) $user_id, 'css_tc_pin_hash' );
	delete_user_meta( (int) $user_id, 'css_tc_pin_set_at' );
	delete_user_meta( (int) $user_id, 'css_tc_pin_enc' );
	delete_user_meta( (int) $user_id, 'css_tc_pin_reveals' );
}

$flagged_users = get_users(
	array(
		'meta_key'     => 'css_tc_flagged_dates',
		'meta_compare' => 'EXISTS',
		'fields'       => 'ID',
		'number'       => 5000,
	)
);
foreach ( $flagged_users as $user_id ) {
	delete_user_meta( (int) $user_id, 'css_tc_flagged_dates' );
}

// Locations, departments and companies setup, and employee assignments.
// Shifts keep their department/location/company meta and name snapshot as
// part of the payroll record, like the AIO shift posts themselves.
delete_option( 'css_tc_org' );
foreach ( array( 'css_tc_departments', 'css_tc_home_department' ) as $css_tc_meta_key ) {
	$css_tc_users = get_users(
		array(
			'meta_key'     => $css_tc_meta_key,
			'meta_compare' => 'EXISTS',
			'fields'       => 'ID',
			'number'       => 5000,
		)
	);
	foreach ( $css_tc_users as $user_id ) {
		delete_user_meta( (int) $user_id, $css_tc_meta_key );
	}
}
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'css_tc_punch_lock_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
