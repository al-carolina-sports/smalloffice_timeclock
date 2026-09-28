<?php
/**
 * TC-Config → PTO & sick.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed> $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_ls     = array_merge( Css_Tc_Leave::default_settings(), $settings );
$css_tc_notice = Css_Tc_Leave_Ui::take_notice();
$css_tc_val    = static function ( $key ) use ( $css_tc_ls ) {
	return esc_attr( (string) $css_tc_ls[ $key ] );
};
?>
<?php if ( $css_tc_notice ) : ?>
	<div class="notice <?php echo $css_tc_notice['error'] ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $css_tc_notice['message'] ); ?></p></div>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="css-tc-leave-settings">
	<input type="hidden" name="action" value="css_tc_leave_settings" />
	<?php wp_nonce_field( Css_Tc_Leave_Ui::ADMIN_NONCE ); ?>
	<p class="description" style="max-width:760px">
		<?php echo esc_html__( 'Each employee\'s leave year runs from their hire date to the day before the next anniversary. The whole year\'s hours are available at the start of each year, and unused hours are lost at the anniversary (no carryover). Time off can be used once the introductory period on the employee\'s profile is over. Managers can change the amounts for one employee on their profile.', 'css-timeclock-addon' ); ?>
	</p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php echo esc_html__( 'PTO', 'css-timeclock-addon' ); ?></th>
			<td>
				<label><input type="checkbox" name="pto_enabled" value="1" <?php checked( ! empty( $css_tc_ls['pto_enabled'] ) ); ?> /> <?php echo esc_html__( 'Track paid time off', 'css-timeclock-addon' ); ?></label>
				<p>
					<label for="pto_hours_year"><?php echo esc_html__( 'Hours per year', 'css-timeclock-addon' ); ?></label>
					<input type="number" min="0" max="2000" step="0.25" class="small-text" name="pto_hours_year" id="pto_hours_year" value="<?php echo $css_tc_val( 'pto_hours_year' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" />
					&nbsp;
					<label for="pto_first_year_hours"><?php echo esc_html__( 'First year', 'css-timeclock-addon' ); ?></label>
					<input type="number" min="0" max="2000" step="0.25" class="small-text" name="pto_first_year_hours" id="pto_first_year_hours" value="<?php echo $css_tc_val( 'pto_first_year_hours' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" />
					<?php echo esc_html__( 'hours', 'css-timeclock-addon' ); ?>
				</p>
				<p class="description"><?php echo esc_html__( 'First year: hours available from the end of the introductory period until the first anniversary. The yearly amount starts at the first anniversary.', 'css-timeclock-addon' ); ?></p>
				<p>
					<label for="pto_notice_days"><?php echo esc_html__( 'Employees must request PTO at least', 'css-timeclock-addon' ); ?></label>
					<input type="number" min="0" max="365" step="1" class="small-text" name="pto_notice_days" id="pto_notice_days" value="<?php echo $css_tc_val( 'pto_notice_days' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" />
					<?php echo esc_html__( 'days in advance', 'css-timeclock-addon' ); ?>
				</p>
				<p class="description"><?php echo esc_html__( 'Sick time has no notice rule. Managers adding time off for an employee are not held to it.', 'css-timeclock-addon' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Sick time', 'css-timeclock-addon' ); ?></th>
			<td>
				<label><input type="checkbox" name="sick_enabled" value="1" <?php checked( ! empty( $css_tc_ls['sick_enabled'] ) ); ?> /> <?php echo esc_html__( 'Track sick time', 'css-timeclock-addon' ); ?></label>
				<p>
					<label><input type="checkbox" name="sick_from_pto" value="1" <?php checked( ! empty( $css_tc_ls['sick_from_pto'] ) ); ?> /> <?php echo esc_html__( 'Sick time comes out of PTO (one bank)', 'css-timeclock-addon' ); ?></label>
				</p>
				<p class="description"><?php echo esc_html__( 'With one bank, sick days are taken from the PTO balance and the sick hours below are not used. They still show as Sick on timecards and reports.', 'css-timeclock-addon' ); ?></p>
				<p>
					<label for="sick_hours_year"><?php echo esc_html__( 'Hours per year', 'css-timeclock-addon' ); ?></label>
					<input type="number" min="0" max="2000" step="0.25" class="small-text" name="sick_hours_year" id="sick_hours_year" value="<?php echo $css_tc_val( 'sick_hours_year' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" />
					&nbsp;
					<label for="sick_first_year_hours"><?php echo esc_html__( 'First year', 'css-timeclock-addon' ); ?></label>
					<input type="number" min="0" max="2000" step="0.25" class="small-text" name="sick_first_year_hours" id="sick_first_year_hours" value="<?php echo $css_tc_val( 'sick_first_year_hours' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="<?php echo esc_attr__( 'same', 'css-timeclock-addon' ); ?>" />
					<?php echo esc_html__( 'hours', 'css-timeclock-addon' ); ?>
				</p>
				<p class="description"><?php echo esc_html__( 'Leave First year blank to give the full yearly amount in the first year. Enter 0 for no sick time until the first anniversary.', 'css-timeclock-addon' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="leave_day_hours"><?php echo esc_html__( 'Length of a day off', 'css-timeclock-addon' ); ?></label></th>
			<td>
				<input type="number" min="1" max="24" step="0.25" class="small-text" name="leave_day_hours" id="leave_day_hours" value="<?php echo $css_tc_val( 'leave_day_hours' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" />
				<?php echo esc_html__( 'hours. A full day off uses this many hours; balances also show in days of this length.', 'css-timeclock-addon' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="leave_increment"><?php echo esc_html__( 'Smallest amount', 'css-timeclock-addon' ); ?></label></th>
			<td>
				<select name="leave_increment" id="leave_increment">
					<?php foreach ( array( 15 => __( '15 minutes', 'css-timeclock-addon' ), 30 => __( '30 minutes', 'css-timeclock-addon' ), 60 => __( '1 hour', 'css-timeclock-addon' ) ) as $css_tc_m => $css_tc_l ) : ?>
						<option value="<?php echo esc_attr( (string) $css_tc_m ); ?>" <?php selected( (int) $css_tc_ls['leave_increment'], $css_tc_m ); ?>><?php echo esc_html( $css_tc_l ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo esc_html__( 'Partial days are requested in steps of this size.', 'css-timeclock-addon' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Balance', 'css-timeclock-addon' ); ?></th>
			<td>
				<label><input type="checkbox" name="leave_allow_negative" value="1" <?php checked( ! empty( $css_tc_ls['leave_allow_negative'] ) ); ?> /> <?php echo esc_html__( 'Allow a negative balance', 'css-timeclock-addon' ); ?></label>
				<p class="description"><?php echo esc_html__( 'Off: requests for more hours than are left (counting pending requests) are refused.', 'css-timeclock-addon' ); ?></p>
			</td>
		</tr>
	</table>
	<p class="submit"><button type="submit" class="button button-primary"><?php echo esc_html__( 'Save PTO & sick settings', 'css-timeclock-addon' ); ?></button>
		<a class="button" href="<?php echo esc_url( Css_Tc_Leave_Ui::page_url() ); ?>"><?php echo esc_html__( 'Open Time off', 'css-timeclock-addon' ); ?></a></p>
</form>
