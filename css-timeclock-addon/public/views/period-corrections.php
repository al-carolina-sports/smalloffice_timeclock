<?php
/**
 * Edit every day in the current pay period.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed>|null $form
 * @var string                   $form_error
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$form_error = isset( $form_error ) ? (string) $form_error : '';
?>
<div class="css-tc-correct">
	<p class="css-tc-correct__back css-tc-no-print">
		<a href="<?php echo esc_url( Css_Tc_Shortcodes::times_url() ); ?>">&larr; <?php echo esc_html__( 'Back to timecard', 'css-timeclock-addon' ); ?></a>
	</p>
	<h1><?php echo esc_html__( 'Correct this pay period', 'css-timeclock-addon' ); ?></h1>
	<?php if ( ! $form ) : ?>
		<p><?php echo esc_html__( 'The current pay period could not be loaded.', 'css-timeclock-addon' ); ?></p>
	<?php else : ?>
		<p class="css-tc-correct__lead">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: employee name, 2: pay period label */
					__( '%1$s — %2$s. Change any day or add a missing punch. A reason is optional. Past pay periods cannot be changed.', 'css-timeclock-addon' ),
					$form['name'],
					$form['period']['label']
				)
			);
			?>
		</p>
		<?php if ( '' !== $form_error ) : ?>
			<p class="css-tc-sheet__error"><?php echo esc_html( $form_error ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="css-tc-correct__form">
			<?php wp_nonce_field( Css_Tc_Corrections::EMPLOYEE_NONCE ); ?>
			<input type="hidden" name="action" value="css_tc_submit_period" />
			<?php foreach ( $form['days'] as $day ) : ?>
				<?php
				$css_tc_day_seconds = 0;
				foreach ( $day['lines'] as $css_tc_sum_line ) {
					$css_tc_span = css_tc_addon()->time->hms_span_seconds(
						isset( $css_tc_sum_line['in_hms'] ) ? $css_tc_sum_line['in_hms'] : '',
						isset( $css_tc_sum_line['out_hms'] ) ? $css_tc_sum_line['out_hms'] : '',
						! empty( $css_tc_sum_line['out_next_day'] )
					);
					if ( $css_tc_span >= 0 ) {
						$css_tc_day_seconds += $css_tc_span;
					}
				}
				$css_tc_day_hours = css_tc_addon()->time->format_hours_hm( $css_tc_day_seconds );
				?>
				<fieldset class="css-tc-correct__day" id="day-<?php echo esc_attr( $day['date'] ); ?>" data-day>
					<legend class="css-tc-correct__legend">
						<span><?php echo esc_html( $day['weekday'] . ', ' . $day['date_label'] ); ?></span>
						<strong class="css-tc-correct__total"><?php echo esc_html__( 'Total hours:', 'css-timeclock-addon' ); ?> <span data-day-total><?php echo esc_html( $css_tc_day_hours ); ?></span></strong>
					</legend>
					<div data-lines>
						<?php foreach ( $day['lines'] as $index => $line ) : ?>
							<?php
							$key = $day['date'] . '-' . $index;
							include CSS_TC_ADDON_DIR . 'public/views/correction-line.php';
							?>
						<?php endforeach; ?>
					</div>
					<template>
						<?php
						$index = '__INDEX__';
						$key   = $day['date'] . '-__INDEX__';
						$line  = array(
							'shift_id'      => 0,
							'correction_id' => 0,
							'in_hms'        => '',
							'out_hms'       => '',
							'out_next_day'  => false,
							'reason'        => '',
							'is_open'       => false,
							'is_stale'      => false,
							'is_missing_in' => false,
							'is_long'       => false,
							'pending'       => false,
						);
						include CSS_TC_ADDON_DIR . 'public/views/correction-line.php';
						?>
					</template>
					<button type="button" class="css-tc-correct__add" data-add-punch><?php echo esc_html__( 'Add punch', 'css-timeclock-addon' ); ?></button>
				</fieldset>
			<?php endforeach; ?>
			<p class="css-tc-correct__submit">
				<button type="submit" class="css-tc-print"><?php echo esc_html__( 'Submit corrections', 'css-timeclock-addon' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
