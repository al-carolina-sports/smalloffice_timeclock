<?php
/**
 * SMOTC → Locations tab: companies, locations (with office networks) and departments.
 *
 * @package CssTimeclockAddon
 *
 * @var string $base_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_org       = css_tc_addon()->organization;
$css_tc_companies = $css_tc_org->companies();
$css_tc_locations = $css_tc_org->locations();
$css_tc_depts     = $css_tc_org->departments();
$css_tc_msg       = isset( $_GET['css_tc_org_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['css_tc_org_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$css_tc_err       = isset( $_GET['css_tc_org_err'] ) ? sanitize_text_field( wp_unslash( $_GET['css_tc_org_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$css_tc_post_url  = admin_url( 'admin-post.php' );
$css_tc_enabled   = ! empty( css_tc_addon()->get_settings()['assignments_enabled'] );
$css_tc_switch    = $css_tc_org->switch_enabled();

/**
 * Hidden fields every setup form needs.
 *
 * @param string $op   save|delete|import.
 * @param string $kind companies|locations|departments.
 * @param int    $id   Row ID.
 * @return void
 */
$css_tc_hidden = static function ( $op, $kind = '', $id = 0 ) {
	?>
	<input type="hidden" name="action" value="<?php echo esc_attr( Css_Tc_Organization::ADMIN_ACTION ); ?>" />
	<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>" />
	<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>" />
	<input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $id ); ?>" />
	<?php
	wp_nonce_field( Css_Tc_Organization::ADMIN_ACTION );
};
?>
<?php if ( '' !== $css_tc_msg ) : ?>
	<div class="notice notice-success"><p><?php echo esc_html( $css_tc_msg ); ?></p></div>
<?php endif; ?>
<?php if ( '' !== $css_tc_err ) : ?>
	<div class="notice notice-error"><p><?php echo esc_html( $css_tc_err ); ?></p></div>
<?php endif; ?>

<div class="css-tc-org">
	<p class="description">
		<?php echo esc_html__( 'Companies are employers (one payroll each). Locations are offices. Departments sit inside a location and belong to one company. Assign employees to departments on their user profile.', 'css-timeclock-addon' ); ?>
	</p>
	<p>
		<strong><?php echo esc_html__( 'Ask for department at clock-in:', 'css-timeclock-addon' ); ?></strong>
		<?php echo $css_tc_enabled ? esc_html__( 'On', 'css-timeclock-addon' ) : esc_html__( 'Off', 'css-timeclock-addon' ); ?>
		· <strong><?php echo esc_html__( 'Switch:', 'css-timeclock-addon' ); ?></strong>
		<?php echo $css_tc_switch ? esc_html__( 'On', 'css-timeclock-addon' ) : esc_html__( 'Off', 'css-timeclock-addon' ); ?>
		— <a href="<?php echo esc_url( $base_url . '&tab=settings#assignments_enabled' ); ?>"><?php echo esc_html__( 'change in Kiosk settings', 'css-timeclock-addon' ); ?></a>
	</p>

	<h2><?php echo esc_html__( 'Companies', 'css-timeclock-addon' ); ?></h2>
	<table class="widefat striped css-tc-org__table">
		<tbody>
			<?php foreach ( $css_tc_companies as $company ) : ?>
				<tr>
					<td>
						<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>" class="css-tc-org__row">
							<?php $css_tc_hidden( 'save', 'companies', (int) $company['id'] ); ?>
							<input type="text" name="name" value="<?php echo esc_attr( $company['name'] ); ?>" aria-label="<?php echo esc_attr__( 'Company name', 'css-timeclock-addon' ); ?>" />
							<button type="submit" class="button"><?php echo esc_html__( 'Save', 'css-timeclock-addon' ); ?></button>
						</form>
					</td>
					<td class="css-tc-org__del">
						<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>">
							<?php $css_tc_hidden( 'delete', 'companies', (int) $company['id'] ); ?>
							<button type="submit" class="button-link button-link-delete"><?php echo esc_html__( 'Remove', 'css-timeclock-addon' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<td colspan="2">
					<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>" class="css-tc-org__row">
						<?php $css_tc_hidden( 'save', 'companies' ); ?>
						<input type="text" name="name" placeholder="<?php echo esc_attr__( 'New company, e.g. Carolina Sports & Spine', 'css-timeclock-addon' ); ?>" />
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Add company', 'css-timeclock-addon' ); ?></button>
					</form>
				</td>
			</tr>
		</tbody>
	</table>

	<h2><?php echo esc_html__( 'Locations', 'css-timeclock-addon' ); ?></h2>
	<p class="description"><?php echo esc_html__( 'Office network: the public IP addresses or CIDR ranges of that office, one per line. Any tablet or desk computer on that network clocks in at this location. Leave blank and employees pick the location themselves.', 'css-timeclock-addon' ); ?></p>
	<?php
	$css_tc_here     = css_tc_addon()->pins->client_ip();
	$css_tc_here_loc = '' !== $css_tc_here ? css_tc_addon()->organization->location_for_ip( $css_tc_here ) : 0;
	?>
	<p class="description css-tc-here-ip">
		<?php
		if ( '' === $css_tc_here ) {
			echo esc_html__( 'This computer’s address could not be read.', 'css-timeclock-addon' );
		} elseif ( $css_tc_here_loc ) {
			echo esc_html( sprintf( /* translators: 1: IP address, 2: location name */ __( 'This computer’s address is %1$s, which matches %2$s.', 'css-timeclock-addon' ), $css_tc_here, css_tc_addon()->organization->location_name( (int) $css_tc_here_loc ) ) );
		} else {
			echo esc_html( sprintf( /* translators: %s: IP address */ __( 'This computer’s address is %s. It does not match any location yet — from an office computer, paste this into that location’s office network.', 'css-timeclock-addon' ), $css_tc_here ) );
		}
		?>
	</p>
	<table class="widefat striped css-tc-org__table">
		<tbody>
			<?php foreach ( $css_tc_locations as $location ) : ?>
				<tr>
					<td>
						<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>" class="css-tc-org__row">
							<?php $css_tc_hidden( 'save', 'locations', (int) $location['id'] ); ?>
							<input type="text" name="name" value="<?php echo esc_attr( $location['name'] ); ?>" aria-label="<?php echo esc_attr__( 'Location name', 'css-timeclock-addon' ); ?>" />
							<textarea name="ips" rows="2" cols="28" placeholder="<?php echo esc_attr__( 'Office IPs, one per line', 'css-timeclock-addon' ); ?>" aria-label="<?php echo esc_attr__( 'Office network', 'css-timeclock-addon' ); ?>"><?php echo esc_textarea( isset( $location['ips'] ) ? (string) $location['ips'] : '' ); ?></textarea>
							<button type="submit" class="button"><?php echo esc_html__( 'Save', 'css-timeclock-addon' ); ?></button>
						</form>
					</td>
					<td class="css-tc-org__del">
						<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>">
							<?php $css_tc_hidden( 'delete', 'locations', (int) $location['id'] ); ?>
							<button type="submit" class="button-link button-link-delete"><?php echo esc_html__( 'Remove', 'css-timeclock-addon' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<td colspan="2">
					<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>" class="css-tc-org__row">
						<?php $css_tc_hidden( 'save', 'locations' ); ?>
						<input type="text" name="name" placeholder="<?php echo esc_attr__( 'New location, e.g. Raleigh', 'css-timeclock-addon' ); ?>" />
						<textarea name="ips" rows="2" cols="28" placeholder="<?php echo esc_attr__( 'Office IPs, one per line', 'css-timeclock-addon' ); ?>"></textarea>
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Add location', 'css-timeclock-addon' ); ?></button>
					</form>
				</td>
			</tr>
		</tbody>
	</table>

	<h2><?php echo esc_html__( 'Departments', 'css-timeclock-addon' ); ?></h2>
	<?php if ( empty( $css_tc_companies ) || empty( $css_tc_locations ) ) : ?>
		<p><?php echo esc_html__( 'Add at least one company and one location first.', 'css-timeclock-addon' ); ?></p>
	<?php else : ?>
		<table class="widefat striped css-tc-org__table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Department · Location · Company', 'css-timeclock-addon' ); ?></th>
					<th class="num"><?php echo esc_html__( 'Employees', 'css-timeclock-addon' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $css_tc_depts as $dept ) : ?>
					<tr>
						<td>
							<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>" class="css-tc-org__row">
								<?php $css_tc_hidden( 'save', 'departments', (int) $dept['id'] ); ?>
								<input type="text" name="name" value="<?php echo esc_attr( $dept['name'] ); ?>" aria-label="<?php echo esc_attr__( 'Department name', 'css-timeclock-addon' ); ?>" />
								<select name="location_id" aria-label="<?php echo esc_attr__( 'Location', 'css-timeclock-addon' ); ?>">
									<?php foreach ( $css_tc_locations as $location ) : ?>
										<option value="<?php echo esc_attr( (string) (int) $location['id'] ); ?>" <?php selected( (int) $dept['location_id'], (int) $location['id'] ); ?>><?php echo esc_html( $location['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<select name="company_id" aria-label="<?php echo esc_attr__( 'Company', 'css-timeclock-addon' ); ?>">
									<?php foreach ( $css_tc_companies as $company ) : ?>
										<option value="<?php echo esc_attr( (string) (int) $company['id'] ); ?>" <?php selected( (int) $dept['company_id'], (int) $company['id'] ); ?>><?php echo esc_html( $company['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button"><?php echo esc_html__( 'Save', 'css-timeclock-addon' ); ?></button>
							</form>
						</td>
						<td class="num"><?php echo esc_html( (string) $css_tc_org->assigned_user_count( (int) $dept['id'] ) ); ?></td>
						<td class="css-tc-org__del">
							<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>">
								<?php $css_tc_hidden( 'delete', 'departments', (int) $dept['id'] ); ?>
								<button type="submit" class="button-link button-link-delete"><?php echo esc_html__( 'Remove', 'css-timeclock-addon' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<td colspan="3">
						<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>" class="css-tc-org__row">
							<?php $css_tc_hidden( 'save', 'departments' ); ?>
							<input type="text" name="name" placeholder="<?php echo esc_attr__( 'New department, e.g. CSS', 'css-timeclock-addon' ); ?>" />
							<select name="location_id">
								<?php foreach ( $css_tc_locations as $location ) : ?>
									<option value="<?php echo esc_attr( (string) (int) $location['id'] ); ?>"><?php echo esc_html( $location['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<select name="company_id">
								<?php foreach ( $css_tc_companies as $company ) : ?>
									<option value="<?php echo esc_attr( (string) (int) $company['id'] ); ?>"><?php echo esc_html( $company['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="button button-primary"><?php echo esc_html__( 'Add department', 'css-timeclock-addon' ); ?></button>
						</form>
					</td>
				</tr>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( taxonomy_exists( 'department' ) ) : ?>
		<h2><?php echo esc_html__( 'Import from AIO departments', 'css-timeclock-addon' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Creates locations and departments from AIO department names written as Location-Department (for example Raleigh-CSS or Wilson-BFM/TRM). The part after the hyphen also becomes the company. Employees are assigned to their AIO department as home, and past shifts are labeled where their department name matches. Existing entries are reused, so it is safe to run again. Rename companies afterwards if needed.', 'css-timeclock-addon' ); ?></p>
		<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>">
			<?php $css_tc_hidden( 'import' ); ?>
			<button type="submit" class="button"><?php echo esc_html__( 'Import AIO departments', 'css-timeclock-addon' ); ?></button>
		</form>
	<?php endif; ?>
</div>
