<?php
/**
 * USOTC → Import employees tab: upload, preview, confirm, results.
 *
 * @package CssTimeclockAddon
 *
 * @var string $base_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_imp       = css_tc_addon()->import;
$css_tc_org       = css_tc_addon()->organization;
$css_tc_post_url  = admin_url( 'admin-post.php' );
$css_tc_msg       = isset( $_GET['css_tc_imp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['css_tc_imp_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$css_tc_err       = isset( $_GET['css_tc_imp_err'] ) ? sanitize_text_field( wp_unslash( $_GET['css_tc_imp_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$css_tc_pv_token  = isset( $_GET['css_tc_import'] ) ? sanitize_key( wp_unslash( $_GET['css_tc_import'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$css_tc_done      = isset( $_GET['css_tc_import_done'] ) ? sanitize_key( wp_unslash( $_GET['css_tc_import_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$css_tc_preview   = '' !== $css_tc_pv_token ? $css_tc_imp->load( $css_tc_pv_token ) : null;
$css_tc_results   = '' !== $css_tc_done ? $css_tc_imp->load( $css_tc_done ) : null;
$css_tc_roles     = $css_tc_imp->allowed_roles();
$css_tc_def_role  = $css_tc_imp->default_role();
$css_tc_status_lb = array(
	'create' => __( 'Will create', 'css-timeclock-addon' ),
	'update' => __( 'Will update', 'css-timeclock-addon' ),
	'skip'   => __( 'Will skip', 'css-timeclock-addon' ),
	'error'  => __( 'Error', 'css-timeclock-addon' ),
);
?>
<?php if ( '' !== $css_tc_msg ) : ?>
	<div class="notice notice-success"><p><?php echo esc_html( $css_tc_msg ); ?></p></div>
<?php endif; ?>
<?php if ( '' !== $css_tc_err ) : ?>
	<div class="notice notice-error"><p><?php echo esc_html( $css_tc_err ); ?></p></div>
<?php endif; ?>
<?php if ( '' !== $css_tc_pv_token && ! $css_tc_preview ) : ?>
	<div class="notice notice-warning"><p><?php echo esc_html__( 'That preview expired (previews last 15 minutes). Upload the file again.', 'css-timeclock-addon' ); ?></p></div>
<?php endif; ?>
<?php if ( '' !== $css_tc_done && ! $css_tc_results ) : ?>
	<div class="notice notice-warning"><p><?php echo esc_html__( 'Those results expired (they are kept for 15 minutes). The import itself is not undone.', 'css-timeclock-addon' ); ?></p></div>
<?php endif; ?>

<?php if ( $css_tc_results ) : ?>
	<?php
	$css_tc_t         = $css_tc_results['tally'];
	$css_tc_has_pins  = false;
	foreach ( $css_tc_results['results'] as $css_tc_r ) {
		if ( '' !== $css_tc_r['pin_new'] ) {
			$css_tc_has_pins = true;
			break;
		}
	}
	$css_tc_dl = wp_nonce_url(
		add_query_arg(
			array(
				'action'             => Css_Tc_Import::ACTION_RESULTS,
				'css_tc_import_done' => $css_tc_done,
			),
			$css_tc_post_url
		),
		Css_Tc_Import::NONCE
	);
	?>
	<h2><?php echo esc_html__( 'Import finished', 'css-timeclock-addon' ); ?></h2>
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: created, 2: updated, 3: skipped, 4: errors */
				__( '%1$d created, %2$d updated, %3$d skipped, %4$d with errors.', 'css-timeclock-addon' ),
				(int) $css_tc_t['created'],
				(int) $css_tc_t['updated'],
				(int) $css_tc_t['skipped'],
				(int) $css_tc_t['error']
			)
		);
		?>
	</p>
	<?php if ( $css_tc_has_pins ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php echo esc_html__( 'The PINs below were generated for you. They are shown here and in the results file only for the next 15 minutes. After that a manager can still reveal a PIN on the Employee PINs tab.', 'css-timeclock-addon' ); ?>
		</p></div>
	<?php endif; ?>
	<p><a class="button button-primary" href="<?php echo esc_url( $css_tc_dl ); ?>"><?php echo esc_html__( 'Download results (CSV)', 'css-timeclock-addon' ); ?></a></p>
	<table class="widefat striped" style="max-width:1100px">
		<thead><tr>
			<th><?php echo esc_html__( 'Row', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'Result', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'Name', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'Email', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'PIN', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'Notes', 'css-timeclock-addon' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $css_tc_results['results'] as $css_tc_r ) : ?>
			<tr>
				<td><?php echo esc_html( (string) (int) $css_tc_r['line'] ); ?></td>
				<td><?php echo esc_html( ucfirst( (string) $css_tc_r['result'] ) ); ?></td>
				<td><?php echo esc_html( trim( $css_tc_r['first_name'] . ' ' . $css_tc_r['last_name'] ) ); ?></td>
				<td><?php echo esc_html( $css_tc_r['email'] ); ?></td>
				<td><code><?php echo esc_html( $css_tc_r['pin_new'] ); ?></code></td>
				<td><?php echo esc_html( trim( $css_tc_r['message'] . ' ' . implode( ' ', (array) $css_tc_r['warnings'] ) ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><a href="<?php echo esc_url( $base_url . '&tab=import' ); ?>">&larr; <?php echo esc_html__( 'Import another file', 'css-timeclock-addon' ); ?></a></p>

<?php elseif ( $css_tc_preview ) : ?>
	<?php
	$css_tc_c    = Css_Tc_Import::counts( $css_tc_preview['rows'] );
	$css_tc_o    = $css_tc_preview['opts'];
	$css_tc_dept = ! empty( $css_tc_o['dept'] ) ? $css_tc_org->label( (int) $css_tc_o['dept'] ) : '';
	?>
	<h2><?php echo esc_html__( 'Check before importing', 'css-timeclock-addon' ); ?></h2>
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: file name, 2: create, 3: update, 4: skip, 5: error */
				__( '%1$s: %2$d to create, %3$d to update, %4$d to skip, %5$d with errors. Nothing has been saved yet.', 'css-timeclock-addon' ),
				$css_tc_o['file'],
				(int) $css_tc_c['create'],
				(int) $css_tc_c['update'],
				(int) $css_tc_c['skip'],
				(int) $css_tc_c['error']
			)
		);
		?>
	</p>
	<ul class="ul-disc">
		<li><?php echo esc_html( sprintf( /* translators: %s: role */ __( 'New accounts get the role: %s', 'css-timeclock-addon' ), isset( $css_tc_roles[ $css_tc_o['role'] ] ) ? $css_tc_roles[ $css_tc_o['role'] ] : $css_tc_o['role'] ) ); ?></li>
		<li><?php echo esc_html( '' !== $css_tc_dept ? sprintf( /* translators: %s: department */ __( 'Everyone without a department is assigned: %s (home)', 'css-timeclock-addon' ), $css_tc_dept ) : __( 'No department is assigned by this import.', 'css-timeclock-addon' ) ); ?></li>
		<li><?php echo esc_html( ! empty( $css_tc_o['update_existing'] ) ? __( 'Existing accounts (same email) are updated with the cells you filled in.', 'css-timeclock-addon' ) : __( 'Existing accounts (same email) are skipped.', 'css-timeclock-addon' ) ); ?></li>
		<li><?php echo esc_html( ! empty( $css_tc_o['send_email'] ) ? __( 'New people get WordPress’s welcome email with a link to set a password.', 'css-timeclock-addon' ) : __( 'No email is sent.', 'css-timeclock-addon' ) ); ?></li>
	</ul>
	<?php if ( ! empty( $css_tc_preview['ignored'] ) ) : ?>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %s: column names */ __( 'These columns were not recognised and are ignored: %s', 'css-timeclock-addon' ), implode( ', ', array_map( 'sanitize_text_field', $css_tc_preview['ignored'] ) ) ) ); ?></p>
	<?php endif; ?>

	<table class="widefat striped" style="max-width:1100px">
		<thead><tr>
			<th><?php echo esc_html__( 'Row', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'What happens', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'Name', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'Email', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'Hire date', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'PIN', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'Password', 'css-timeclock-addon' ); ?></th>
			<th><?php echo esc_html__( 'Notes', 'css-timeclock-addon' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $css_tc_preview['rows'] as $css_tc_r ) : ?>
			<tr>
				<td><?php echo esc_html( (string) (int) $css_tc_r['line'] ); ?></td>
				<td><strong><?php echo esc_html( $css_tc_status_lb[ $css_tc_r['status'] ] ); ?></strong></td>
				<td><?php echo esc_html( trim( $css_tc_r['first_name'] . ' ' . $css_tc_r['last_name'] ) ); ?></td>
				<td><?php echo esc_html( $css_tc_r['email'] ); ?></td>
				<td><?php echo esc_html( $css_tc_r['hire_date'] ); ?></td>
				<td>
					<?php
					if ( 'create' === $css_tc_r['status'] || 'update' === $css_tc_r['status'] ) {
						echo esc_html( '' !== $css_tc_r['pin'] ? __( 'From file', 'css-timeclock-addon' ) : ( $css_tc_r['pin_generate'] ? __( 'Generated', 'css-timeclock-addon' ) : __( 'Unchanged', 'css-timeclock-addon' ) ) );
					}
					?>
				</td>
				<td>
					<?php
					if ( 'create' === $css_tc_r['status'] ) {
						echo esc_html( '' !== $css_tc_r['password'] ? __( 'From file', 'css-timeclock-addon' ) : __( 'Random', 'css-timeclock-addon' ) );
					} elseif ( 'update' === $css_tc_r['status'] ) {
						echo esc_html( '' !== $css_tc_r['password'] ? __( 'Will change', 'css-timeclock-addon' ) : __( 'Unchanged', 'css-timeclock-addon' ) );
					}
					?>
				</td>
				<td><?php echo esc_html( $css_tc_r['message'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>" style="margin-top:16px">
		<?php wp_nonce_field( Css_Tc_Import::NONCE ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( Css_Tc_Import::ACTION_COMMIT ); ?>" />
		<input type="hidden" name="css_tc_import_token" value="<?php echo esc_attr( $css_tc_pv_token ); ?>" />
		<?php if ( $css_tc_c['create'] + $css_tc_c['update'] > 0 ) : ?>
			<button type="submit" class="button button-primary">
				<?php echo esc_html( sprintf( /* translators: %d: number of people */ _n( 'Import %d person', 'Import %d people', $css_tc_c['create'] + $css_tc_c['update'], 'css-timeclock-addon' ), $css_tc_c['create'] + $css_tc_c['update'] ) ); ?>
			</button>
			<?php if ( $css_tc_c['error'] > 0 ) : ?>
				<span class="description"><?php echo esc_html__( 'Rows with errors are skipped. Fix them in the file and import that file again; people who already have accounts are skipped, so it is safe to repeat.', 'css-timeclock-addon' ); ?></span>
			<?php endif; ?>
		<?php else : ?>
			<p><?php echo esc_html__( 'Nothing to import. Fix the errors in your file and upload it again.', 'css-timeclock-addon' ); ?></p>
		<?php endif; ?>
		<a class="button" href="<?php echo esc_url( $base_url . '&tab=import' ); ?>"><?php echo esc_html__( 'Cancel', 'css-timeclock-addon' ); ?></a>
	</form>

<?php else : ?>
	<?php
	$css_tc_tpl = wp_nonce_url(
		add_query_arg( 'action', Css_Tc_Import::ACTION_TEMPLATE, $css_tc_post_url ),
		Css_Tc_Import::NONCE
	);
	$css_tc_log = $css_tc_imp->recent_log();
	?>
	<h2><?php echo esc_html__( 'Import employees from a CSV file', 'css-timeclock-addon' ); ?></h2>
	<p>
		<?php echo esc_html__( 'Each row becomes a WordPress user and a time clock employee. You see a preview first; nothing is saved until you confirm.', 'css-timeclock-addon' ); ?>
		<a href="<?php echo esc_url( $css_tc_tpl ); ?>"><?php echo esc_html__( 'Download the template', 'css-timeclock-addon' ); ?></a>
	</p>
	<table class="widefat striped" style="max-width:760px">
		<thead><tr><th><?php echo esc_html__( 'Column', 'css-timeclock-addon' ); ?></th><th><?php echo esc_html__( 'Needed?', 'css-timeclock-addon' ); ?></th><th><?php echo esc_html__( 'If left blank', 'css-timeclock-addon' ); ?></th></tr></thead>
		<tbody>
			<tr><td><code>first_name</code>, <code>last_name</code>, <code>email</code></td><td><?php echo esc_html__( 'Required', 'css-timeclock-addon' ); ?></td><td>—</td></tr>
			<tr><td><code>username</code></td><td><?php echo esc_html__( 'Optional', 'css-timeclock-addon' ); ?></td><td><?php echo esc_html__( 'The email address is the username.', 'css-timeclock-addon' ); ?></td></tr>
			<tr><td><code>password</code></td><td><?php echo esc_html__( 'Optional', 'css-timeclock-addon' ); ?></td><td><?php echo esc_html( sprintf( /* translators: %d: minimum length */ __( 'A random password nobody knows (they can use “Lost your password?”). At least %d characters if given.', 'css-timeclock-addon' ), Css_Tc_Import::MIN_PASSWORD ) ); ?></td></tr>
			<tr><td><code>role</code></td><td><?php echo esc_html__( 'Optional', 'css-timeclock-addon' ); ?></td><td><?php echo esc_html__( 'The role chosen below. Administrator is never allowed.', 'css-timeclock-addon' ); ?></td></tr>
			<tr><td><code>hire_date</code></td><td><?php echo esc_html__( 'Optional', 'css-timeclock-addon' ); ?></td><td><?php echo esc_html__( 'No hire date. PTO starts once you set one on the profile. Use 2026-03-02 or 3/2/2026.', 'css-timeclock-addon' ); ?></td></tr>
			<tr><td><code>pin</code> (or <code>badge_no</code>)</td><td><?php echo esc_html__( 'Optional', 'css-timeclock-addon' ); ?></td><td><?php echo esc_html__( 'A unique PIN is generated. Format this column as Text in your spreadsheet so a leading zero is not dropped.', 'css-timeclock-addon' ); ?></td></tr>
		</tbody>
	</table>
	<p class="description" style="max-width:760px">
		<?php echo esc_html__( 'The file holds passwords in plain text, so delete your copy after importing. This site does not keep the file; the preview is held encrypted for 15 minutes.', 'css-timeclock-addon' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( $css_tc_post_url ); ?>" enctype="multipart/form-data">
		<?php wp_nonce_field( Css_Tc_Import::NONCE ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( Css_Tc_Import::ACTION_PREVIEW ); ?>" />
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="css-tc-import-file"><?php echo esc_html__( 'CSV file', 'css-timeclock-addon' ); ?></label></th>
				<td><input type="file" id="css-tc-import-file" name="css_tc_import_file" accept=".csv,text/csv" required /></td>
			</tr>
			<tr>
				<th scope="row"><label for="css-tc-import-role"><?php echo esc_html__( 'Role for new accounts', 'css-timeclock-addon' ); ?></label></th>
				<td>
					<select id="css-tc-import-role" name="css_tc_import_role">
						<?php foreach ( $css_tc_roles as $css_tc_slug => $css_tc_label ) : ?>
							<option value="<?php echo esc_attr( $css_tc_slug ); ?>" <?php selected( $css_tc_def_role, $css_tc_slug ); ?>><?php echo esc_html( $css_tc_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php echo esc_html__( 'Used when a row has no role. Only time clock employee roles are offered.', 'css-timeclock-addon' ); ?></p>
				</td>
			</tr>
			<?php if ( $css_tc_org->enabled() ) : ?>
			<tr>
				<th scope="row"><label for="css-tc-import-dept"><?php echo esc_html__( 'Department for everyone', 'css-timeclock-addon' ); ?></label></th>
				<td>
					<select id="css-tc-import-dept" name="css_tc_import_dept">
						<option value="0"><?php echo esc_html__( 'None. I will assign departments later.', 'css-timeclock-addon' ); ?></option>
						<?php foreach ( $css_tc_org->departments() as $css_tc_d ) : ?>
							<?php $css_tc_co = $css_tc_org->company_name( (int) $css_tc_d['company_id'] ); ?>
							<option value="<?php echo esc_attr( (string) (int) $css_tc_d['id'] ); ?>"><?php echo esc_html( ( '' !== $css_tc_co ? $css_tc_co . ' · ' : '' ) . $css_tc_org->label( (int) $css_tc_d['id'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php echo esc_html__( 'Assigned as the home department to every person in the file who has none yet. For a mixed file, import it in parts.', 'css-timeclock-addon' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Options', 'css-timeclock-addon' ); ?></th>
				<td>
					<label><input type="checkbox" name="css_tc_import_update" value="1" /> <?php echo esc_html__( 'Update people who already have an account (matched by email). Only cells you filled in change; accounts that are not time clock employees are never touched.', 'css-timeclock-addon' ); ?></label><br />
					<label><input type="checkbox" name="css_tc_import_email" value="1" /> <?php echo esc_html__( 'Send new people WordPress’s welcome email (a link to set their own password)', 'css-timeclock-addon' ); ?></label>
				</td>
			</tr>
		</table>
		<p class="submit"><button type="submit" class="button button-primary"><?php echo esc_html__( 'Preview import', 'css-timeclock-addon' ); ?></button></p>
	</form>

	<?php if ( ! empty( $css_tc_log ) ) : ?>
		<h3><?php echo esc_html__( 'Recent imports', 'css-timeclock-addon' ); ?></h3>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr>
				<th><?php echo esc_html__( 'When', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'By', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'File', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Created / updated / skipped / errors', 'css-timeclock-addon' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $css_tc_log as $css_tc_l ) : ?>
				<?php $css_tc_by = get_userdata( (int) $css_tc_l['by'] ); ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'M j, Y g:i a', (int) $css_tc_l['at'] ) ); ?></td>
					<td><?php echo esc_html( $css_tc_by ? $css_tc_by->display_name : '—' ); ?></td>
					<td><?php echo esc_html( (string) $css_tc_l['file'] ); ?></td>
					<td><?php echo esc_html( (int) $css_tc_l['created'] . ' / ' . (int) $css_tc_l['updated'] . ' / ' . (int) $css_tc_l['skipped'] . ' / ' . (int) $css_tc_l['error'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
<?php endif; ?>
