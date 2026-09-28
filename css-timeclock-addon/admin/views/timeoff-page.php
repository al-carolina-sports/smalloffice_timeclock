<?php
/**
 * USOTC → Time off (managers).
 *
 * @package CssTimeclockAddon
 *
 * @var Css_Tc_Leave                     $leave
 * @var string                           $today
 * @var array{message:string,error:bool}|null $notice
 * @var array<int,array<string,mixed>>   $pending
 * @var array<int,array<string,mixed>>   $recent
 * @var WP_User[]                        $employees
 * @var string                           $out_from
 * @var string                           $out_to
 * @var array<int,array<string,mixed>>   $out_rows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_emp  = css_tc_addon()->employees;
$css_tc_post = admin_url( 'admin-post.php' );
$css_tc_who  = static function ( $id ) {
	$u = get_userdata( (int) $id );
	return $u ? $u->display_name : '—';
};
$css_tc_status = array(
	'pending'   => __( 'Pending', 'css-timeclock-addon' ),
	'approved'  => __( 'Approved', 'css-timeclock-addon' ),
	'denied'    => __( 'Denied', 'css-timeclock-addon' ),
	'cancelled' => __( 'Cancelled', 'css-timeclock-addon' ),
);
?>
<div class="wrap css-tc-admin css-tc-timeoff">
	<h1><?php echo esc_html__( 'Time off', 'css-timeclock-addon' ); ?></h1>
	<?php if ( $notice ) : ?>
		<div class="notice <?php echo $notice['error'] ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $leave->any_enabled() ) : ?>
		<div class="notice notice-info inline"><p>
			<?php echo esc_html__( 'PTO and sick time are turned off.', 'css-timeclock-addon' ); ?>
			<a href="<?php echo esc_url( Css_Tc_Admin::settings_url( 'leave' ) ); ?>"><?php echo esc_html__( 'Turn them on in TC-Config → PTO & sick.', 'css-timeclock-addon' ); ?></a>
		</p></div>
	<?php else : ?>
		<p class="css-tc-lead">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: days */
					__( 'Employees request time off from My Time Clock. PTO needs %d days\' notice; sick time does not. Balances are on the Reports page.', 'css-timeclock-addon' ),
					$leave->notice_days()
				)
			);
			?>
			<a href="<?php echo esc_url( Css_Tc_Reports::url( array( 'report' => 'leave' ) ) ); ?>"><?php echo esc_html__( 'PTO summary by employee', 'css-timeclock-addon' ); ?></a>
		</p>

		<h2><?php echo esc_html__( 'Waiting for a decision', 'css-timeclock-addon' ); ?> <span class="count">(<?php echo esc_html( (string) count( $pending ) ); ?>)</span></h2>
		<?php if ( empty( $pending ) ) : ?>
			<p class="description"><?php echo esc_html__( 'No requests waiting.', 'css-timeclock-addon' ); ?></p>
		<?php else : ?>
			<table class="widefat striped css-tc-leave-table">
				<thead><tr>
					<th><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Days', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Type', 'css-timeclock-addon' ); ?></th>
					<th class="num"><?php echo esc_html__( 'Hours', 'css-timeclock-addon' ); ?></th>
					<th class="num"><?php echo esc_html__( 'Left if approved', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Note', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Decision', 'css-timeclock-addon' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $pending as $g ) : ?>
						<?php
						$css_tc_bal  = $leave->balances( (int) $g['user_id'], (string) min( array_keys( $g['days'] ) ) );
						$css_tc_bank = $leave->bank_for( $g['type'] );
						$css_tc_left = isset( $css_tc_bal['banks'][ $css_tc_bank ] ) ? $css_tc_bal['banks'][ $css_tc_bank ]['left'] : 0;
						?>
						<tr>
							<td><strong><?php echo esc_html( $css_tc_emp->display_name( (int) $g['user_id'] ) ); ?></strong>
								<div class="description"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'asked %s', 'css-timeclock-addon' ), wp_date( get_option( 'date_format' ), (int) $g['requested_at'] ) ) ); ?></div></td>
							<td><?php echo esc_html( Css_Tc_Leave::describe_days( array_keys( $g['days'] ) ) ); ?></td>
							<td><span class="css-tc-leave-tag css-tc-leave-tag--<?php echo esc_attr( $g['type'] ); ?>"><?php echo esc_html( Css_Tc_Leave::label( $g['type'] ) ); ?></span></td>
							<td class="num"><?php echo esc_html( Css_Tc_Leave::hours( (int) $g['seconds'] ) ); ?></td>
							<td class="num<?php echo $css_tc_left < 0 ? ' is-attention' : ''; ?>"><?php echo esc_html( Css_Tc_Leave::hours( $css_tc_left ) ); ?></td>
							<td><?php echo esc_html( '' !== $g['note'] ? $g['note'] : '—' ); ?></td>
							<td class="css-tc-leave-decide">
								<form method="post" action="<?php echo esc_url( $css_tc_post ); ?>">
									<input type="hidden" name="action" value="css_tc_leave_decide" />
									<input type="hidden" name="group" value="<?php echo esc_attr( $g['group'] ); ?>" />
									<?php wp_nonce_field( Css_Tc_Leave_Ui::ADMIN_NONCE ); ?>
									<button type="submit" name="decision" value="approve" class="button button-primary"><?php echo esc_html__( 'Approve', 'css-timeclock-addon' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( $css_tc_post ); ?>" class="css-tc-leave-deny">
									<input type="hidden" name="action" value="css_tc_leave_decide" />
									<input type="hidden" name="group" value="<?php echo esc_attr( $g['group'] ); ?>" />
									<?php wp_nonce_field( Css_Tc_Leave_Ui::ADMIN_NONCE ); ?>
									<input type="text" name="reason" required maxlength="300" placeholder="<?php echo esc_attr__( 'Reason to deny', 'css-timeclock-addon' ); ?>" />
									<button type="submit" name="decision" value="deny" class="button"><?php echo esc_html__( 'Deny', 'css-timeclock-addon' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php echo esc_html__( 'Add time off for an employee', 'css-timeclock-addon' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'For a missed day or hours. Only with the employee\'s agreement. It is approved right away, the advance-notice rule does not apply, and the balance and introductory period still do.', 'css-timeclock-addon' ); ?></p>
		<form method="post" action="<?php echo esc_url( $css_tc_post ); ?>" class="css-tc-leave-add">
			<input type="hidden" name="action" value="css_tc_leave_add" />
			<?php wp_nonce_field( Css_Tc_Leave_Ui::ADMIN_NONCE ); ?>
			<p>
				<label><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?>
					<select name="user_id" required>
						<option value=""><?php echo esc_html__( 'Choose…', 'css-timeclock-addon' ); ?></option>
						<?php foreach ( $employees as $u ) : ?>
							<option value="<?php echo esc_attr( (string) $u->ID ); ?>"><?php echo esc_html( $css_tc_emp->display_name( (int) $u->ID ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php echo esc_html__( 'Type', 'css-timeclock-addon' ); ?>
					<select name="type">
						<?php foreach ( Css_Tc_Leave::TYPES as $t ) : ?>
							<?php if ( $leave->type_enabled( $t ) ) : ?>
								<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( Css_Tc_Leave::label( $t ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php echo esc_html__( 'From', 'css-timeclock-addon' ); ?> <input type="date" name="from" required /></label>
				<label><?php echo esc_html__( 'To', 'css-timeclock-addon' ); ?> <input type="date" name="to" /></label>
				<label><?php echo esc_html__( 'Hours per day', 'css-timeclock-addon' ); ?> <input type="number" name="hours" min="0.25" max="24" step="0.25" class="small-text" value="<?php echo esc_attr( (string) round( $leave->day_seconds() / 3600, 2 ) ); ?>" required /></label>
				<label><input type="checkbox" name="skip_weekends" value="1" checked /> <?php echo esc_html__( 'Skip weekends', 'css-timeclock-addon' ); ?></label>
			</p>
			<p>
				<label><input type="checkbox" name="agreed" value="1" required /> <strong><?php echo esc_html__( 'Employee agreed', 'css-timeclock-addon' ); ?></strong></label>
				<input type="text" name="note" class="regular-text" maxlength="500" required placeholder="<?php echo esc_attr__( 'How they agreed, e.g. "Talked to Jane 10/2, she asked to use PTO for the missed afternoon"', 'css-timeclock-addon' ); ?>" style="width:min(640px,100%)" />
				<button type="submit" class="button button-primary"><?php echo esc_html__( 'Add time off', 'css-timeclock-addon' ); ?></button>
			</p>
		</form>

		<h2><?php echo esc_html__( 'Who\'s out', 'css-timeclock-addon' ); ?></h2>
		<?php
		$css_tc_by_day = array();
		foreach ( $out_rows as $r ) {
			$css_tc_by_day[ $r['date'] ][] = $r;
		}
		$css_tc_hol = css_tc_addon()->holidays->between( $out_from, $out_to );
		?>
		<div class="css-tc-out-cal">
			<?php foreach ( array( __( 'Mon', 'css-timeclock-addon' ), __( 'Tue', 'css-timeclock-addon' ), __( 'Wed', 'css-timeclock-addon' ), __( 'Thu', 'css-timeclock-addon' ), __( 'Fri', 'css-timeclock-addon' ), __( 'Sat', 'css-timeclock-addon' ), __( 'Sun', 'css-timeclock-addon' ) ) as $css_tc_d ) : ?>
				<div class="css-tc-out-cal__dow"><?php echo esc_html( $css_tc_d ); ?></div>
			<?php endforeach; ?>
			<?php for ( $css_tc_i = 0; $css_tc_i < 35; $css_tc_i++ ) : ?>
				<?php $css_tc_date = Css_Tc_Leave::add_days( $out_from, $css_tc_i ); ?>
				<div class="css-tc-out-cal__day<?php echo $css_tc_date === $today ? ' is-today' : ''; ?><?php echo $css_tc_date < $today ? ' is-past' : ''; ?>">
					<span class="css-tc-out-cal__num"><?php echo esc_html( wp_date( 'M j', strtotime( $css_tc_date . ' 12:00:00' ) ) ); ?></span>
					<?php if ( isset( $css_tc_hol[ $css_tc_date ] ) ) : ?>
						<span class="css-tc-out-cal__hol"><?php echo esc_html( implode( ', ', $css_tc_hol[ $css_tc_date ] ) ); ?></span>
					<?php endif; ?>
					<?php foreach ( $css_tc_by_day[ $css_tc_date ] ?? array() as $r ) : ?>
						<span class="css-tc-leave-tag css-tc-leave-tag--<?php echo esc_attr( $r['type'] ); ?><?php echo 'pending' === $r['status'] ? ' is-pending' : ''; ?>" title="<?php echo esc_attr( Css_Tc_Leave::label( $r['type'] ) . ' ' . Css_Tc_Leave::hours( $r['seconds'] ) . ( 'pending' === $r['status'] ? ' · ' . __( 'pending', 'css-timeclock-addon' ) : '' ) ); ?>">
							<?php echo esc_html( $css_tc_emp->display_name( (int) $r['user_id'] ) ); ?>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endfor; ?>
		</div>
		<p class="description"><?php echo esc_html__( 'Dashed outline = pending request.', 'css-timeclock-addon' ); ?></p>

		<h2><?php echo esc_html__( 'Recent decisions', 'css-timeclock-addon' ); ?></h2>
		<?php if ( empty( $recent ) ) : ?>
			<p class="description"><?php echo esc_html__( 'Nothing yet.', 'css-timeclock-addon' ); ?></p>
		<?php else : ?>
			<table class="widefat striped css-tc-leave-table">
				<thead><tr>
					<th><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Days', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Type', 'css-timeclock-addon' ); ?></th>
					<th class="num"><?php echo esc_html__( 'Hours', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Record', 'css-timeclock-addon' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $recent as $g ) : ?>
						<tr>
							<td><?php echo esc_html( $css_tc_emp->display_name( (int) $g['user_id'] ) ); ?></td>
							<td><?php echo esc_html( Css_Tc_Leave::describe_days( array_keys( $g['days'] ) ) ); ?></td>
							<td><span class="css-tc-leave-tag css-tc-leave-tag--<?php echo esc_attr( $g['type'] ); ?>"><?php echo esc_html( Css_Tc_Leave::label( $g['type'] ) ); ?></span></td>
							<td class="num"><?php echo esc_html( Css_Tc_Leave::hours( (int) $g['seconds'] ) ); ?></td>
							<td><?php echo esc_html( $css_tc_status[ $g['status'] ] ?? $g['status'] ); ?></td>
							<td class="css-tc-leave-record">
								<?php if ( 'manager' === $g['source'] ) : ?>
									<?php echo esc_html( sprintf( /* translators: 1: manager, 2: note */ __( 'Added by %1$s, employee agreed: “%2$s”', 'css-timeclock-addon' ), $css_tc_who( $g['requested_by'] ), $g['note'] ) ); ?>
								<?php else : ?>
									<?php echo esc_html( sprintf( /* translators: %s: person */ __( 'Requested by employee; %s', 'css-timeclock-addon' ), strtolower( $css_tc_status[ $g['status'] ] ?? '' ) . ' ' . __( 'by', 'css-timeclock-addon' ) . ' ' . $css_tc_who( $g['decided_by'] ) ) ); ?>
									<?php if ( '' !== $g['note'] ) : ?>
										<br /><span class="description"><?php echo esc_html( '“' . $g['note'] . '”' ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( '' !== $g['reason'] ) : ?>
									<br /><span class="description"><?php echo esc_html( __( 'Reason:', 'css-timeclock-addon' ) . ' ' . $g['reason'] ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'approved' === $g['status'] ) : ?>
									<form method="post" action="<?php echo esc_url( $css_tc_post ); ?>" class="css-tc-leave-deny">
										<input type="hidden" name="action" value="css_tc_leave_decide" />
										<input type="hidden" name="group" value="<?php echo esc_attr( $g['group'] ); ?>" />
										<?php wp_nonce_field( Css_Tc_Leave_Ui::ADMIN_NONCE ); ?>
										<input type="text" name="reason" required maxlength="300" placeholder="<?php echo esc_attr__( 'Reason to cancel', 'css-timeclock-addon' ); ?>" />
										<button type="submit" name="decision" value="cancel" class="button button-link-delete"><?php echo esc_html__( 'Cancel', 'css-timeclock-addon' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>
</div>
