<?php
/**
 * Manager editor for one timecard day. Saves immediately.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed>|null $edit_row
 * @var string                   $edit_day
 * @var bool                     $is_open
 * @var array<string,mixed>      $period
 * @var int                      $user_id
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$edit_day = isset( $edit_day ) ? (string) $edit_day : '';
$edit_row = isset( $edit_row ) && is_array( $edit_row ) ? $edit_row : null;
$close    = Css_Tc_Admin::timecards_url(
	array(
		'employee' => (int) $user_id,
		'period'   => (string) $period['start'],
	)
);
$time     = css_tc_addon()->time;
?>
<div class="css-tc-correct css-tc-manager-edit css-tc-no-print" id="css-tc-day-edit">
	<p class="css-tc-correct__back">
		<a href="<?php echo esc_url( $close ); ?>">&larr; <?php echo esc_html__( 'Back to timecard', 'css-timeclock-addon' ); ?></a>
	</p>
	<?php if ( ! $edit_row ) : ?>
		<p class="css-tc-sheet__error"><?php echo esc_html__( 'That day is not in this pay period.', 'css-timeclock-addon' ); ?></p>
	<?php else : ?>
		<?php
		$css_tc_edit_lines = $edit_row['shifts'];
		if ( empty( $css_tc_edit_lines ) ) {
			$css_tc_edit_lines = array(
				array(
					'id'            => 0,
					'in_hms'        => '',
					'out_hms'       => '',
					'out_next_day'  => false,
					'is_open'       => false,
					'is_stale'      => false,
					'is_missing_in' => false,
					'is_long'       => false,
				),
			);
		}
		$css_tc_day_seconds = 0;
		foreach ( $css_tc_edit_lines as $css_tc_sum_line ) {
			$css_tc_span = $time->hms_span_seconds(
				isset( $css_tc_sum_line['in_hms'] ) ? $css_tc_sum_line['in_hms'] : '',
				isset( $css_tc_sum_line['out_hms'] ) ? $css_tc_sum_line['out_hms'] : '',
				! empty( $css_tc_sum_line['out_next_day'] )
			);
			if ( $css_tc_span >= 0 ) {
				$css_tc_day_seconds += $css_tc_span;
			}
		}
		?>
		<?php if ( empty( $edit_row['shifts'] ) ) : ?>
			<p class="css-tc-banner css-tc-banner--long" role="status">
				<?php echo esc_html__( 'This day has no punches. Add a clock-in and clock-out, then save. The shift is recorded as Edited by manager.', 'css-timeclock-addon' ); ?>
			</p>
		<?php endif; ?>
		<?php if ( empty( $is_open ) ) : ?>
			<p class="css-tc-banner css-tc-banner--closed" role="status">
				<?php echo esc_html__( 'This pay period is closed. Saving still updates these punches and records the edit.', 'css-timeclock-addon' ); ?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $edit_row['pending'] ) ) : ?>
			<p class="css-tc-banner css-tc-banner--long" role="status">
				<?php echo esc_html__( 'This day has a pending employee request. Saving does not approve that request. The times below are the stored punches.', 'css-timeclock-addon' ); ?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-manager-edit>
			<?php wp_nonce_field( Css_Tc_Corrections::MANAGER_NONCE ); ?>
			<input type="hidden" name="action" value="css_tc_manager_edit_day" />
			<input type="hidden" name="employee" value="<?php echo esc_attr( (string) (int) $user_id ); ?>" />
			<input type="hidden" name="work_date" value="<?php echo esc_attr( $edit_day ); ?>" />
			<input type="hidden" name="period" value="<?php echo esc_attr( (string) $period['start'] ); ?>" />
			<fieldset class="css-tc-correct__day" data-day>
				<legend class="css-tc-correct__legend">
					<span>
						<?php
						if ( empty( $edit_row['shifts'] ) ) {
							/* translators: 1: weekday, 2: date label */
							$css_tc_edit_format = __( 'Add a shift for %1$s, %2$s', 'css-timeclock-addon' );
						} else {
							/* translators: 1: weekday, 2: date label */
							$css_tc_edit_format = __( 'Edit %1$s, %2$s', 'css-timeclock-addon' );
						}
						echo esc_html(
							sprintf(
								$css_tc_edit_format,
								isset( $edit_row['weekday'] ) ? $edit_row['weekday'] : '',
								isset( $edit_row['date_label'] ) ? $edit_row['date_label'] : $edit_day
							)
						);
						?>
					</span>
					<strong class="css-tc-correct__total">
						<?php echo esc_html__( 'Total hours:', 'css-timeclock-addon' ); ?>
						<span data-day-total><?php echo esc_html( $time->format_hours_hm( $css_tc_day_seconds ) ); ?></span>
					</strong>
				</legend>
				<div data-lines>
					<?php foreach ( $css_tc_edit_lines as $index => $line ) : ?>
						<?php
						$key = $edit_day . '-' . $index;
						$css_tc_shift_seconds = $time->hms_span_seconds(
							isset( $line['in_hms'] ) ? $line['in_hms'] : '',
							isset( $line['out_hms'] ) ? $line['out_hms'] : '',
							! empty( $line['out_next_day'] )
						);
						include CSS_TC_ADDON_DIR . 'admin/views/manager-edit-line.php';
						?>
					<?php endforeach; ?>
				</div>
				<template>
					<?php
					$index = '__INDEX__';
					$key   = $edit_day . '-__INDEX__';
					$line  = array(
						'id'            => 0,
						'in_hms'        => '',
						'out_hms'       => '',
						'out_next_day'  => false,
						'is_open'       => false,
						'is_stale'      => false,
						'is_missing_in' => false,
						'is_long'       => false,
					);
					$css_tc_shift_seconds = -1;
					include CSS_TC_ADDON_DIR . 'admin/views/manager-edit-line.php';
					?>
				</template>
				<button type="button" class="css-tc-correct__add" data-add-punch><?php echo esc_html__( 'Add punch', 'css-timeclock-addon' ); ?></button>
			</fieldset>
			<label class="css-tc-correct__reason css-tc-manager-note">
				<span><?php echo esc_html__( 'Note (optional)', 'css-timeclock-addon' ); ?></span>
				<textarea name="manager_note" rows="2" maxlength="500"></textarea>
			</label>
			<p class="css-tc-correct__submit">
				<button type="submit" class="css-tc-print"><?php echo esc_html__( 'Save day', 'css-timeclock-addon' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
