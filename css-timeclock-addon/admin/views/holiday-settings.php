<?php
/**
 * TC-Config → Holidays.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed> $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_hnotice = Css_Tc_Leave_Ui::take_notice();
?>
<?php if ( $css_tc_hnotice ) : ?>
	<div class="notice <?php echo $css_tc_hnotice['error'] ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $css_tc_hnotice['message'] ); ?></p></div>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="css-tc-holiday-settings">
	<input type="hidden" name="action" value="css_tc_holiday_settings" />
	<?php wp_nonce_field( 'css_tc_holiday_settings' ); ?>
	<table class="form-table" role="presentation">
				<?php
				$css_tc_observed = isset( $settings['holidays_observed'] ) ? (array) $settings['holidays_observed'] : Css_Tc_Holidays::default_observed();
				$css_tc_year     = (int) wp_date( 'Y' );
				$css_tc_preview  = ( new Css_Tc_Holidays( array_merge( $settings, array( 'holidays_enabled' => 1 ) ) ) )->between( $css_tc_year . '-01-01', $css_tc_year . '-12-31' );
				?>
				<tr id="holidays">
					<th scope="row"><?php echo esc_html__( 'Paid holidays', 'css-timeclock-addon' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="holidays_enabled" value="1" <?php checked( ! empty( $settings['holidays_enabled'] ) ); ?> />
							<?php echo esc_html__( 'Pay holidays', 'css-timeclock-addon' ); ?>
						</label>
						<p>
							<label for="holiday_hours"><?php echo esc_html__( 'Hours paid per holiday', 'css-timeclock-addon' ); ?></label>
							<input name="holiday_hours" id="holiday_hours" type="number" min="0" max="24" step="0.25" value="<?php echo esc_attr( (string) ( isset( $settings['holiday_hours'] ) ? $settings['holiday_hours'] : 8 ) ); ?>" class="small-text" />
						</p>
						<fieldset class="css-tc-holiday-list">
							<legend><?php echo esc_html__( 'Observed holidays', 'css-timeclock-addon' ); ?></legend>
							<?php foreach ( Css_Tc_Holidays::catalog() as $css_tc_key => $css_tc_def ) : ?>
								<label class="css-tc-holiday">
									<input type="checkbox" name="holidays_observed[]" value="<?php echo esc_attr( $css_tc_key ); ?>" <?php checked( in_array( $css_tc_key, $css_tc_observed, true ) ); ?> />
									<span>
										<?php echo esc_html( $css_tc_def['label'] ); ?>
										<span class="css-tc-holiday__when"><?php echo esc_html( $css_tc_def['when'] ); ?></span>
									</span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p>
							<label for="holidays_custom"><?php echo esc_html__( 'Other holidays', 'css-timeclock-addon' ); ?></label><br />
							<textarea name="holidays_custom" id="holidays_custom" rows="3" class="large-text code" placeholder="<?php echo esc_attr__( "12-24 Christmas Eve\n2026-10-12 Office closed", 'css-timeclock-addon' ); ?>"><?php echo esc_textarea( (string) ( $settings['holidays_custom'] ?? '' ) ); ?></textarea>
							<span class="description"><?php echo esc_html__( 'One per line: MM-DD Name for every year, or YYYY-MM-DD Name for one date.', 'css-timeclock-addon' ); ?></span>
						</p>
						<?php $css_tc_custom_rows = Css_Tc_Holidays::parse_custom( (string) ( $settings['holidays_custom'] ?? '' ) )['rows']; ?>
						<?php if ( ! empty( $css_tc_custom_rows ) ) : ?>
							<ul class="css-tc-holiday-custom">
								<?php foreach ( $css_tc_custom_rows as $css_tc_row ) : ?>
									<li><strong><?php echo esc_html( $css_tc_row['name'] ); ?></strong> — <?php echo esc_html( Css_Tc_Holidays::describe_custom( $css_tc_row ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<label>
							<input type="checkbox" name="holiday_weekend_shift" value="1" <?php checked( ! isset( $settings['holiday_weekend_shift'] ) || ! empty( $settings['holiday_weekend_shift'] ) ); ?> />
							<?php echo esc_html__( 'When a holiday falls on a weekend, pay it on Friday (Saturday holidays) or Monday (Sunday holidays)', 'css-timeclock-addon' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'Christmas Eve and New Year\'s Eve always move back to Friday so they do not land on the holiday after them. One-off dates are paid on the date typed.', 'css-timeclock-addon' ); ?></p>
						<p class="description"><?php echo esc_html__( 'Each eligible employee gets these hours as the Holiday pay code on that day, whether or not they work. Holiday hours do not count toward overtime. Set each employee\'s hire date and introductory period on their user profile; no holiday pay is due before the hire date or during the introductory period.', 'css-timeclock-addon' ); ?></p>
						<?php if ( ! empty( $css_tc_preview ) ) : ?>
							<details class="css-tc-holiday-preview">
								<summary><?php echo esc_html( sprintf( /* translators: %d: year */ __( 'Paid dates in %d', 'css-timeclock-addon' ), $css_tc_year ) ); ?></summary>
								<ul>
									<?php
									$css_tc_rules = array();
									foreach ( Css_Tc_Holidays::catalog() as $css_tc_def ) {
										$css_tc_rules[ $css_tc_def['label'] ] = $css_tc_def;
									}
									foreach ( $css_tc_custom_rows as $css_tc_row ) {
										$css_tc_rules[ $css_tc_row['name'] ] = array( 'when' => Css_Tc_Holidays::describe_custom( $css_tc_row ), 'fixed' => '' !== $css_tc_row['md'], 'rule' => $css_tc_row['md'] );
									}
									?>
									<?php foreach ( $css_tc_preview as $css_tc_date => $css_tc_names ) : ?>
										<?php
										$css_tc_bits = array();
										foreach ( $css_tc_names as $css_tc_n ) {
											$css_tc_r    = $css_tc_rules[ $css_tc_n ] ?? null;
											$css_tc_text = $css_tc_n;
											if ( $css_tc_r ) {
												$css_tc_text .= ' (' . $css_tc_r['when'];
												if ( ! empty( $css_tc_r['fixed'] ) && preg_match( '/^\d{2}-\d{2}$/', (string) $css_tc_r['rule'] ) && substr( $css_tc_date, 5 ) !== $css_tc_r['rule'] ) {
													$css_tc_text .= '; ' . __( 'falls on a weekend, paid this day', 'css-timeclock-addon' );
												}
												$css_tc_text .= ')';
											}
											$css_tc_bits[] = $css_tc_text;
										}
										?>
										<li><strong><?php echo esc_html( css_tc_addon()->time->format_day_label( $css_tc_date ) ); ?></strong> — <?php echo esc_html( implode( ', ', $css_tc_bits ) ); ?></li>
									<?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>
					</td>
				</tr>
	</table>
	<p class="submit"><button type="submit" class="button button-primary"><?php echo esc_html__( 'Save holidays', 'css-timeclock-addon' ); ?></button></p>
</form>
