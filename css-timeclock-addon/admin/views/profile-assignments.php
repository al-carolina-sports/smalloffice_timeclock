<?php
/**
 * Time clock departments on the user profile (managers only).
 *
 * @package CssTimeclockAddon
 *
 * @var WP_User                        $user
 * @var array<int,array<string,mixed>> $departments
 * @var int[]                          $assigned
 * @var int                            $home
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_org = css_tc_addon()->organization;
?>
<h2 id="css-tc-assignments"><?php echo esc_html__( 'Time clock departments', 'css-timeclock-addon' ); ?></h2>
<input type="hidden" name="css_tc_assign_present" value="1" />
<?php wp_nonce_field( Css_Tc_Organization::PROFILE_NONCE, 'css_tc_assign_nonce' ); ?>
<?php if ( empty( $departments ) ) : ?>
	<p class="description"><?php echo esc_html__( 'No departments are set up yet. Add them under SMOTC → Locations.', 'css-timeclock-addon' ); ?></p>
<?php else : ?>
	<p class="description"><?php echo esc_html__( 'Tick every department this employee can clock into and choose their home department, which is listed first at the kiosk. Saved with the Update User button at the bottom of this page. Companies, locations and departments are managed under SMOTC → Locations & departments.', 'css-timeclock-addon' ); ?></p>
	<table class="widefat striped css-tc-assign" style="max-width:720px">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Can work', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Home', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Location', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Department', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Company', 'css-timeclock-addon' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $departments as $dept ) : ?>
				<?php $css_tc_id = (int) $dept['id']; ?>
				<tr>
					<td><input type="checkbox" id="css-tc-dept-<?php echo esc_attr( (string) $css_tc_id ); ?>" name="css_tc_departments[]" value="<?php echo esc_attr( (string) $css_tc_id ); ?>" <?php checked( in_array( $css_tc_id, $assigned, true ) ); ?> /></td>
					<td><input type="radio" name="css_tc_home_department" value="<?php echo esc_attr( (string) $css_tc_id ); ?>" <?php checked( $home, $css_tc_id ); ?> aria-label="<?php echo esc_attr__( 'Home department', 'css-timeclock-addon' ); ?>" /></td>
					<td><label for="css-tc-dept-<?php echo esc_attr( (string) $css_tc_id ); ?>"><?php echo esc_html( $css_tc_org->location_name( (int) $dept['location_id'] ) ); ?></label></td>
					<td><?php echo esc_html( $dept['name'] ); ?></td>
					<td><?php echo esc_html( $css_tc_org->company_name( (int) $dept['company_id'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<td></td>
				<td><input type="radio" id="css-tc-no-home" name="css_tc_home_department" value="<?php echo esc_attr( (string) Css_Tc_Organization::NO_HOME ); ?>" <?php checked( $css_tc_org->has_no_home( (int) $user->ID ) ); ?> /></td>
				<td colspan="3"><label for="css-tc-no-home"><?php echo esc_html__( 'No home department (choices are listed alphabetically at the kiosk)', 'css-timeclock-addon' ); ?></label></td>
			</tr>
		</tbody>
	</table>
<?php endif; ?>
