<?php
/**
 * Hire date and introductory period on the user profile (managers only).
 *
 * @package CssTimeclockAddon
 *
 * @var WP_User $user
 * @var string  $hire
 * @var int     $intro
 * @var string  $from
 * @var bool    $holidays
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2 id="css-tc-employment"><?php echo esc_html__( 'Time clock employment', 'css-timeclock-addon' ); ?></h2>
<input type="hidden" name="css_tc_employment_present" value="1" />
<?php wp_nonce_field( Css_Tc_Holidays::PROFILE_NONCE, 'css_tc_employment_nonce' ); ?>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label for="css_tc_hire_date"><?php echo esc_html__( 'Hire date', 'css-timeclock-addon' ); ?></label></th>
		<td>
			<input type="date" name="css_tc_hire_date" id="css_tc_hire_date" value="<?php echo esc_attr( $hire ); ?>" />
		</td>
	</tr>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Introductory period', 'css-timeclock-addon' ); ?></th>
		<td>
			<label>
				<input type="checkbox" name="css_tc_intro_on" value="1" <?php checked( $intro > 0 ); ?> />
				<?php echo esc_html__( 'Introductory period of', 'css-timeclock-addon' ); ?>
			</label>
			<select name="css_tc_intro_days" aria-label="<?php echo esc_attr__( 'Introductory period length', 'css-timeclock-addon' ); ?>">
				<?php foreach ( Css_Tc_Holidays::INTRO_CHOICES as $css_tc_days ) : ?>
					<option value="<?php echo esc_attr( (string) $css_tc_days ); ?>" <?php selected( $intro > 0 ? $intro : 90, $css_tc_days ); ?>><?php echo esc_html( sprintf( /* translators: %d: days */ __( '%d days', 'css-timeclock-addon' ), $css_tc_days ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php echo esc_html__( 'from the hire date', 'css-timeclock-addon' ); ?>
			<p class="description">
				<?php echo esc_html__( 'No holiday pay before the hire date or during the introductory period.', 'css-timeclock-addon' ); ?>
				<?php if ( '' !== $from ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: date */
							__( 'Holiday pay starts %s.', 'css-timeclock-addon' ),
							css_tc_addon()->time->format_day_label( $from )
						)
					);
					?>
				<?php else : ?>
					<?php echo esc_html__( 'With no hire date, holiday pay applies to every holiday.', 'css-timeclock-addon' ); ?>
				<?php endif; ?>
				<?php if ( ! $holidays ) : ?>
					<?php echo esc_html__( 'Holiday pay is turned off in TC-Config → Holidays.', 'css-timeclock-addon' ); ?>
				<?php endif; ?>
			</p>
		</td>
	</tr>
</table>
