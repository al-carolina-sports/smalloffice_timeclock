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

// Manager capability granted on activation.
foreach ( array( 'administrator', 'time_clock_admin' ) as $css_tc_role_name ) {
	$css_tc_role = get_role( $css_tc_role_name );
	if ( $css_tc_role ) {
		$css_tc_role->remove_cap( 'css_tc_manage' );
	}
}
delete_option( 'css_tc_caps_version' );
delete_option( 'css_tc_caps_roles' );

// Office hostname lookups.
delete_option( 'css_tc_dns_cache' );
wp_clear_scheduled_hook( 'css_tc_refresh_dns' );

// Refused kiosk request log and per-manager dismissals.
delete_option( 'css_tc_refused_kiosk' );
delete_metadata( 'user', 0, 'css_tc_refused_dismissed', '', true );

// Hire dates and introductory periods.
delete_metadata( 'user', 0, 'css_tc_hire_date', '', true );
delete_metadata( 'user', 0, 'css_tc_intro_days', '', true );

// PTO & sick: time-off records, allowance overrides and adjustments.
$css_tc_leave_ids = get_posts(
	array(
		'post_type'      => 'css_tc_leave',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $css_tc_leave_ids as $css_tc_leave_id ) {
	wp_delete_post( (int) $css_tc_leave_id, true );
}
delete_metadata( 'user', 0, 'css_tc_leave_allow', '', true );
delete_metadata( 'user', 0, 'css_tc_leave_adjust', '', true );

// Employment status.
foreach ( array( 'css_tc_status', 'css_tc_leave_from', 'css_tc_leave_to', 'css_tc_last_day', 'css_tc_status_log' ) as $css_tc_meta_key ) {
	delete_metadata( 'user', 0, $css_tc_meta_key, '', true );
}
