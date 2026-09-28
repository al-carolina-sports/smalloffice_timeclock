<?php
/**
 * Reports → PTO & sick: summary, and one employee's used days.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed> $filters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_leave = css_tc_addon()->leave;
$css_tc_uid   = (int) $filters['employee'];
?>
<?php if ( $css_tc_uid > 0 && css_tc_addon()->employees->is_employee( $css_tc_uid ) ) : ?>
	<?php
	$css_tc_hire  = css_tc_addon()->holidays->hire_date( $css_tc_uid );
	$css_tc_year  = isset( $_GET['year'] ) ? sanitize_text_field( wp_unslash( $_GET['year'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$css_tc_cycle = '' !== $css_tc_hire ? Css_Tc_Leave::cycle( $css_tc_hire, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $css_tc_year ) && $css_tc_year >= $css_tc_hire ? $css_tc_year : max( $css_tc_leave->today(), $css_tc_hire ) ) : null;
	$css_tc_name  = css_tc_addon()->employees->display_name( $css_tc_uid );
	?>
	<h2 id="css-tc-leave-used" class="css-tc-report-h2">
		<?php echo esc_html( sprintf( /* translators: %s: employee */ __( 'Time off used — %s', 'css-timeclock-addon' ), $css_tc_name ) ); ?>
		<a class="button button-small" href="<?php echo esc_url( Css_Tc_Reports::url( array( 'report' => 'leave' ) ) ); ?>"><?php echo esc_html__( 'All employees', 'css-timeclock-addon' ); ?></a>
	</h2>
	<?php if ( ! $css_tc_cycle ) : ?>
		<p><a href="<?php echo esc_url( get_edit_user_link( $css_tc_uid ) . '#css-tc-employment' ); ?>"><?php echo esc_html__( 'Set a hire date to start PTO.', 'css-timeclock-addon' ); ?></a></p>
	<?php else : ?>
		<?php
		$css_tc_rows = $css_tc_leave->records( $css_tc_uid, $css_tc_cycle['start'], $css_tc_cycle['end'], array( 'approved', 'pending' ) );
		$css_tc_bal  = $css_tc_leave->balances( $css_tc_uid, $css_tc_cycle['start'] );
		$css_tc_prev = $css_tc_cycle['index'] > 0 ? Css_Tc_Leave::anniversary( $css_tc_hire, $css_tc_cycle['index'] - 1 ) : '';
		$css_tc_next = Css_Tc_Leave::add_days( $css_tc_cycle['end'], 1 );
		$css_tc_tot  = array();
		?>
		<p class="css-tc-leave-yearnav">
			<?php if ( '' !== $css_tc_prev ) : ?>
				<a href="<?php echo esc_url( Css_Tc_Reports::leave_used_url( $css_tc_uid, $css_tc_prev ) ); ?>">&larr; <?php echo esc_html__( 'Previous year', 'css-timeclock-addon' ); ?></a>
			<?php endif; ?>
			<strong><?php echo esc_html( sprintf( /* translators: 1: year number, 2: range */ __( 'Leave year %1$d: %2$s', 'css-timeclock-addon' ), $css_tc_cycle['index'] + 1, css_tc_leave_date_label( $css_tc_cycle['start'] ) . ' – ' . css_tc_leave_date_label( $css_tc_cycle['end'] ) ) ); ?></strong>
			<?php if ( $css_tc_next <= Css_Tc_Leave::add_days( $css_tc_leave->today(), 366 ) ) : ?>
				<a href="<?php echo esc_url( Css_Tc_Reports::leave_used_url( $css_tc_uid, $css_tc_next ) ); ?>"><?php echo esc_html__( 'Next year', 'css-timeclock-addon' ); ?> &rarr;</a>
			<?php endif; ?>
		</p>
		<ul class="css-tc-leave-bal">
			<?php foreach ( $css_tc_bal['banks'] as $css_tc_bank => $css_tc_t ) : ?>
				<li><strong><?php echo esc_html( 'pto' === $css_tc_bank ? __( 'PTO', 'css-timeclock-addon' ) : __( 'Sick', 'css-timeclock-addon' ) ); ?>:</strong>
					<?php echo esc_html( sprintf( /* translators: 1: total, 2: used, 3: pending, 4: remaining */ __( '%1$s total · %2$s used · %3$s pending · %4$s remaining', 'css-timeclock-addon' ), Css_Tc_Leave::hours( $css_tc_t['allowance'] + $css_tc_t['adjust'] ), Css_Tc_Leave::hours( $css_tc_t['used'] ), Css_Tc_Leave::hours( $css_tc_t['pending'] ), Css_Tc_Leave::hours( $css_tc_t['left'] ) ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<table class="widefat striped css-tc-report-table css-tc-report-table--narrow">
			<thead><tr>
				<th><?php echo esc_html__( 'Date', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Type', 'css-timeclock-addon' ); ?></th>
				<th class="num"><?php echo esc_html__( 'Hours', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Status', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Record', 'css-timeclock-addon' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( empty( $css_tc_rows ) ) : ?>
					<tr><td colspan="5"><?php echo esc_html__( 'No time off in this leave year.', 'css-timeclock-addon' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $css_tc_rows as $css_tc_r ) : ?>
					<?php
					if ( 'approved' === $css_tc_r['status'] ) {
						$css_tc_tot[ $css_tc_r['type'] ] = ( $css_tc_tot[ $css_tc_r['type'] ] ?? 0 ) + $css_tc_r['seconds'];
					}
					$css_tc_by = get_userdata( (int) ( 'manager' === $css_tc_r['source'] ? $css_tc_r['requested_by'] : $css_tc_r['decided_by'] ) );
					?>
					<tr class="<?php echo 'pending' === $css_tc_r['status'] ? 'is-pending' : ''; ?>">
						<td><a href="<?php echo esc_url( Css_Tc_Admin::timecards_url( array( 'employee' => $css_tc_uid, 'period' => (string) ( css_tc_addon()->pay_periods->period_for_date( $css_tc_r['date'] )['start'] ?? '' ) ) ) . '#day-' . $css_tc_r['date'] ); ?>"><?php echo esc_html( css_tc_leave_date_label( $css_tc_r['date'] ) ); ?></a></td>
						<td><span class="css-tc-leave-tag css-tc-leave-tag--<?php echo esc_attr( $css_tc_r['type'] ); ?>"><?php echo esc_html( Css_Tc_Leave::label( $css_tc_r['type'] ) ); ?></span></td>
						<td class="num"><?php echo esc_html( Css_Tc_Leave::hours( $css_tc_r['seconds'] ) ); ?></td>
						<td><?php echo esc_html( 'pending' === $css_tc_r['status'] ? __( 'Pending', 'css-timeclock-addon' ) : __( 'Approved', 'css-timeclock-addon' ) ); ?></td>
						<td>
							<?php
							if ( 'manager' === $css_tc_r['source'] ) {
								/* translators: 1: manager, 2: note */
								echo esc_html( sprintf( __( 'Added by %1$s, employee agreed: “%2$s”', 'css-timeclock-addon' ), $css_tc_by ? $css_tc_by->display_name : '?', $css_tc_r['note'] ) );
							} elseif ( 'approved' === $css_tc_r['status'] ) {
								/* translators: %s: manager */
								echo esc_html( sprintf( __( 'Requested by employee, approved by %s', 'css-timeclock-addon' ), $css_tc_by ? $css_tc_by->display_name : '?' ) );
							} else {
								echo esc_html__( 'Requested by employee', 'css-timeclock-addon' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<?php if ( ! empty( $css_tc_tot ) ) : ?>
				<tfoot><tr>
					<th colspan="5">
						<?php
						$css_tc_parts = array();
						foreach ( $css_tc_tot as $css_tc_type => $css_tc_sec ) {
							$css_tc_parts[] = Css_Tc_Leave::label( $css_tc_type ) . ' ' . Css_Tc_Leave::hours( $css_tc_sec );
						}
						echo esc_html( __( 'Approved:', 'css-timeclock-addon' ) . ' ' . implode( ' · ', $css_tc_parts ) );
						?>
					</th>
				</tr></tfoot>
			<?php endif; ?>
		</table>
	<?php endif; ?>
<?php endif; ?>

<?php include CSS_TC_ADDON_DIR . 'admin/views/report-leave-summary.php'; ?>
