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
<div class="css-tc-correct__line" data-line>
	<input type="hidden" name="lines[<?php echo esc_attr( $key ); ?>][work_date]" value="<?php echo esc_attr( $day['date'] ); ?>" />
	<input type="hidden" name="lines[<?php echo esc_attr( $key ); ?>][shift_id]" value="<?php echo esc_attr( (string) (int) $line['shift_id'] ); ?>" />
	<input type="hidden" name="lines[<?php echo esc_attr( $key ); ?>][correction_id]" value="<?php echo esc_attr( (string) (int) $line['correction_id'] ); ?>" />
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
	<label class="css-tc-correct__check">
		<input type="checkbox" name="lines[<?php echo esc_attr( $key ); ?>][out_next_day]" value="1" <?php checked( ! empty( $line['out_next_day'] ) ); ?> />
		<span><?php echo esc_html__( 'Clock-out is the next day', 'css-timeclock-addon' ); ?></span>
	</label>
	<label class="css-tc-correct__reason">
		<span><?php echo esc_html__( 'Reason', 'css-timeclock-addon' ); ?></span>
		<textarea name="lines[<?php echo esc_attr( $key ); ?>][reason]" rows="2" maxlength="500"><?php echo esc_textarea( (string) $line['reason'] ); ?></textarea>
	</label>
</div>
