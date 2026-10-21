<?php
/**
 * Time off on the user profile and on USOTC → Time off.
 *
 * Profile (another employee, manager): allowances and adjustments.
 * Time off page: those fields plus the hire date.
 * Own profile and My Time Clock: balances only.
 *
 * @package CssTimeclockAddon
 *
 * @var WP_User                         $user
 * @var Css_Tc_Leave                    $leave
 * @var array<string,string>            $overrides
 * @var array<string,mixed>             $balances
 * @var array<int,array<string,mixed>>  $adjustments
 * @var array<string,mixed>             $settings
 * @var bool                            $readonly
 * @var bool                            $show_hire_input
 * @var string                          $hire
 * @var array<int,array<string,mixed>>  $audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$readonly        = ! empty( $readonly );
$show_hire_input = ! empty( $show_hire_input );
$hire            = isset( $hire ) ? (string) $hire : (string) ( $balances['hire'] ?? '' );
$audit           = isset( $audit ) && is_array( $audit ) ? $audit : array();

$css_tc_ls  = array_merge( Css_Tc_Leave::default_settings(), $settings );
$css_tc_day = $leave->day_seconds();
$css_tc_fmt = static function ( $seconds ) use ( $css_tc_day ) {
	$days = round( $seconds / $css_tc_day, 2 );
	return Css_Tc_Leave::hours( $seconds ) . ' (' . rtrim( rtrim( number_format( $days, 2, '.', '' ), '0' ), '.' ) . ' ' . _n( 'day', 'days', (int) ceil( abs( $days ) ), 'css-timeclock-addon' ) . ')';
};
$css_tc_field = static function ( $key, $placeholder ) use ( $overrides ) {
	printf(
		'<input type="number" min="0" max="2000" step="0.25" class="small-text" name="css_tc_leave_%1$s" value="%2$s" placeholder="%3$s" />',
		esc_attr( $key ),
		esc_attr( (string) ( $overrides[ $key ] ?? '' ) ),
		esc_attr( (string) $placeholder )
	);
};
$css_tc_audit_label = static function ( $field ) {
	$labels = array(
		'hire'        => __( 'Hire date', 'css-timeclock-addon' ),
		'pto_year'    => __( 'PTO per year', 'css-timeclock-addon' ),
		'pto_first'   => __( 'First-year PTO', 'css-timeclock-addon' ),
		'sick_year'   => __( 'Sick per year', 'css-timeclock-addon' ),
		'sick_first'  => __( 'First-year sick', 'css-timeclock-addon' ),
		'adjust_pto'  => __( 'PTO balance', 'css-timeclock-addon' ),
		'adjust_sick' => __( 'Sick balance', 'css-timeclock-addon' ),
	);
	return $labels[ $field ] ?? $field;
};
$css_tc_audit_value = static function ( $field, $value ) {
	$value = (string) $value;
	if ( 'hire' === $field ) {
		return '' === $value ? __( 'not set', 'css-timeclock-addon' ) : $value;
	}
	if ( 0 === strpos( (string) $field, 'adjust_' ) ) {
		return $value;
	}
	return '' === $value ? __( 'default', 'css-timeclock-addon' ) : $value;
};
?>
<h2 id="css-tc-leave">
	<?php
	if ( $show_hire_input ) {
		echo esc_html(
			sprintf(
				/* translators: %s: employee */
				__( 'Time off for %s', 'css-timeclock-addon' ),
				css_tc_addon()->employees->display_name( (int) $user->ID )
			)
		);
	} else {
		echo esc_html__( 'Time off', 'css-timeclock-addon' );
	}
	?>
</h2>
<?php if ( $readonly ) : ?>
	<p class="description"><?php echo esc_html__( 'Balances are read-only. A manager changes allowances, adjustments, and the hire date.', 'css-timeclock-addon' ); ?></p>
<?php elseif ( ! $show_hire_input ) : ?>
	<input type="hidden" name="css_tc_leave_present" value="1" />
	<?php wp_nonce_field( Css_Tc_Leave_Ui::PROFILE_NONCE, 'css_tc_leave_nonce' ); ?>
<?php endif; ?>
<table class="form-table" role="presentation">
	<?php if ( $show_hire_input || $readonly ) : ?>
		<tr>
			<th scope="row"><label for="css_tc_hire_date"><?php echo esc_html__( 'Hire date', 'css-timeclock-addon' ); ?></label></th>
			<td>
				<?php if ( $show_hire_input ) : ?>
					<input type="date" name="css_tc_hire_date" id="css_tc_hire_date" value="<?php echo esc_attr( $hire ); ?>" />
					<p class="description"><?php echo esc_html__( 'Leave blank to clear it. PTO and sick time start from this date.', 'css-timeclock-addon' ); ?></p>
				<?php else : ?>
					<strong><?php echo '' !== $hire ? esc_html( css_tc_addon()->time->format_day_label( $hire ) ) : esc_html__( 'Not set', 'css-timeclock-addon' ); ?></strong>
				<?php endif; ?>
			</td>
		</tr>
	<?php endif; ?>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Balance', 'css-timeclock-addon' ); ?></th>
		<td>
			<?php if ( '' === $balances['hire'] ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						$readonly
							? __( 'Your PTO starts once a manager sets your hire date.', 'css-timeclock-addon' )
							: __( 'Set a hire date to start PTO and sick time.', 'css-timeclock-addon' )
					);
					?>
				</p>
			<?php elseif ( empty( $balances['cycle'] ) ) : ?>
				<p class="description"><?php echo esc_html__( 'No leave year for this hire date yet.', 'css-timeclock-addon' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: leave year, 2: usable from date */
							__( 'Leave year %1$s. Usable from %2$s.', 'css-timeclock-addon' ),
							css_tc_leave_date_label( $balances['cycle']['start'] ) . ' – ' . css_tc_leave_date_label( $balances['cycle']['end'] ),
							css_tc_leave_date_label( '' !== $balances['usable_from'] ? $balances['usable_from'] : $balances['hire'] )
						)
					);
					?>
				</p>
				<?php if ( ! empty( $balances['banks'] ) ) : ?>
					<table class="widefat striped" style="max-width:640px">
						<thead><tr><th></th><th><?php echo esc_html__( 'Allowance', 'css-timeclock-addon' ); ?></th><th><?php echo esc_html__( 'Used', 'css-timeclock-addon' ); ?></th><th><?php echo esc_html__( 'Pending', 'css-timeclock-addon' ); ?></th><th><?php echo esc_html__( 'Left', 'css-timeclock-addon' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $balances['banks'] as $css_tc_bank => $css_tc_t ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( 'pto' === $css_tc_bank ? ( $leave->one_bank() ? __( 'PTO (incl. sick)', 'css-timeclock-addon' ) : __( 'PTO', 'css-timeclock-addon' ) ) : __( 'Sick', 'css-timeclock-addon' ) ); ?></th>
									<td><?php echo esc_html( $css_tc_fmt( $css_tc_t['allowance'] + $css_tc_t['adjust'] ) ); ?></td>
									<td><?php echo esc_html( Css_Tc_Leave::hours( $css_tc_t['used'] ) ); ?></td>
									<td><?php echo esc_html( Css_Tc_Leave::hours( $css_tc_t['pending'] ) ); ?></td>
									<td><strong><?php echo esc_html( $css_tc_fmt( $css_tc_t['left'] ) ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</td>
	</tr>
	<?php if ( ! $readonly ) : ?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Hours for this employee', 'css-timeclock-addon' ); ?></th>
			<td>
				<?php if ( $leave->type_enabled( 'pto' ) ) : ?>
					<p>
						<?php echo esc_html__( 'PTO per year', 'css-timeclock-addon' ); ?> <?php $css_tc_field( 'pto_year', $css_tc_ls['pto_hours_year'] ); ?>
						&nbsp; <?php echo esc_html__( 'first year', 'css-timeclock-addon' ); ?> <?php $css_tc_field( 'pto_first', $css_tc_ls['pto_first_year_hours'] ); ?>
					</p>
				<?php endif; ?>
				<?php if ( $leave->type_enabled( 'sick' ) && ! $leave->one_bank() ) : ?>
					<p>
						<?php echo esc_html__( 'Sick per year', 'css-timeclock-addon' ); ?> <?php $css_tc_field( 'sick_year', $css_tc_ls['sick_hours_year'] ); ?>
						&nbsp; <?php echo esc_html__( 'first year', 'css-timeclock-addon' ); ?> <?php $css_tc_field( 'sick_first', '' !== (string) $css_tc_ls['sick_first_year_hours'] ? $css_tc_ls['sick_first_year_hours'] : $css_tc_ls['sick_hours_year'] ); ?>
					</p>
				<?php endif; ?>
				<?php if ( ! $leave->any_enabled() ) : ?>
					<p class="description"><?php echo esc_html__( 'PTO and sick time are turned off. You can still set the hire date. Turn the banks on under TC-Config → PTO & sick.', 'css-timeclock-addon' ); ?></p>
				<?php else : ?>
					<p class="description"><?php echo esc_html__( 'Hours. Leave blank to use TC-Config → PTO & sick (shown in grey).', 'css-timeclock-addon' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php if ( ! empty( $balances['banks'] ) && ( '' !== (string) $balances['hire'] || $show_hire_input ) ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Adjust balance', 'css-timeclock-addon' ); ?></th>
				<td>
					<select name="css_tc_leave_adjust_bank" aria-label="<?php echo esc_attr__( 'Balance', 'css-timeclock-addon' ); ?>">
						<?php foreach ( array_keys( $balances['banks'] ) as $css_tc_bank ) : ?>
							<option value="<?php echo esc_attr( $css_tc_bank ); ?>"><?php echo esc_html( 'pto' === $css_tc_bank ? __( 'PTO', 'css-timeclock-addon' ) : __( 'Sick', 'css-timeclock-addon' ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="number" step="0.25" class="small-text" name="css_tc_leave_adjust" placeholder="+/-" aria-label="<?php echo esc_attr__( 'Hours to add or remove', 'css-timeclock-addon' ); ?>" />
					<?php echo esc_html__( 'hours, because', 'css-timeclock-addon' ); ?>
					<input type="text" class="regular-text" name="css_tc_leave_adjust_reason" maxlength="200" placeholder="<?php echo esc_attr__( 'Reason (required)', 'css-timeclock-addon' ); ?>" />
					<p class="description">
						<?php
						echo esc_html(
							$show_hire_input
								? __( 'Applies to the current leave year only. Saved with this form and logged.', 'css-timeclock-addon' )
								: __( 'Applies to the current leave year only. Saved with Update User and logged.', 'css-timeclock-addon' )
						);
						?>
					</p>
					<?php if ( ! empty( $adjustments ) ) : ?>
						<ul class="css-tc-leave-log">
							<?php foreach ( array_slice( $adjustments, 0, 10 ) as $css_tc_a ) : ?>
								<?php $css_tc_by = get_userdata( (int) $css_tc_a['by'] ); ?>
								<li>
									<?php
									echo esc_html(
										sprintf(
											'%s · %s %s%s · %s · %s',
											wp_date( get_option( 'date_format' ), (int) $css_tc_a['at'] ),
											'pto' === $css_tc_a['bank'] ? 'PTO' : __( 'Sick', 'css-timeclock-addon' ),
											$css_tc_a['seconds'] > 0 ? '+' : '−',
											Css_Tc_Leave::hours( abs( (int) $css_tc_a['seconds'] ) ),
											(string) $css_tc_a['reason'],
											$css_tc_by ? $css_tc_by->display_name : '?'
										)
									);
									?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</td>
			</tr>
		<?php endif; ?>
	<?php endif; ?>
</table>
<?php if ( ! $readonly && ! empty( $audit ) ) : ?>
	<h3><?php echo esc_html__( 'Change log', 'css-timeclock-addon' ); ?></h3>
	<ul class="css-tc-leave-log">
		<?php foreach ( array_slice( $audit, 0, 15 ) as $css_tc_row ) : ?>
			<?php $css_tc_by = get_userdata( (int) ( $css_tc_row['by'] ?? 0 ) ); ?>
			<li>
				<?php
				$css_tc_line = sprintf(
					'%s · %s · %s → %s · %s',
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $css_tc_row['at'] ?? 0 ) ),
					$css_tc_audit_label( (string) ( $css_tc_row['field'] ?? '' ) ),
					$css_tc_audit_value( (string) ( $css_tc_row['field'] ?? '' ), (string) ( $css_tc_row['before'] ?? '' ) ),
					$css_tc_audit_value( (string) ( $css_tc_row['field'] ?? '' ), (string) ( $css_tc_row['after'] ?? '' ) ),
					$css_tc_by ? $css_tc_by->display_name : '?'
				);
				if ( ! empty( $css_tc_row['reason'] ) ) {
					$css_tc_line .= ' · ' . (string) $css_tc_row['reason'];
				}
				echo esc_html( $css_tc_line );
				?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
