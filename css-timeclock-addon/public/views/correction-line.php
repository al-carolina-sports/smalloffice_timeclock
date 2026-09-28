<?php
/**
 * One punch row on the period correction form.
 *
 * @package CssTimeclockAddon
 *
 * @var string              $key
 * @var array<string,mixed> $line
 * @var array<string,mixed> $day
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notes = array();
if ( ! empty( $line['is_stale'] ) ) {
	$notes[] = __( 'Missed clock-out', 'css-timeclock-addon' );
} elseif ( ! empty( $line['is_open'] ) ) {
	$notes[] = __( 'Still clocked in', 'css-timeclock-addon' );
}
if ( ! empty( $line['is_missing_in'] ) ) {
	$notes[] = __( 'No clock-in', 'css-timeclock-addon' );
}
if ( ! empty( $line['is_long'] ) ) {
	$notes[] = __( 'Long shift', 'css-timeclock-addon' );
}
if ( ! empty( $line['pending'] ) ) {
	$notes[] = __( 'Pending review', 'css-timeclock-addon' );
}
?>
<?php
$css_tc_locked  = ! empty( $day['is_future'] );
$css_tc_problem = css_tc_addon()->time->line_clock_problem(
	isset( $line['in_hms'] ) ? $line['in_hms'] : '',
	isset( $line['out_hms'] ) ? $line['out_hms'] : '',
	! empty( $line['out_next_day'] ),
	(int) $line['shift_id'] > 0
);
if ( $css_tc_locked ) {
	$css_tc_problem['message'] = '';
}
$css_tc_line_error  = $css_tc_problem['message'];
$css_tc_shift_hours = '' !== $css_tc_problem['short'] ? $css_tc_problem['short'] : css_tc_addon()->time->format_hours_hm( (int) $css_tc_problem['seconds'] );
?>
<div class="css-tc-correct__line<?php echo '' !== $css_tc_line_error ? ' is-invalid' : ''; ?>" data-line>
	<input type="hidden" name="lines[<?php echo esc_attr( $key ); ?>][work_date]" value="<?php echo esc_attr( $day['date'] ); ?>" <?php disabled( $css_tc_locked ); ?> />
	<input type="hidden" name="lines[<?php echo esc_attr( $key ); ?>][shift_id]" value="<?php echo esc_attr( (string) (int) $line['shift_id'] ); ?>" <?php disabled( $css_tc_locked ); ?> />
	<input type="hidden" name="lines[<?php echo esc_attr( $key ); ?>][correction_id]" value="<?php echo esc_attr( (string) (int) $line['correction_id'] ); ?>" <?php disabled( $css_tc_locked ); ?> />
	<?php if ( ! empty( $notes ) ) : ?>
		<p class="css-tc-correct__note"><?php echo esc_html( implode( ' · ', $notes ) ); ?></p>
	<?php endif; ?>
	<label>
		<span><?php echo esc_html__( 'Clock in', 'css-timeclock-addon' ); ?></span>
		<input type="time" step="1" name="lines[<?php echo esc_attr( $key ); ?>][proposed_in]" value="<?php echo esc_attr( (string) $line['in_hms'] ); ?>" <?php disabled( $css_tc_locked ); ?> />
	</label>
	<label>
		<span><?php echo esc_html__( 'Clock out', 'css-timeclock-addon' ); ?></span>
		<input type="time" step="1" name="lines[<?php echo esc_attr( $key ); ?>][proposed_out]" value="<?php echo esc_attr( (string) $line['out_hms'] ); ?>" <?php disabled( $css_tc_locked ); ?> />
	</label>
	<?php
	$css_tc_org_view = css_tc_addon()->organization;
	if ( $css_tc_org_view->enabled() ) :
		$css_tc_dept_now  = isset( $line['department_id'] ) ? (int) $line['department_id'] : 0;
		$css_tc_dept_opts = $css_tc_org_view->assigned( get_current_user_id() );
		if ( $css_tc_dept_now > 0 && ! in_array( $css_tc_dept_now, $css_tc_dept_opts, true ) ) {
			$css_tc_dept_opts[] = $css_tc_dept_now;
		}
		if ( $css_tc_dept_now < 1 ) {
			$css_tc_dept_now = $css_tc_org_view->home( get_current_user_id() );
		}
		?>
		<label class="css-tc-correct__dept">
			<span><?php echo esc_html__( 'Worked in', 'css-timeclock-addon' ); ?></span>
			<select name="lines[<?php echo esc_attr( $key ); ?>][department_id]" <?php disabled( $css_tc_locked ); ?>>
				<?php foreach ( $css_tc_dept_opts as $css_tc_opt ) : ?>
					<?php $css_tc_opt_row = $css_tc_org_view->department( $css_tc_opt ); ?>
					<?php if ( $css_tc_opt_row ) : ?>
						<option value="<?php echo esc_attr( (string) $css_tc_opt ); ?>" <?php selected( $css_tc_dept_now, $css_tc_opt ); ?>><?php echo esc_html( $css_tc_org_view->company_name( (int) $css_tc_opt_row['company_id'] ) . ' · ' . $css_tc_org_view->label( $css_tc_opt ) ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
		</label>
	<?php endif; ?>
	<div class="css-tc-correct__pair">
		<label class="css-tc-correct__check">
			<input type="checkbox" name="lines[<?php echo esc_attr( $key ); ?>][out_next_day]" value="1" <?php checked( ! empty( $line['out_next_day'] ) ); ?> <?php disabled( $css_tc_locked ); ?> />
			<span><?php echo esc_html__( 'Clock-out is the next day', 'css-timeclock-addon' ); ?></span>
		</label>
		<span class="css-tc-correct__shift"><?php echo esc_html__( 'Shift hours:', 'css-timeclock-addon' ); ?> <span data-shift-hours<?php echo '' !== $css_tc_problem['short'] ? ' class="is-problem"' : ''; ?>><?php echo esc_html( $css_tc_shift_hours ); ?></span></span>
	</div>
	<p class="css-tc-line-error" data-line-error role="alert" <?php echo '' === $css_tc_line_error ? 'hidden' : ''; ?>><?php echo esc_html( $css_tc_line_error ); ?></p>
	<label class="css-tc-correct__reason">
		<span><?php echo esc_html__( 'Reason (optional)', 'css-timeclock-addon' ); ?></span>
		<textarea name="lines[<?php echo esc_attr( $key ); ?>][reason]" rows="2" maxlength="500" <?php disabled( $css_tc_locked ); ?>><?php echo esc_textarea( (string) $line['reason'] ); ?></textarea>
	</label>
</div>
