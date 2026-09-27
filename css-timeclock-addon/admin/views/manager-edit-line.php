<?php
/**
 * One punch row in the manager day editor.
 *
 * @package CssTimeclockAddon
 *
 * @var string              $key
 * @var array<string,mixed> $line
 * @var string              $edit_day
 * @var int                 $css_tc_shift_seconds
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
$css_tc_problem = css_tc_addon()->time->line_clock_problem(
	isset( $line['in_hms'] ) ? $line['in_hms'] : '',
	isset( $line['out_hms'] ) ? $line['out_hms'] : '',
	! empty( $line['out_next_day'] ),
	(int) $line['id'] > 0
);
$css_tc_line_error  = $css_tc_problem['message'];
$css_tc_shift_hours = '' !== $css_tc_problem['short'] ? $css_tc_problem['short'] : css_tc_addon()->time->format_hours_hm( (int) $css_tc_problem['seconds'] );
?>
<div class="css-tc-correct__line<?php echo '' !== $css_tc_line_error ? ' is-invalid' : ''; ?>" data-line>
	<input type="hidden" name="lines[<?php echo esc_attr( $key ); ?>][shift_id]" value="<?php echo esc_attr( (string) (int) $line['id'] ); ?>" />
	<input type="hidden" name="lines[<?php echo esc_attr( $key ); ?>][delete]" value="<?php echo esc_attr( ! empty( $line['delete'] ) ? '1' : '' ); ?>" />
	<?php if ( ! empty( $notes ) ) : ?>
		<p class="css-tc-correct__note"><?php echo esc_html( implode( ' · ', $notes ) ); ?></p>
	<?php endif; ?>
	<label>
		<span><?php echo esc_html__( 'Clock in', 'css-timeclock-addon' ); ?></span>
		<input type="time" step="1" name="lines[<?php echo esc_attr( $key ); ?>][proposed_in]" value="<?php echo esc_attr( (string) $line['in_hms'] ); ?>" />
	</label>
	<label>
		<span><?php echo esc_html__( 'Clock out', 'css-timeclock-addon' ); ?></span>
		<input type="time" step="1" name="lines[<?php echo esc_attr( $key ); ?>][proposed_out]" value="<?php echo esc_attr( (string) $line['out_hms'] ); ?>" />
	</label>
	<div class="css-tc-correct__pair">
		<label class="css-tc-correct__check">
			<input type="checkbox" name="lines[<?php echo esc_attr( $key ); ?>][out_next_day]" value="1" <?php checked( ! empty( $line['out_next_day'] ) ); ?> />
			<span><?php echo esc_html__( 'Clock-out is the next day', 'css-timeclock-addon' ); ?></span>
		</label>
		<span class="css-tc-correct__shift"><?php echo esc_html__( 'Shift hours:', 'css-timeclock-addon' ); ?> <span data-shift-hours<?php echo '' !== $css_tc_problem['short'] ? ' class="is-problem"' : ''; ?>><?php echo esc_html( $css_tc_shift_hours ); ?></span></span>
		<?php if ( (int) $line['id'] > 0 ) : ?>
			<button type="button" class="css-tc-manager-delete" data-delete-shift data-confirm="<?php echo esc_attr__( 'Delete this shift? It is removed now and recorded under Corrections.', 'css-timeclock-addon' ); ?>"><?php echo esc_html__( 'Delete shift', 'css-timeclock-addon' ); ?></button>
		<?php else : ?>
			<button type="button" class="css-tc-manager-delete" data-delete-shift><?php echo esc_html__( 'Remove', 'css-timeclock-addon' ); ?></button>
		<?php endif; ?>
	</div>
	<p class="css-tc-line-error" data-line-error role="alert" <?php echo '' === $css_tc_line_error ? 'hidden' : ''; ?>><?php echo esc_html( $css_tc_line_error ); ?></p>
</div>
